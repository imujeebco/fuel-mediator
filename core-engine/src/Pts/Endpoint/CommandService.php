<?php

declare(strict_types=1);

namespace App\Pts\Endpoint;

use App\Pts\ControllerEndpoint;
use App\Pts\ControllerLock;
use App\Pts\PacketPolicy;
use App\Pts\PtsClient;
use App\Pts\PtsException;
use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use App\Shared\Http\JsonResponder;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function hash;
use function in_array;
use function intdiv;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;
use function substr;
use function vsprintf;

/**
 * Executes a physical command from a device (docs/transaction-lifecycle.md §1):
 * journal (RECEIVED) → QUEUED → controller lock → SENT once → CONFIRMED | FAILED | OUTCOME_UNKNOWN.
 *
 * Hard rules: never retried; a timeout is OUTCOME_UNKNOWN (never "failed"); a command that could not obtain the
 * controller in time is FAILED and NOT sent; an identical authorize from the same client within
 * {@see IDEMPOTENCY_WINDOW_SECONDS} returns the journaled result instead of a second command. Stops are never
 * de-duplicated and wait longer for the controller than anything else.
 */
final class CommandService
{
    public const IDEMPOTENCY_WINDOW_SECONDS = 10;
    private const STOP_TYPES = ['PumpStop', 'PumpEmergencyStop'];
    private const STOP_LOCK_WAIT_SECONDS = 8.0;
    private const COMMAND_LOCK_WAIT_SECONDS = 3.0;
    /** Device read timeout is 15 s; lock wait (≤ 3 s) + this must stay below it. */
    private const SEND_TIMEOUT_MS = 9000;
    private const STOP_SEND_TIMEOUT_MS = 6000;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PtsClient $pts,
        private readonly ControllerLock $lock,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array{Id: int, Type: string, Data?: array<string, mixed>} $packet
     *
     * @return array<string, mixed> Reply packet for the device (carrying the device's packet Id).
     */
    public function execute(ControllerEndpoint $controller, array $packet, string $clientKey, string $correlationId): array
    {
        $type = $packet['Type'];
        $id = $packet['Id'];
        $request = ['Type' => $type] + (isset($packet['Data']) ? ['Data' => $packet['Data']] : []);
        $isStop = in_array($type, self::STOP_TYPES, true);
        $pump = self::dataInt($packet, 'Pump');
        $nozzle = self::dataInt($packet, 'Nozzle');

        $idempotencyKey = $isStop ? null : hash('sha256', sprintf(
            '%d|%s|%s|%d',
            $controller->id,
            $clientKey,
            json_encode($request, JsonResponder::FLAGS),
            intdiv($this->clock->now()->getTimestamp(), self::IDEMPOTENCY_WINDOW_SECONDS),
        ));
        $publicId = self::uuid();

        try {
            $this->db->createCommand()->insert('commands', [
                'public_id' => $publicId,
                'station_id' => $controller->stationId,
                'controller_id' => $controller->id,
                'correlation_id' => $correlationId,
                'type' => $type,
                'pump_number' => $pump,
                'nozzle_number' => $nozzle,
                'request' => json_encode($request, JsonResponder::FLAGS),
                'idempotency_key' => $idempotencyKey,
                'state' => 'received',
                'received_at' => $this->clock->nowForDb(),
            ])->execute();
        } catch (IntegrityException) {
            return $this->replayDuplicate($idempotencyKey, $id, $type);
        }
        $this->audit->record('command', $publicId, 'received', $clientKey, $controller->stationId, null, 'received', $request, $correlationId);
        $this->transition($publicId, 'received', 'queued', $controller->stationId, $correlationId);

        $lockWait = $isStop ? self::STOP_LOCK_WAIT_SECONDS : self::COMMAND_LOCK_WAIT_SECONDS;
        if (!$this->lock->acquire($controller->id, $lockWait)) {
            $message = "Controller busy: {$type} was NOT sent (ref {$publicId})";
            $this->complete($publicId, 'queued', 'failed', $controller->stationId, $correlationId, null, $message);
            return Packets::error($id, $type, $message);
        }

        try {
            $this->transition($publicId, 'queued', 'sent', $controller->stationId, $correlationId, ['sent_at' => $this->clock->nowForDb()]);
            $timed = new ControllerEndpoint(
                $controller->id,
                $controller->stationId,
                $controller->name,
                $controller->url,
                $controller->authMode,
                $controller->username,
                $controller->password,
                $controller->verifySsl,
                $isStop ? self::STOP_SEND_TIMEOUT_MS : self::SEND_TIMEOUT_MS,
                $controller->pollIntervalMs,
                $controller->utcOffsetMinutes,
            );

            try {
                $reply = $this->pts->send($timed, [$request], PacketPolicy::DEVICE)[0] ?? null;
            } catch (PtsException $e) {
                return $this->failure($publicId, $controller, $correlationId, $id, $type, $e);
            }
        } finally {
            $this->lock->release($controller->id);
        }

        if ($reply === null) {
            $message = "Outcome unknown: controller sent no reply for {$type}. Check the pump before retrying (ref {$publicId})";
            $this->complete($publicId, 'sent', 'outcome_unknown', $controller->stationId, $correlationId, null, $message);
            return Packets::error($id, $type, $message);
        }

        // Same success rules as FuelPay: an error packet fails; control replies must carry no Message or "OK".
        $isError = Packets::isError($reply)
            || ($type !== 'PumpAuthorize' && isset($reply['Message']) && $reply['Message'] !== 'OK');
        $txNo = null;
        if ($type === 'PumpAuthorize' && is_array($reply['Data'] ?? null)) {
            $tx = $reply['Data']['Transaction'] ?? null;
            $txNo = is_int($tx) ? $tx : null;
        }
        $this->complete(
            $publicId,
            'sent',
            $isError ? 'failed' : 'confirmed',
            $controller->stationId,
            $correlationId,
            $reply,
            $isError ? substr(json_encode($reply['Message'] ?? $reply['Error'] ?? 'error', JsonResponder::FLAGS), 0, 500) : null,
            $txNo,
        );

        return Packets::withId($reply, $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $publicId, ControllerEndpoint $controller, string $correlationId, int $id, string $type, PtsException $e): array
    {
        // Rejected by the controller (HTTP status) or provably never sent: not executed.
        $notExecuted = in_array($e->kind, [PtsException::AUTH, PtsException::HTTP, PtsException::POLICY, PtsException::CONFIGURATION], true)
            || !$e->requestMayHaveBeenSent;

        if ($notExecuted) {
            $message = "{$type} was NOT executed: {$e->getMessage()} (ref {$publicId})";
            $this->complete($publicId, 'sent', 'failed', $controller->stationId, $correlationId, null, $message);
        } else {
            $message = "Outcome unknown: no reliable reply for {$type}. Check the pump before retrying (ref {$publicId})";
            $this->complete($publicId, 'sent', 'outcome_unknown', $controller->stationId, $correlationId, null, '[' . $e->kind . '] ' . $e->getMessage());
        }

        return Packets::error($id, $type, $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function replayDuplicate(?string $idempotencyKey, int $id, string $type): array
    {
        /** @var array{public_id: string, state: string, response: ?string, error: ?string}|null $row */
        $row = (new Query($this->db))->select(['public_id', 'state', 'response', 'error'])->from('commands')
            ->where(['idempotency_key' => $idempotencyKey])->one();
        if ($row === null) {
            return Packets::error($id, $type, 'Duplicate command rejected');
        }
        if ($row['state'] === 'confirmed' && $row['response'] !== null) {
            /** @var array<string, mixed> $response */
            $response = json_decode($row['response'], true, 64, JSON_THROW_ON_ERROR);
            return Packets::withId($response, $id);
        }
        if (in_array($row['state'], ['received', 'queued', 'sent'], true)) {
            return Packets::error($id, $type, "Same {$type} already in progress (ref {$row['public_id']}); not sent again");
        }

        return Packets::error($id, $type, ($row['error'] ?? "Previous identical {$type} did not succeed") . ' (duplicate not sent again)');
    }

    private function transition(string $publicId, string $from, string $to, int $stationId, string $correlationId, array $extra = []): void
    {
        $updated = $this->db->createCommand()->update('commands', ['state' => $to] + $extra, ['public_id' => $publicId, 'state' => $from])->execute();
        if ($updated !== 1) {
            throw new \LogicException(vsprintf('Command %s: invalid transition %s → %s', [$publicId, $from, $to]));
        }
        $this->audit->record('command', $publicId, 'transition', 'mediator', $stationId, $from, $to, correlationId: $correlationId);
    }

    /**
     * @param array<string, mixed>|null $response
     */
    private function complete(
        string $publicId,
        string $from,
        string $to,
        int $stationId,
        string $correlationId,
        ?array $response,
        ?string $error,
        ?int $txNo = null,
    ): void {
        try {
            $this->transition($publicId, $from, $to, $stationId, $correlationId, [
                'response' => $response === null ? null : json_encode($response, JsonResponder::FLAGS),
                'error' => $error === null ? null : substr($error, 0, 512),
                'pts_transaction_no' => $txNo,
                'completed_at' => $this->clock->nowForDb(),
            ]);
        } catch (Throwable $e) {
            // The device must still get its answer; the journal inconsistency is visible in the audit log.
            $this->audit->record('command', $publicId, 'journal_error', 'mediator', $stationId, $from, $to, ['error' => $e->getMessage()], $correlationId);
        }
    }

    /** @param array{Id: int, Type: string, Data?: array<string, mixed>} $packet */
    private static function dataInt(array $packet, string $key): ?int
    {
        $value = $packet['Data'][$key] ?? null;

        return is_int($value) ? $value : null;
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return sprintf('%s-%s-4%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
