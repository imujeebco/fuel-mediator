<?php

declare(strict_types=1);

namespace App\Price;

use App\Odoo\OdooReadClientInterface;
use App\Pts\ControllerEndpoint;
use App\Pts\ControllerLock;
use App\Pts\ControllerRepository;
use App\Pts\Endpoint\ConfigGeneration;
use App\Pts\PacketPolicy;
use App\Pts\PtsClient;
use App\Pts\PtsException;
use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use App\Shared\Http\JsonResponder;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function abs;
use function array_map;
use function hash;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function max;
use function min;
use function number_format;
use function round;
use function sprintf;
use function str_contains;
use function strtolower;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Fuel prices from Odoo to the controllers (decisions D25–D27).
 *
 * - Odoo is the source: `pts.station.fuel.price` (station × fuel type), the current line being the latest whose
 *   effective date is not in the future. Fuel types are matched to controller grades by name.
 * - The controller's current prices come from `GetFuelGradesConfiguration` (read-only).
 * - A push sends `SetFuelGradesPrices` for every configured grade (the controller rejects partial sets), then reads
 *   the prices back; only a read-back that matches counts as verified. Each push is recorded in `price_pushes`.
 * - Safety: prices must lie in a plausible range, and an automatic push never moves a price by more than
 *   MAX_AUTO_CHANGE; larger changes need a manual push from the dashboard.
 */
final class PriceService
{
    public const MIN_PRICE = 0.5;
    public const MAX_PRICE = 10.0;
    /** Largest relative change an automatic push may make (manual pushes may exceed it). */
    public const MAX_AUTO_CHANGE = 0.25;
    private const TOLERANCE = 0.0005;
    private const LOCK_WAIT_SECONDS = 5.0;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PtsClient $pts,
        private readonly ControllerRepository $controllers,
        private readonly ControllerLock $lock,
        private readonly CacheInterface $cache,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Mirrors Odoo's station price lines for every station with an Odoo id (read-only, one query).
     *
     * @return int Lines upserted.
     */
    public function pullOdooPrices(OdooReadClientInterface $odoo): int
    {
        /** @var array<int, int> $stationByOdoo odoo station id => station id */
        $stationByOdoo = [];
        /** @var list<array{id: string, odoo_station_id: string}> $stationRows */
        $stationRows = (new Query($this->db))->select(['id', 'odoo_station_id'])->from('stations')->where(['not', ['odoo_station_id' => null]])->all();
        foreach ($stationRows as $row) {
            $stationByOdoo[(int) $row['odoo_station_id']] = (int) $row['id'];
        }
        if ($stationByOdoo === []) {
            return 0;
        }
        $rows = $odoo->searchRead(
            'pts.station.fuel.price',
            [['station_id', 'in', array_keys($stationByOdoo)]],
            ['id', 'station_id', 'fuel_type_id', 'price', 'effective_date', 'write_date'],
        );
        $now = $this->clock->nowForDb();
        $seen = [];
        foreach ($rows as $row) {
            $odooStation = self::many2oneId($row['station_id'] ?? null);
            $stationId = $odooStation === null ? null : ($stationByOdoo[$odooStation] ?? null);
            $fuel = $row['fuel_type_id'] ?? null;
            if ($stationId === null || !is_array($fuel) || !is_string($fuel[1] ?? null) || !is_numeric($row['price'] ?? null)) {
                continue;
            }
            $seen[] = (int) $row['id'];
            $columns = [
                'station_id' => $stationId,
                'odoo_id' => (int) $row['id'],
                'odoo_fuel_type_id' => is_int($fuel[0] ?? null) ? $fuel[0] : null,
                'fuel_name' => $fuel[1],
                'price' => number_format((float) $row['price'], 4, '.', ''),
                'effective_date' => is_string($row['effective_date'] ?? null) ? $row['effective_date'] : null,
                'odoo_write_date' => is_string($row['write_date'] ?? null) ? $row['write_date'] : null,
                'synced_at' => $now,
            ];
            $update = $columns;
            unset($update['odoo_id']);
            $this->db->createCommand()->upsert('odoo_station_prices', $columns, $update)->execute();
        }
        $this->db->createCommand()->delete('odoo_station_prices', $seen === [] ? [] : ['not in', 'odoo_id', $seen])->execute();

        return count($rows);
    }

    /**
     * Reads the controller's grades and prices (read-only) and stores them. Records a price change (and refreshes the
     * device-facing configuration cache) when a price differs from the last reading.
     *
     * @return list<array{grade_id: int, name: string, price: float}>
     */
    public function readController(ControllerEndpoint $controller): array
    {
        $reply = $this->pts->send($controller, [['Type' => 'GetFuelGradesConfiguration']], PacketPolicy::READ_ONLY)[0] ?? null;
        $grades = self::grades($reply);
        if ($grades === []) {
            throw new PtsException(PtsException::MALFORMED, 'Controller returned no fuel grades.');
        }
        $this->storeGrades($controller, $grades);

        return $grades;
    }

    /**
     * Odoo's current price per fuel name for a station (latest line effective today or earlier, station local date).
     *
     * @return array<string, array{price: float, fuel_name: string, effective_date: ?string, odoo_write_date: ?string}> normalized name => line
     */
    public function odooPrices(int $stationId): array
    {
        $offset = (int) (new Query($this->db))->select('utc_offset_minutes')->from('stations')->where(['id' => $stationId])->scalar();
        /** @var non-empty-string $zone */
        $zone = sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 60), abs($offset) % 60);
        $today = $this->clock->now()->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
        /** @var list<array{fuel_name: string, price: string, effective_date: ?string, odoo_write_date: ?string}> $rows */
        $rows = (new Query($this->db))->select(['fuel_name', 'price', 'effective_date', 'odoo_write_date'])->from('odoo_station_prices')
            ->where(['station_id' => $stationId])
            ->andWhere(['or', ['effective_date' => null], ['<=', 'effective_date', $today]])
            ->orderBy(['effective_date' => SORT_ASC, 'odoo_write_date' => SORT_ASC])
            ->all();
        $result = [];
        foreach ($rows as $row) {
            $result[self::normalize($row['fuel_name'])] = [
                'price' => (float) $row['price'], 'fuel_name' => $row['fuel_name'],
                'effective_date' => $row['effective_date'], 'odoo_write_date' => $row['odoo_write_date'],
            ];
        }

        return $result;
    }

    /**
     * Price comparison for a station: per controller grade, the controller price, Odoo's price, what the pumps report
     * and what the latest sales were charged.
     *
     * @return list<array<string, mixed>>
     */
    public function compare(int $stationId): array
    {
        $controllerId = $this->mainControllerId($stationId);
        if ($controllerId === null) {
            return [];
        }
        $odoo = $this->odooPrices($stationId);
        /** @var list<array{grade_id: string, name: string, price: string, observed_at: string, price_changed_at: ?string}> $grades */
        $grades = (new Query($this->db))->from('fuel_grades')->where(['controller_id' => $controllerId])->orderBy(['grade_id' => SORT_ASC])->all();
        $pumpPrices = $this->pumpPrices($stationId, $controllerId);
        $since = $this->clock->now()->modify('-3 hours')->format('Y-m-d H:i:s.v');

        $rows = [];
        foreach ($grades as $grade) {
            $key = self::normalize($grade['name']);
            $odooLine = $odoo[$key] ?? null;
            $controllerPrice = (float) $grade['price'];
            $salePrice = (new Query($this->db))->select('price')->from('odoo_sales')
                ->where(['station_id' => $stationId, 'fuel_name' => $grade['name'], 'payment_status' => 'APPROVED'])
                ->andWhere(['>=', 'sale_created_at', $since])->andWhere(['not', ['price' => null]])
                ->orderBy(['sale_created_at' => SORT_DESC])->limit(1)->scalar();
            $pumps = $pumpPrices[(int) $grade['grade_id']] ?? [];
            /** @var string|false|null $salePrice */
            $pumpMismatch = array_filter($pumps, static fn(float $p): bool => abs($p - $controllerPrice) > self::TOLERANCE);
            $state = match (true) {
                $odooLine === null => 'no_odoo_price',
                abs($odooLine['price'] - $controllerPrice) > self::TOLERANCE => 'mismatch',
                $pumpMismatch !== [] => 'pump_mismatch',
                default => 'ok',
            };
            $rows[] = [
                'grade_id' => (int) $grade['grade_id'],
                'grade' => $grade['name'],
                'controller_price' => $controllerPrice,
                'odoo_price' => $odooLine['price'] ?? null,
                'odoo_effective_date' => $odooLine['effective_date'] ?? null,
                'odoo_write_date' => $odooLine['odoo_write_date'] ?? null,
                'pump_prices' => $pumps,
                'sale_price' => $salePrice === false || $salePrice === null ? null : (float) $salePrice,
                'observed_at' => $grade['observed_at'],
                'price_changed_at' => $grade['price_changed_at'],
                'state' => $state,
            ];
        }

        return $rows;
    }

    /**
     * Sends Odoo's prices to the station's controller and verifies them. `$resend` sends them even when the
     * controller already has them.
     *
     * @return array{state: string, message: string}
     */
    public function push(int $stationId, string $triggeredBy, bool $manual, bool $resend = false): array
    {
        $row = $this->controllerRow($stationId);
        $endpoint = $this->controllers->endpoint($row);
        $controllerId = (int) $row['id'];
        if (!$this->lock->acquire($controllerId, self::LOCK_WAIT_SECONDS)) {
            return ['state' => 'busy', 'message' => 'Controller busy; try again in a moment.'];
        }
        try {
            $current = $this->readController($endpoint);
            $odoo = $this->odooPrices($stationId);
            $payload = [];
            $changes = [];
            foreach ($current as $grade) {
                $target = $odoo[self::normalize($grade['name'])]['price'] ?? null;
                $price = $target === null ? $grade['price'] : round($target, 2);
                if ($target !== null && abs($price - $grade['price']) > self::TOLERANCE) {
                    if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
                        return $this->refuse($stationId, $controllerId, $triggeredBy, $current, sprintf('%s: %.2f is outside %.2f–%.2f SAR/L', $grade['name'], $price, self::MIN_PRICE, self::MAX_PRICE));
                    }
                    $relative = $grade['price'] > 0 ? abs($price - $grade['price']) / $grade['price'] : 1.0;
                    if (!$manual && $relative > self::MAX_AUTO_CHANGE) {
                        return $this->refuse($stationId, $controllerId, $triggeredBy, $current, sprintf('%s: %.2f → %.2f changes more than %d%%; push it manually from the dashboard', $grade['name'], $grade['price'], $price, (int) (self::MAX_AUTO_CHANGE * 100)));
                    }
                    $changes[] = sprintf('%s %.2f → %.2f', $grade['name'], $grade['price'], $price);
                }
                $payload[] = ['FuelGradeId' => $grade['grade_id'], 'Price' => $price];
            }
            if ($changes === []) {
                return ['state' => 'unchanged', 'message' => 'Controller already has Odoo\'s prices.'];
            }

            $now = $this->clock->nowForDb();
            $this->db->createCommand()->insert('price_pushes', [
                'station_id' => $stationId, 'controller_id' => $controllerId, 'triggered_by' => $triggeredBy,
                'previous' => json_encode($current, JsonResponder::FLAGS), 'requested' => json_encode($payload, JsonResponder::FLAGS),
                'state' => 'sent', 'created_at' => $now,
            ])->execute();
            $pushId = (int) $this->db->getLastInsertId();

            try {
                $reply = $this->pts->send($endpoint, [['Type' => 'SetFuelGradesPrices', 'Data' => ['FuelGradesPrices' => $payload]]], PacketPolicy::PRICE_ADMIN)[0] ?? null;
            } catch (PtsException $e) {
                return $this->finish($pushId, $stationId, $controllerId, 'failed', "[{$e->kind}] {$e->getMessage()}", $changes, $triggeredBy);
            }
            if (!is_array($reply) || ($reply['Error'] ?? false) === true || (isset($reply['Message']) && $reply['Message'] !== 'OK' && $reply['Message'] !== '')) {
                $message = is_array($reply) ? json_encode($reply['Message'] ?? $reply['Error'] ?? 'error', JsonResponder::FLAGS) : 'no reply';
                return $this->finish($pushId, $stationId, $controllerId, 'rejected', 'Controller rejected the prices: ' . $message, $changes, $triggeredBy);
            }

            $after = $this->readController($endpoint);
            $wrong = [];
            $byId = [];
            foreach ($after as $grade) {
                $byId[$grade['grade_id']] = $grade['price'];
            }
            foreach ($payload as $line) {
                if (abs(($byId[$line['FuelGradeId']] ?? -1.0) - $line['Price']) > self::TOLERANCE) {
                    $wrong[] = 'grade ' . $line['FuelGradeId'];
                }
            }

            return $wrong === []
                ? $this->finish($pushId, $stationId, $controllerId, 'verified', null, $changes, $triggeredBy)
                : $this->finish($pushId, $stationId, $controllerId, 'mismatch', 'Read-back differs for ' . implode(', ', $wrong), $changes, $triggeredBy);
        } finally {
            $this->lock->release($controllerId);
        }
    }

    /**
     * One price pass for a station during sync: read the controller, compare, push automatically when the station
     * is opted in, and keep a `price_mismatch` issue open while Odoo and the controller disagree.
     *
     * @return string|null Short status for the sync report.
     */
    public function check(int $stationId): ?string
    {
        $row = $this->controllerRowOrNull($stationId);
        if ($row === null) {
            return null;
        }
        $optedIn = (int) (new Query($this->db))->select('price_push_enabled')->from('stations')->where(['id' => $stationId])->scalar() === 1;
        if (!$optedIn && (int) $row['enabled'] !== 1) {
            return null; // not collected and not opted in: do not poll this controller
        }
        $this->readController($this->controllers->endpoint($row));
        $mismatches = array_values(array_filter($this->compare($stationId), static fn(array $r): bool => $r['state'] === 'mismatch'));

        $status = null;
        if ($mismatches !== [] && $optedIn) {
            // Odoo writes are given a minute to settle (wizards write several lines).
            $settled = array_filter($mismatches, fn(array $r): bool => $r['odoo_write_date'] === null
                || $this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $r['odoo_write_date'] . ' UTC'))->getTimestamp() >= 60);
            if (count($settled) === count($mismatches)) {
                $result = $this->push($stationId, 'auto', false);
                $status = 'price push: ' . $result['state'];
                $mismatches = array_values(array_filter($this->compare($stationId), static fn(array $r): bool => $r['state'] === 'mismatch'));
            }
        }
        $this->syncIssue($stationId, $mismatches);

        return $status ?? ($mismatches === [] ? null : count($mismatches) . ' price mismatch(es)');
    }

    /**
     * Price for a PumpAuthorize from a device (D26): the controller's current grade price for that pump and nozzle,
     * or null when unknown (the device's price is then left as it is).
     */
    public function authorizePrice(int $controllerId, int $pump, ?int $nozzle): ?float
    {
        $query = (new Query($this->db))->select(['g.price'])->from(['n' => 'nozzles'])
            ->innerJoin(['p' => 'pumps'], 'p.id = n.pump_id')
            ->innerJoin(['g' => 'fuel_grades'], 'g.controller_id = p.controller_id AND g.grade_id = n.fuel_grade_id')
            ->where(['p.controller_id' => $controllerId, 'p.pump_number' => $pump, 'n.on_controller' => 1]);
        if ($nozzle !== null && $nozzle > 0) {
            $query->andWhere(['n.nozzle_number' => $nozzle]);
        }
        /** @var list<string> $prices */
        $prices = $query->column();
        if ($prices === [] || count(array_unique($prices)) > 1) {
            return null; // unknown, or several grades on the pump and no nozzle given
        }

        return (float) $prices[0];
    }

    /**
     * Grade key shared by Odoo fuel types and controller grades, whose spellings differ per station
     * ("A 91", "A91", "91"; "Diesel", "Disel", "Desil", "DSL").
     */
    public static function normalize(string $name): string
    {
        $name = strtolower(trim($name));
        if (preg_match('/d(ie|i|e)s(e)?l|dsl|desil/', $name) === 1) {
            return 'diesel';
        }
        if (preg_match('/(100|[89][0-9])/', $name, $m) === 1) {
            return 'octane' . $m[1];
        }

        return (string) preg_replace('/[^a-z0-9]+/', '', $name);
    }

    /** @param list<array{grade_id: int, name: string, price: float}> $grades */
    private function storeGrades(ControllerEndpoint $controller, array $grades): void
    {
        $now = $this->clock->nowForDb();
        $changed = false;
        foreach ($grades as $grade) {
            $old = (new Query($this->db))->select('price')->from('fuel_grades')
                ->where(['controller_id' => $controller->id, 'grade_id' => $grade['grade_id']])->scalar();
            $isChange = $old !== false && $old !== null && abs((float) $old - $grade['price']) > self::TOLERANCE;
            $changed = $changed || $isChange;
            $columns = [
                'controller_id' => $controller->id, 'grade_id' => $grade['grade_id'], 'station_id' => $controller->stationId,
                'name' => $grade['name'], 'price' => number_format($grade['price'], 4, '.', ''), 'observed_at' => $now,
            ];
            $update = ['name' => $grade['name'], 'price' => $columns['price'], 'observed_at' => $now];
            if ($isChange) {
                $update['price_changed_at'] = $now;
            }
            $this->db->createCommand()->upsert('fuel_grades', $columns, $update)->execute();
        }
        if ($changed) {
            $this->db->createCommand()->update('stations', ['prices_changed_at' => $now], ['id' => $controller->stationId])->execute();
            ConfigGeneration::bump($this->cache, $controller->id);
        }
    }

    /** @return array<int, list<float>> grade id => prices the pumps currently report (from the status cache) */
    private function pumpPrices(int $stationId, int $controllerId): array
    {
        /** @var list<array{packet: string, pump_number: string}> $rows */
        $rows = (new Query($this->db))->select(['sc.packet', 'sc.pump_number'])->from(['sc' => 'pump_status_cache'])
            ->where(['sc.controller_id' => $controllerId])->all();
        /** @var array<string, string> $gradeByPumpNozzle "pump:nozzle" => grade id */
        $gradeByPumpNozzle = [];
        /** @var list<array{pump_number: string, nozzle_number: string, fuel_grade_id: ?string}> $nozzles */
        $nozzles = (new Query($this->db))->select(['p.pump_number', 'n.nozzle_number', 'n.fuel_grade_id'])->from(['n' => 'nozzles'])
            ->innerJoin(['p' => 'pumps'], 'p.id = n.pump_id')->where(['p.controller_id' => $controllerId, 'n.on_controller' => 1])->all();
        foreach ($nozzles as $n) {
            $gradeByPumpNozzle[$n['pump_number'] . ':' . $n['nozzle_number']] = (string) $n['fuel_grade_id'];
        }
        $result = [];
        foreach ($rows as $row) {
            /** @var mixed $packet */
            $packet = json_decode($row['packet'], true);
            $data = is_array($packet) ? ($packet['Data'] ?? $packet) : [];
            $prices = is_array($data) ? ($data['NozzlePrices'] ?? null) : null;
            if (!is_array($prices)) {
                continue;
            }
            foreach ($prices as $index => $price) {
                $grade = $gradeByPumpNozzle[$row['pump_number'] . ':' . ((int) $index + 1)] ?? null;
                if ($grade !== null && (is_int($price) || is_float($price)) && $price > 0) {
                    $result[(int) $grade][] = (float) $price;
                }
            }
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $mismatches */
    private function syncIssue(int $stationId, array $mismatches): void
    {
        $fingerprint = hash('sha256', "$stationId|price_mismatch|current");
        if ($mismatches === []) {
            $this->db->createCommand()->update('discrepancies', [
                'status' => 'resolved', 'resolved_by' => 'system', 'resolved_at' => $this->clock->nowForDb(), 'resolution_note' => 'Prices match',
            ], ['fingerprint' => $fingerprint, 'status' => 'open'])->execute();
            return;
        }
        $summary = 'Controller prices differ from Odoo: ' . implode(', ', array_map(
            static fn(array $r): string => sprintf('%s %.2f (Odoo %.2f)', (string) $r['grade'], (float) $r['controller_price'], (float) $r['odoo_price']),
            $mismatches,
        ));
        $now = $this->clock->nowForDb();
        $columns = [
            'station_id' => $stationId, 'type' => 'price_mismatch', 'severity' => 'high', 'status' => 'open', 'fingerprint' => $fingerprint,
            'period_start' => $now, 'period_end' => $now, 'summary' => mb_substr($summary, 0, 255),
            'details' => json_encode($mismatches, JsonResponder::FLAGS),
        ];
        $update = $columns;
        unset($update['fingerprint'], $update['period_start']);
        $this->db->createCommand()->upsert('discrepancies', $columns, $update)->execute();
    }

    /**
     * @param list<array{grade_id: int, name: string, price: float}> $current
     *
     * @return array{state: string, message: string}
     */
    private function refuse(int $stationId, int $controllerId, string $triggeredBy, array $current, string $reason): array
    {
        $this->db->createCommand()->insert('price_pushes', [
            'station_id' => $stationId, 'controller_id' => $controllerId, 'triggered_by' => $triggeredBy,
            'previous' => json_encode($current, JsonResponder::FLAGS), 'requested' => '[]', 'state' => 'rejected',
            'error' => mb_substr('Not sent: ' . $reason, 0, 512), 'created_at' => $this->clock->nowForDb(), 'completed_at' => $this->clock->nowForDb(),
        ])->execute();
        $this->audit->record('prices', $stationId, 'push_refused', $triggeredBy, $stationId, data: ['reason' => $reason]);

        return ['state' => 'refused', 'message' => 'Not sent: ' . $reason];
    }

    /**
     * @param list<string> $changes
     *
     * @return array{state: string, message: string}
     */
    private function finish(int $pushId, int $stationId, int $controllerId, string $state, ?string $error, array $changes, string $triggeredBy): array
    {
        $now = $this->clock->nowForDb();
        $this->db->createCommand()->update('price_pushes', ['state' => $state, 'error' => $error === null ? null : mb_substr($error, 0, 512), 'completed_at' => $now], ['id' => $pushId])->execute();
        $this->audit->record('prices', $stationId, 'push_' . $state, $triggeredBy, $stationId, data: ['push_id' => $pushId, 'changes' => $changes, 'error' => $error]);
        $fingerprint = hash('sha256', "$stationId|price_push_failed|current");
        if ($state === 'verified') {
            $this->db->createCommand()->update('discrepancies', ['status' => 'resolved', 'resolved_by' => 'system', 'resolved_at' => $now, 'resolution_note' => 'Verified push #' . $pushId],
                ['fingerprint' => $fingerprint, 'status' => 'open'])->execute();
            $this->logger->info('Prices pushed and verified', ['station_id' => $stationId, 'controller_id' => $controllerId, 'changes' => $changes]);

            return ['state' => 'verified', 'message' => 'Prices set and verified on the controller: ' . implode(', ', $changes) . '.'];
        }
        $this->db->createCommand()->upsert('discrepancies', [
            'station_id' => $stationId, 'type' => 'price_push_failed', 'severity' => 'critical', 'status' => 'open', 'fingerprint' => $fingerprint,
            'period_start' => $now, 'period_end' => $now, 'summary' => mb_substr("Price push #$pushId $state: " . (string) $error, 0, 255),
            'details' => json_encode(['push_id' => $pushId, 'changes' => $changes], JsonResponder::FLAGS),
        ], ['status' => 'open', 'period_end' => $now, 'summary' => mb_substr("Price push #$pushId $state: " . (string) $error, 0, 255)])->execute();
        $this->logger->warning('Price push not verified', ['station_id' => $stationId, 'state' => $state, 'error' => $error]);

        return ['state' => $state, 'message' => "Push $state: " . (string) $error];
    }

    private function mainControllerId(int $stationId): ?int
    {
        $row = $this->controllerRowOrNull($stationId);

        return $row === null ? null : (int) $row['id'];
    }

    /** @return array<string, mixed>|null The station's collected controller, else its primary, else the first. */
    private function controllerRowOrNull(int $stationId): ?array
    {
        /** @var array<string, mixed>|null */
        return (new Query($this->db))
            ->select(['c.*', 'utc_offset_minutes' => 's.utc_offset_minutes'])
            ->from(['c' => 'controllers'])
            ->innerJoin(['s' => 'stations'], 's.id = c.station_id')
            ->where(['c.station_id' => $stationId, 'c.odoo_active' => 1])
            ->orderBy(['c.enabled' => SORT_DESC, 'c.is_primary' => SORT_DESC, 'c.id' => SORT_ASC])
            ->one();
    }

    /** @return array<string, mixed> */
    private function controllerRow(int $stationId): array
    {
        return $this->controllerRowOrNull($stationId) ?? throw new RuntimeException('This station has no active PTS controller.');
    }

    /** @return list<array{grade_id: int, name: string, price: float}> */
    private static function grades(mixed $reply): array
    {
        $data = is_array($reply) ? ($reply['Data'] ?? null) : null;
        $list = is_array($data) ? ($data['FuelGrades'] ?? null) : null;
        if (!is_array($list)) {
            return [];
        }
        $grades = [];
        foreach ($list as $grade) {
            if (!is_array($grade)) {
                continue;
            }
            $id = $grade['Id'] ?? null;
            $name = $grade['Name'] ?? null;
            $price = $grade['Price'] ?? null;
            if (is_int($id) && is_string($name) && is_numeric($price)) {
                $grades[] = ['grade_id' => $id, 'name' => $name, 'price' => (float) $price];
            }
        }

        return $grades;
    }

    private static function many2oneId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_int($value) ? $value : null;
    }
}
