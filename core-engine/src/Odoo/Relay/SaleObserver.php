<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use App\Shared\Arr;
use App\Shared\Clock;
use App\Shared\Decimal;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;
use function is_int;
use function is_numeric;

/**
 * Records every sale payload a device sends through the relay (`sale_observations`), with Odoo's answer when there
 * was one. Gives the mediator its own trace of what each terminal tried to register — including attempts Odoo never
 * acknowledged — for reconciliation. Also keeps the device registry (`devices`) current.
 */
final class SaleObserver
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<array-key, mixed> $args execute_kw args
     * @param array<array-key, mixed>|null $response Odoo JSON-RPC response, null when Odoo did not answer
     */
    public function observe(?string $modelMethod, array $args, ?array $response, ?int $relayCallId): void
    {
        if ($modelMethod !== 'register_terminal_sale' && $modelMethod !== 'register_terminal_sales_bulk') {
            return;
        }
        $callArgs = Arr::arr($args, 5);
        $payloads = $modelMethod === 'register_terminal_sale' ? [Arr::raw($callArgs, 0)] : Arr::arr($callArgs, 0);
        $result = Arr::arrOrNull($response, 'result');
        $posSync = Arr::arr($result, 'pos_sync');
        $synced = Arr::bool($posSync, 'synced');
        $manual = null;

        try {
            /** @var mixed $payload */
            foreach ($payloads as $payload) {
                $uuid = Arr::str($payload, 'uuid');
                if (!is_array($payload) || $uuid === null) {
                    continue;
                }
                $backendId = Arr::int($payload, 'station_id') ?? Arr::int($payload, 'pts_station_id');
                $stationId = $this->stationForBackend($backendId);
                $volume = Arr::raw($payload, 'volume');
                $amount = Arr::raw($payload, 'amount_sar');
                $manual = Arr::bool($payload, 'manual_sale');

                $this->db->createCommand()->insert('sale_observations', [
                    'station_id' => $stationId,
                    'device_id' => $this->device(Arr::str($payload, 'terminal_id'), $stationId),
                    'relay_call_id' => $relayCallId,
                    'uuid' => $uuid,
                    'payment_status' => Arr::str($payload, 'payment_status'),
                    'pump_number' => Arr::int($payload, 'pump_number'),
                    'nozzle_number' => Arr::int($payload, 'nozzle_number'),
                    'volume' => is_numeric($volume) ? Decimal::of($volume) : null,
                    'amount' => is_numeric($amount) ? Decimal::of($amount, 2) : null,
                    'pts_transaction_id' => Arr::str($payload, 'pts_transaction_id'),
                    'manual_sale' => $manual === null ? null : (int) $manual,
                    'rrn' => Arr::str($payload, 'rrn'),
                    'approval_code' => Arr::str($payload, 'approval_code'),
                    'odoo_status' => $modelMethod === 'register_terminal_sale' ? Arr::str($result, 'status') : ($result === null ? null : 'bulk'),
                    'canonical_uuid' => Arr::str($result, 'canonical_uuid'),
                    'pos_synced' => $synced === null ? null : (int) $synced,
                    'created_at' => $this->clock->nowForDb(),
                ])->execute();
            }
        } catch (Throwable $e) {
            // Observation is best-effort: the device already has Odoo's answer.
            $this->logger->error('Sale observation failed', ['error' => $e->getMessage()]);
        }
    }

    private function device(?string $terminalId, ?int $stationId): ?int
    {
        if ($terminalId === null) {
            return null;
        }
        $now = $this->clock->nowForDb();
        // A sale whose station is unknown (e.g. a backend id the mediator does not serve) must not unlink the device.
        $this->db->createCommand()->upsert(
            'devices',
            ['terminal_id' => $terminalId, 'station_id' => $stationId, 'first_seen_at' => $now, 'last_seen_at' => $now],
            $stationId === null ? ['last_seen_at' => $now] : ['station_id' => $stationId, 'last_seen_at' => $now],
        )->execute();

        $id = (new Query($this->db))->select('id')->from('devices')->where(['terminal_id' => $terminalId])->scalar();

        return is_numeric($id) ? (int) $id : null;
    }

    /** FuelPay's "station id" is the Odoo `pts.backend.config` id (the mobile models use the backend as station). */
    private function stationForBackend(?int $backendId): ?int
    {
        if (!is_int($backendId)) {
            return null;
        }
        $id = (new Query($this->db))->select('station_id')->from('controllers')
            ->where(['odoo_backend_config_id' => $backendId])->scalar();

        return is_numeric($id) ? (int) $id : null;
    }
}
