<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Pts\ControllerEndpoint;
use App\Shared\Http\HttpResult;
use Closure;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Builders for controller fixtures. Packet shapes are copied from captures of a real PTS-2 controller
 * (fuel-scratpad/phase0/pts-captures/status.json).
 */
final class PtsFixture
{
    /**
     * A FakeHttpTransport responder answering every PumpGetStatus packet via $statusFor(pump).
     *
     * @param Closure(int): array<string, mixed> $statusFor
     *
     * @return Closure(string): HttpResult
     */
    public static function statusResponder(Closure $statusFor): Closure
    {
        return static function (string $body) use ($statusFor): HttpResult {
            /** @var array{Packets: list<array{Id: int, Type: string, Data?: array{Pump?: int}}>} $request */
            $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            $packets = [];
            foreach ($request['Packets'] as $packet) {
                $packets[] = ['Id' => $packet['Id']] + $statusFor((int) ($packet['Data']['Pump'] ?? 0));
            }

            return new HttpResult(200, json_encode(['Protocol' => 'jsonPTS', 'Packets' => $packets], JSON_THROW_ON_ERROR), 4);
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function idle(int $pump, int $lastTx, ?float $total, float $lastVolume, float $lastAmount, string $endedLocal = '2026-10-11T06:40:16'): array
    {
        $data = [
            'Pump' => $pump, 'NozzleUp' => 0, 'Nozzle' => 0, 'Request' => '',
            'NozzlePrices' => [2.18, 0, 0, 0, 0, 0],
            'LastNozzle' => 1, 'LastVolume' => $lastVolume, 'LastPrice' => 2.18, 'LastAmount' => $lastAmount,
            'LastTransaction' => $lastTx, 'LastFuelGradeId' => 1, 'LastFuelGradeName' => 'A 91',
            'LastDateTimeStart' => '2026-10-11T06:39:20', 'LastDateTime' => $endedLocal,
            'LastFlowRate' => 29, 'LastUser' => 'PTS', 'User' => '',
        ];
        if ($total !== null) {
            $data['LastTotalVolume'] = $total;
            $data['LastTotalAmount'] = round($total * 2.18, 2);
        }

        return ['Type' => 'PumpIdleStatus', 'Data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    public static function filling(int $pump, int $tx, float $volume): array
    {
        return ['Type' => 'PumpFillingStatus', 'Data' => [
            'Pump' => $pump, 'Nozzle' => 1, 'Transaction' => $tx, 'Volume' => $volume, 'Amount' => round($volume * 2.18, 2),
        ]];
    }

    /**
     * Inserts a station with one enabled controller and the given pumps (on_controller = 1). Returns the endpoint.
     *
     * @param list<int> $pumps
     */
    public static function seedController(ConnectionInterface $db, int $odooStationId, int $odooBackendId, array $pumps): ControllerEndpoint
    {
        $db->createCommand()->insert('stations', [
            'code' => 'T' . $odooStationId, 'name' => 'Test Station ' . $odooStationId, 'odoo_station_id' => $odooStationId,
            'mode' => 'pts', 'utc_offset_minutes' => 180,
        ])->execute();
        $stationId = (int) (new Query($db))->select('id')->from('stations')->where(['odoo_station_id' => $odooStationId])->scalar();

        $db->createCommand()->insert('controllers', [
            'station_id' => $stationId, 'odoo_backend_config_id' => $odooBackendId, 'name' => 'Ctl ' . $odooBackendId,
            'is_primary' => 1, 'url' => "https://pts$odooBackendId.test/jsonPTS", 'auth_mode' => 'digest', 'username' => 'admin',
            'enabled' => 1, 'poll_interval_ms' => 10000, 'config_synced_at' => '2026-10-11 00:00:00.000',
        ])->execute();
        $controllerId = (int) (new Query($db))->select('id')->from('controllers')->where(['odoo_backend_config_id' => $odooBackendId])->scalar();

        foreach ($pumps as $pump) {
            $db->createCommand()->insert('pumps', [
                'station_id' => $stationId, 'controller_id' => $controllerId, 'pump_number' => $pump, 'on_controller' => 1,
            ])->execute();
        }
        $_ENV['PTS_PASSWORD_' . $odooBackendId] = 'test-secret';

        return new ControllerEndpoint(
            $controllerId,
            $stationId,
            'Ctl ' . $odooBackendId,
            "https://pts$odooBackendId.test/jsonPTS",
            'digest',
            'admin',
            'test-secret',
            false,
            10000,
            10000,
            180,
        );
    }
}
