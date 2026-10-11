<?php

declare(strict_types=1);

namespace App\Odoo\Sync;

use App\Odoo\OdooReadClientInterface;
use App\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function number_format;

/**
 * Incremental, read-only mirror of `pts.terminal.sale` into `odoo_sales`, per station.
 *
 * Cursor = (write_date, id) in `sync_cursors`, so edits to existing sales (payment status, POS sync, session) are
 * picked up too. Upserts are keyed on the Odoo id, so re-reading the same page is harmless.
 */
final class OdooSalesReader
{
    public const SOURCE = 'odoo.terminal_sale';
    private const PAGE_SIZE = 500;

    private const FIELDS = [
        'id', 'uuid', 'alias_uuids', 'pump_number', 'nozzle_number', 'fuel_name', 'volume', 'amount_sar', 'price',
        'payment_status', 'payment_method', 'rrn', 'approval_code', 'manual_sale', 'pts_transaction_id',
        'terminal_id', 'terminal_order_ref', 'operator_name', 'pos_config_id', 'pos_session_id', 'pos_order_id',
        'pos_sync_status', 'created_at', 'create_date', 'write_date',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @return int Number of sales upserted.
     */
    public function pull(OdooReadClientInterface $odoo, int $stationId, int $odooStationId, int $initialLookbackDays, int $maxPages = 50): int
    {
        [$cursorDate, $cursorId] = $this->cursor($stationId, $initialLookbackDays);
        $total = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $rows = $odoo->searchRead(
                'pts.terminal.sale',
                [
                    ['station_id', '=', $odooStationId],
                    '|',
                    ['write_date', '>', $cursorDate],
                    '&', ['write_date', '=', $cursorDate], ['id', '>', $cursorId],
                ],
                self::FIELDS,
                self::PAGE_SIZE,
                'write_date asc, id asc',
            );
            if ($rows === []) {
                break;
            }

            $this->db->transaction(function () use ($rows, $stationId, &$cursorDate, &$cursorId, &$total): void {
                foreach ($rows as $row) {
                    $this->upsert($stationId, $row);
                    $cursorDate = (string) $row['write_date'];
                    $cursorId = (int) $row['id'];
                    $total++;
                }
                $this->saveCursor($stationId, $cursorDate, $cursorId);
            });

            if (count($rows) < self::PAGE_SIZE) {
                break;
            }
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsert(int $stationId, array $row): void
    {
        $createdAt = self::str($row['created_at'] ?? null) ?? self::str($row['create_date'] ?? null) ?? (string) $row['write_date'];
        $columns = [
            'station_id' => $stationId,
            'odoo_id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'alias_uuids' => self::str($row['alias_uuids'] ?? null),
            'pump_number' => self::int($row['pump_number'] ?? null),
            'nozzle_number' => self::int($row['nozzle_number'] ?? null),
            'fuel_name' => self::str($row['fuel_name'] ?? null),
            'volume' => self::decimal($row['volume'] ?? null, 3) ?? '0.000',
            'amount' => self::decimal($row['amount_sar'] ?? null, 2) ?? '0.00',
            'price' => self::decimal($row['price'] ?? null, 3),
            'payment_status' => self::str($row['payment_status'] ?? null) ?? 'UNKNOWN',
            'payment_method' => self::str($row['payment_method'] ?? null),
            'rrn' => self::str($row['rrn'] ?? null),
            'approval_code' => self::str($row['approval_code'] ?? null),
            'manual_sale' => ($row['manual_sale'] ?? false) === true ? 1 : 0,
            'pts_transaction_id' => self::str($row['pts_transaction_id'] ?? null),
            'terminal_id' => self::str($row['terminal_id'] ?? null),
            'terminal_order_ref' => self::str($row['terminal_order_ref'] ?? null),
            'operator_name' => self::str($row['operator_name'] ?? null),
            'odoo_pos_config_id' => self::many2oneId($row['pos_config_id'] ?? null),
            'odoo_pos_session_id' => self::many2oneId($row['pos_session_id'] ?? null),
            'odoo_pos_order_id' => self::many2oneId($row['pos_order_id'] ?? null),
            'pos_sync_status' => self::str($row['pos_sync_status'] ?? null),
            'sale_created_at' => $createdAt,
            'odoo_write_date' => (string) $row['write_date'],
        ];
        $update = $columns;
        unset($update['odoo_id']);
        $this->db->createCommand()->upsert('odoo_sales', $columns, $update)->execute();
    }

    /** @return array{string, int} */
    private function cursor(int $stationId, int $initialLookbackDays): array
    {
        /** @var array{cursor_value: string, cursor_id: string}|null $row */
        $row = (new Query($this->db))->select(['cursor_value', 'cursor_id'])->from('sync_cursors')
            ->where(['source' => self::SOURCE, 'station_id' => $stationId])->one();
        if ($row !== null) {
            return [$row['cursor_value'], (int) $row['cursor_id']];
        }

        return [$this->clock->now()->modify("-{$initialLookbackDays} days")->format('Y-m-d H:i:s'), 0];
    }

    private function saveCursor(int $stationId, string $writeDate, int $id): void
    {
        $this->db->createCommand()->upsert(
            'sync_cursors',
            ['source' => self::SOURCE, 'station_id' => $stationId, 'cursor_value' => $writeDate, 'cursor_id' => $id],
            ['cursor_value' => $writeDate, 'cursor_id' => $id],
        )->execute();
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function decimal(mixed $value, int $scale): ?string
    {
        return is_int($value) || is_float($value) ? number_format((float) $value, $scale, '.', '') : null;
    }

    private static function many2oneId(mixed $value): ?int
    {
        $id = is_array($value) ? ($value[0] ?? null) : null;

        return is_int($id) ? $id : null;
    }
}
