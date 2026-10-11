<?php

declare(strict_types=1);

namespace App\Pts\Endpoint;

use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use App\Shared\Http\JsonResponder;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function hash;
use function json_encode;
use function sprintf;

/**
 * Resolves commands whose outcome is unknown, from evidence only (docs/transaction-lifecycle.md §1). Never re-sends.
 *
 *  - Crash recovery: a command still `sent` after {@see IN_FLIGHT_LIMIT_SECONDS} becomes `outcome_unknown`; one still
 *    `received`/`queued` was never sent and becomes `failed`.
 *  - Authorize: a fill starting on that pump within {@see AUTHORIZE_WINDOW_SECONDS} of sending → executed; the pump
 *    observed afterwards with no such fill → not executed.
 *  - Stop / emergency stop: a fill on that pump ending within {@see STOP_WINDOW_SECONDS} → executed; a fill that kept
 *    dispensing past that → needs review (high severity).
 *  - Anything unresolved after {@see REVIEW_AFTER_SECONDS} → `needs_review` + `unknown_command_outcome` discrepancy.
 */
final class CommandResolver
{
    public const IN_FLIGHT_LIMIT_SECONDS = 60;
    public const AUTHORIZE_WINDOW_SECONDS = 180;
    public const STOP_WINDOW_SECONDS = 15;
    public const REVIEW_AFTER_SECONDS = 1800;
    private const LEAD_SECONDS = 5;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {}

    /** @return array<string, int> counts by outcome */
    public function resolve(): array
    {
        $now = $this->clock->now();
        $counts = ['recovered_unknown' => 0, 'recovered_failed' => 0, 'executed' => 0, 'not_executed' => 0, 'needs_review' => 0];
        $inFlightCutoff = $now->modify('-' . self::IN_FLIGHT_LIMIT_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');

        /** @var list<array{public_id: string, state: string, station_id: string, correlation_id: string}> $stuck */
        $stuck = (new Query($this->db))->select(['public_id', 'state', 'station_id', 'correlation_id'])->from('commands')
            ->where(['state' => ['received', 'queued', 'sent']])->andWhere(['<', 'received_at', $inFlightCutoff])->all();
        foreach ($stuck as $row) {
            $to = $row['state'] === 'sent' ? 'outcome_unknown' : 'failed';
            $note = $to === 'failed' ? 'Process stopped before the command was sent' : 'Process stopped while waiting for the controller reply';
            if ($this->transition($row, $to, ['error' => $note, 'completed_at' => $now->format('Y-m-d H:i:s.v')])) {
                $counts[$to === 'failed' ? 'recovered_failed' : 'recovered_unknown']++;
            }
        }

        /** @var list<array{public_id: string, state: string, station_id: string, correlation_id: string, controller_id: string, type: string, pump_number: ?string, sent_at: ?string, received_at: string}> $unknown */
        $unknown = (new Query($this->db))
            ->select(['public_id', 'state', 'station_id', 'correlation_id', 'controller_id', 'type', 'pump_number', 'sent_at', 'received_at'])
            ->from('commands')->where(['state' => 'outcome_unknown'])->orderBy(['id' => SORT_ASC])->all();

        foreach ($unknown as $row) {
            $sentAt = new DateTimeImmutable(($row['sent_at'] ?? $row['received_at']) . ' UTC');
            $age = $now->getTimestamp() - $sentAt->getTimestamp();
            $pump = $row['pump_number'] === null ? null : (int) $row['pump_number'];
            $outcome = $pump === null ? null : match ($row['type']) {
                'PumpAuthorize' => $this->authorizeEvidence((int) $row['controller_id'], $pump, $sentAt, $age),
                'PumpStop', 'PumpEmergencyStop' => $this->stopEvidence((int) $row['controller_id'], $pump, $sentAt),
                default => null,
            };

            if ($outcome !== null) {
                [$to, $fillId, $note] = $outcome;
                if ($to === 'needs_review') {
                    $this->review($row, $note, 'high', $now);
                    $counts['needs_review']++;
                } elseif ($this->transition($row, $to, ['resolved_by_fill_event_id' => $fillId, 'error' => $note])) {
                    $counts[$to === 'resolved_executed' ? 'executed' : 'not_executed']++;
                }
                continue;
            }
            if ($age >= self::REVIEW_AFTER_SECONDS) {
                $this->review($row, 'No evidence within ' . (self::REVIEW_AFTER_SECONDS / 60) . ' minutes', 'warning', $now);
                $counts['needs_review']++;
            }
        }

        return $counts;
    }

    /** @return array{string, ?int, string}|null */
    private function authorizeEvidence(int $controllerId, int $pump, DateTimeImmutable $sentAt, int $age): ?array
    {
        $from = $sentAt->modify('-' . self::LEAD_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');
        $to = $sentAt->modify('+' . self::AUTHORIZE_WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');

        $fillId = (new Query($this->db))->select('id')->from('fill_events')
            ->where(['controller_id' => $controllerId, 'pump_number' => $pump])
            ->andWhere(['or', ['between', 'started_at', $from, $to], ['and', ['started_at' => null], ['between', 'ended_at', $from, $to]]])
            ->orderBy(['ended_at' => SORT_ASC])->scalar();
        if ($fillId !== false && $fillId !== null) {
            return ['resolved_executed', (int) $fillId, 'Fill observed on the pump after the command'];
        }

        // Not executed only if the collector actually observed the pump after the authorize window closed.
        if ($age > self::AUTHORIZE_WINDOW_SECONDS) {
            $observedAfter = (new Query($this->db))->from('pump_status_cache')
                ->where(['controller_id' => $controllerId, 'pump_number' => $pump])
                ->andWhere(['>', 'observed_at', $to])->exists();
            if ($observedAfter) {
                return ['resolved_not_executed', null, 'Pump observed with no fill within the authorize window'];
            }
        }

        return null;
    }

    /** @return array{string, ?int, string}|null */
    private function stopEvidence(int $controllerId, int $pump, DateTimeImmutable $sentAt): ?array
    {
        $from = $sentAt->modify('-' . self::LEAD_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');
        $until = $sentAt->modify('+' . self::STOP_WINDOW_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');
        $sent = $sentAt->format('Y-m-d H:i:s.v');

        $stopped = (new Query($this->db))->select('id')->from('fill_events')
            ->where(['controller_id' => $controllerId, 'pump_number' => $pump])
            ->andWhere(['between', 'ended_at', $from, $until])->scalar();
        if ($stopped !== false && $stopped !== null) {
            return ['resolved_executed', (int) $stopped, 'Fill ended right after the stop'];
        }

        $continued = (new Query($this->db))->select('id')->from('fill_events')
            ->where(['controller_id' => $controllerId, 'pump_number' => $pump])
            ->andWhere(['<=', 'started_at', $sent])->andWhere(['>', 'ended_at', $until])->scalar();
        if ($continued !== false && $continued !== null) {
            return ['needs_review', (int) $continued, 'Pump kept dispensing after the stop command'];
        }

        return null;
    }

    /**
     * @param array{public_id: string, state: string, station_id: string, correlation_id: string, ...} $row
     */
    private function review(array $row, string $reason, string $severity, DateTimeImmutable $now): void
    {
        if (!$this->transition($row, 'needs_review', ['error' => $reason])) {
            return;
        }
        $columns = [
            'station_id' => (int) $row['station_id'],
            'type' => 'unknown_command_outcome',
            'severity' => $severity,
            'status' => 'open',
            'fingerprint' => hash('sha256', "{$row['station_id']}|unknown_command_outcome|{$row['public_id']}"),
            'period_start' => $now->format('Y-m-d H:i:s.v'),
            'period_end' => $now->format('Y-m-d H:i:s.v'),
            'summary' => sprintf('Command %s: outcome could not be confirmed (%s)', $row['public_id'], $reason),
            'details' => json_encode(['command' => $row['public_id'], 'reason' => $reason], JsonResponder::FLAGS),
        ];
        $update = $columns;
        unset($update['status'], $update['fingerprint']);
        $this->db->createCommand()->upsert('discrepancies', $columns, $update)->execute();
    }

    /**
     * Guarded transition (only from the state we read) + audit, in one DB transaction.
     *
     * @param array{public_id: string, state: string, station_id: string, correlation_id: string, ...} $row
     */
    private function transition(array $row, string $to, array $extra): bool
    {
        return $this->db->transaction(function () use ($row, $to, $extra): bool {
            $updated = $this->db->createCommand()->update(
                'commands',
                ['state' => $to] + $extra,
                ['public_id' => $row['public_id'], 'state' => $row['state']],
            )->execute();
            if ($updated !== 1) {
                return false;
            }
            $this->audit->record('command', $row['public_id'], 'resolved', 'resolver', (int) $row['station_id'], $row['state'], $to, $extra, $row['correlation_id']);

            return true;
        });
    }
}
