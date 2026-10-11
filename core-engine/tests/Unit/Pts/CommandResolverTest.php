<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pts;

use App\Pts\Endpoint\CommandResolver;
use App\Shared\Audit\AuditLog;
use App\Shared\Clock;
use App\Shared\Log\Redactor;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertSame;

/**
 * Test scenarios 7 (dispense succeeded, response lost) and 10 (mediator restart with pending commands).
 */
final class CommandResolverTest extends Unit
{
    private ConnectionInterface $db;
    private int $stationId;
    private int $controllerId;
    private int $seq = 0;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
        $controller = PtsFixture::seedController($this->db, 1, 2, [1, 2]);
        $this->stationId = $controller->stationId;
        $this->controllerId = $controller->id;
    }

    /** Scenario 10: restart with commands in flight — never re-sent, never assumed failed if possibly sent. */
    public function testCrashRecoveryNeverResends(): void
    {
        $sent = $this->command('PumpAuthorize', 1, 'sent', '04:00:00');
        $queued = $this->command('PumpAuthorize', 2, 'queued', '04:00:00');
        $recent = $this->command('PumpAuthorize', 2, 'sent', '04:00:50');

        $counts = $this->resolver('04:01:10')->resolve();

        assertSame('outcome_unknown', $this->state($sent));
        assertSame('failed', $this->state($queued), 'never sent, so safe to report as failed');
        assertSame('sent', $this->state($recent), 'still within the in-flight limit');
        assertSame(1, $counts['recovered_unknown']);
        assertSame(1, $counts['recovered_failed']);
    }

    /** Scenario 7: dispensing succeeded but the authorize reply was lost. */
    public function testAuthorizeWithFollowingFillIsResolvedExecuted(): void
    {
        $cmd = $this->command('PumpAuthorize', 1, 'outcome_unknown', '04:00:00');
        $fill = $this->fill(1, '04:00:20', '04:01:30');

        $this->resolver('04:02:00')->resolve();

        assertSame('resolved_executed', $this->state($cmd));
        assertSame((string) $fill, (string) $this->scalar("SELECT resolved_by_fill_event_id FROM commands WHERE public_id = '$cmd'"));
    }

    public function testAuthorizeWithoutFillAfterObservationIsNotExecuted(): void
    {
        $cmd = $this->command('PumpAuthorize', 1, 'outcome_unknown', '04:00:00');
        $this->observe(1, '04:03:30');

        $early = $this->resolver('04:02:00')->resolve();
        assertSame(0, $early['not_executed'], 'cannot conclude before the authorize window closes');

        $this->resolver('04:04:00')->resolve();
        assertSame('resolved_not_executed', $this->state($cmd));
    }

    public function testNoEvidenceGoesToReviewWithDiscrepancy(): void
    {
        $cmd = $this->command('PumpSuspend', 1, 'outcome_unknown', '04:00:00');

        $this->resolver('04:10:00')->resolve();
        assertSame('outcome_unknown', $this->state($cmd));

        $this->resolver('04:31:00')->resolve();
        assertSame('needs_review', $this->state($cmd));
        assertSame('unknown_command_outcome', $this->scalar('SELECT type FROM discrepancies'));
    }

    public function testStopThatDidNotStopDispensingIsHighSeverityReview(): void
    {
        $stopped = $this->command('PumpStop', 1, 'outcome_unknown', '04:00:00');
        $this->fill(1, '03:59:00', '04:00:08');
        $ignored = $this->command('PumpEmergencyStop', 2, 'outcome_unknown', '04:00:00');
        $this->fill(2, '03:59:00', '04:02:00');

        $this->resolver('04:03:00')->resolve();

        assertSame('resolved_executed', $this->state($stopped));
        assertSame('needs_review', $this->state($ignored));
        assertSame('high', $this->scalar('SELECT severity FROM discrepancies'));
    }

    private function resolver(string $utc): CommandResolver
    {
        $clock = Clock::fixedAt(new DateTimeImmutable("2026-10-11 $utc", new DateTimeZone('UTC')));

        return new CommandResolver($this->db, new AuditLog($this->db, $clock, new Redactor()), $clock);
    }

    private function command(string $type, int $pump, string $state, string $sentUtc): string
    {
        $publicId = sprintf('00000000-0000-4000-8000-%012d', ++$this->seq);
        $this->db->createCommand()->insert('commands', [
            'public_id' => $publicId, 'station_id' => $this->stationId, 'controller_id' => $this->controllerId,
            'correlation_id' => 'c', 'type' => $type, 'pump_number' => $pump, 'request' => '{}', 'state' => $state,
            'received_at' => "2026-10-11 $sentUtc", 'sent_at' => $state === 'queued' ? null : "2026-10-11 $sentUtc",
        ])->execute();

        return $publicId;
    }

    private function fill(int $pump, string $startedUtc, string $endedUtc): int
    {
        $this->db->createCommand()->insert('fill_events', [
            'station_id' => $this->stationId, 'controller_id' => $this->controllerId, 'pump_number' => $pump,
            'nozzle_number' => 1, 'started_at' => "2026-10-11 $startedUtc", 'ended_at' => "2026-10-11 $endedUtc",
            'volume' => '10.000', 'amount' => '21.80', 'meter_volume_after' => (string) (1000 + ++$this->seq), 'source' => 'status_transition',
        ])->execute();

        return (int) $this->db->getLastInsertId();
    }

    private function observe(int $pump, string $utc): void
    {
        $this->db->createCommand()->insert('pump_status_cache', [
            'controller_id' => $this->controllerId, 'pump_number' => $pump, 'station_id' => $this->stationId,
            'state' => 'idle', 'packet' => '{}', 'observed_at' => "2026-10-11 $utc",
        ])->execute();
    }

    private function state(string $publicId): string
    {
        return (string) $this->scalar("SELECT state FROM commands WHERE public_id = '$publicId'");
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->createCommand($sql)->queryScalar();
    }
}
