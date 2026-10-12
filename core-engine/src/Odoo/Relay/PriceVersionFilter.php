<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use App\Reconciliation\SettledFills;
use App\Shared\Arr;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_values;
use function is_array;
use function is_numeric;
use function is_string;
use function substr;

/**
 * D26: `pos.config.mobile_get_price_version` is one timestamp for the whole Odoo database and is not bumped by station
 * price lines or by price pulls, so devices may never notice a price change. When the station's controller prices
 * changed later than Odoo's version, the relay reports that later time (same "YYYY-MM-DD HH:MM:SS" UTC format) and
 * FuelPay reloads its price catalog. Any failure passes Odoo's answer through.
 */
final class PriceVersionFilter
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettledFills $stations,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<array-key, mixed> $args execute_kw args
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>|null
     */
    public function apply(array $args, array $response): ?array
    {
        try {
            $raw = $args[5] ?? null;
            $positional = is_array($raw) ? array_values($raw) : [];
            $first = $positional[0] ?? null;
            $config = is_array($first) ? ($first[0] ?? null) : $first;
            $result = Arr::arrOrNull($response, 'result');
            if (!is_numeric($config) || $result === null) {
                return null;
            }
            $stationId = $this->stations->stationForPosConfig((int) $config);
            if ($stationId === null) {
                return null;
            }
            $changed = (new Query($this->db))->select('prices_changed_at')->from('stations')->where(['id' => $stationId])->scalar();
            if (!is_string($changed) || $changed === '') {
                return null;
            }
            $ours = substr($changed, 0, 19);
            $odoo = $result['price_version'] ?? null;
            if (is_string($odoo) && $odoo >= $ours) {
                return null;
            }
            $result['price_version'] = $ours;
            $response['result'] = $result;

            return $response;
        } catch (Throwable $e) {
            $this->logger->error('Price version filter failed; Odoo reply passed through', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
