<?php

declare(strict_types=1);

namespace App\Odoo\Relay;

use App\Shared\Arr;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function rtrim;

/**
 * Decision D1: in `pts.backend.config.mobile_get_pumps` responses, point the device's PTS URL at the mediator's
 * jsonPTS endpoint for that backend. Applied only when the mediator serves that controller and a public URL is
 * configured; otherwise the response is left untouched (the device keeps talking to the controller directly).
 * Credentials are passed through unchanged for now (D5); FuelPay requires a non-blank username to enable PTS mode.
 */
final class PumpsRewriter
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $publicUrl,
    ) {}

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>|null Rewritten response, or null when nothing was changed.
     */
    public function rewrite(array $response): ?array
    {
        if ($this->publicUrl === '') {
            return null;
        }
        $result = Arr::arrOrNull($response, 'result');
        $connection = Arr::arrOrNull($result, 'pts_connection');
        $backendId = Arr::int($result, 'backend_config_id');
        if ($result === null || $connection === null || $backendId === null) {
            return null;
        }
        $served = (new Query($this->db))->from(['c' => 'controllers'])
            ->innerJoin(['s' => 'stations'], 's.id = c.station_id')
            ->where(['c.odoo_backend_config_id' => $backendId, 'c.odoo_active' => 1, 's.active' => 1])
            ->exists();
        if (!$served) {
            return null;
        }

        $connection['integration_mode'] = 'direct';
        $connection['direct_pts_url'] = rtrim($this->publicUrl, '/') . '/stations/' . $backendId . '/jsonPTS';
        $result['pts_connection'] = $connection;
        $response['result'] = $result;

        return $response;
    }
}
