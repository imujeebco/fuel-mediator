<?php

declare(strict_types=1);

namespace App\Sync;

use App\Odoo\OdooException;
use App\Odoo\OdooReadClientInterface;
use App\Odoo\Sync\OdooSalesReader;
use App\Odoo\Sync\OdooSessionsReader;
use App\Reconciliation\FillMatcher;
use App\Shared\Clock;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function getmypid;
use function gethostname;

/**
 * One sync pass for the whole network (or one station), shared by `yii sync:run`, the server timer and the
 * dashboard's "Run sync now" button:
 *  1. mirror the station's Odoo sales (attributed by the POS config's company, D18) and their POS sessions;
 *  2. for stations whose controller the mediator collects from, link fills to sales (feeds reports and D21).
 * Odoo and the controllers are only read. A failure at one station never stops the others.
 */
final class SyncService
{
    /** Fill matching looks back over a full 12-hour shift plus margin. */
    private const MATCH_LOOKBACK = '-13 hours';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly OdooReadClientInterface $odoo,
        private readonly OdooSalesReader $sales,
        private readonly OdooSessionsReader $sessions,
        private readonly FillMatcher $matcher,
        private readonly Clock $clock,
    ) {}

    /**
     * @param int|null $stationId One station, or every active station with an Odoo company.
     * @param int $maxPages Sales pages (500 each) per station; keep small for interactive use.
     *
     * @return list<array{station_id: int, station: string, sales: int, sessions: int, matched: ?int, unmatched: ?int, error: ?string}>
     */
    public function run(?int $stationId = null, int $maxPages = 50): array
    {
        $query = (new Query($this->db))
            ->select(['s.id', 's.name', 's.odoo_company_id', 'collecting' => new Expression('MAX(c.enabled)')])
            ->from(['s' => 'stations'])
            ->leftJoin(['c' => 'controllers'], 'c.station_id = s.id')
            ->where(['s.active' => 1])
            ->andWhere(['not', ['s.odoo_company_id' => null]])
            ->groupBy(['s.id', 's.name', 's.odoo_company_id'])
            ->orderBy(['s.name' => SORT_ASC]);
        if ($stationId !== null) {
            $query->andWhere(['s.id' => $stationId]);
        }

        $results = [];
        /** @var array{id: string, name: string, odoo_company_id: string, collecting: ?string} $station */
        foreach ($query->all() as $station) {
            $id = (int) $station['id'];
            $line = ['station_id' => $id, 'station' => $station['name'], 'sales' => 0, 'sessions' => 0, 'matched' => null, 'unmatched' => null, 'error' => null];
            try {
                $line['sales'] = $this->sales->pull($this->odoo, $id, (int) $station['odoo_company_id'], 2, $maxPages);
                $line['sessions'] = $this->sessions->pull($this->odoo, $id);
                if ((int) ($station['collecting'] ?? 0) === 1) {
                    $now = $this->clock->now();
                    $match = $this->matcher->match(
                        $id,
                        $now->modify(self::MATCH_LOOKBACK)->format('Y-m-d H:i:s.v'),
                        $now->format('Y-m-d H:i:s.v'),
                    );
                    $line['matched'] = $match->confirmed + $match->probable;
                    $line['unmatched'] = $match->unmatchedFills;
                }
                $this->heartbeat($id);
            } catch (OdooException $e) {
                $line['error'] = 'Odoo: ' . $e->getMessage();
            } catch (Throwable $e) {
                $line['error'] = $e->getMessage();
            }
            $results[] = $line;
        }

        return $results;
    }

    /** Sync heartbeat per station (dashboard System page: is the sync cron running?). */
    private function heartbeat(int $stationId): void
    {
        $now = $this->clock->now();
        $this->db->createCommand()->upsert('worker_leases', [
            'worker' => 'sync',
            'station_id' => $stationId,
            'holder' => (string) gethostname() . ':' . (string) getmypid(),
            'lease_until' => $now->modify('+2 minutes')->format('Y-m-d H:i:s.v'),
            'heartbeat_at' => $now->format('Y-m-d H:i:s.v'),
        ], true)->execute();
    }
}
