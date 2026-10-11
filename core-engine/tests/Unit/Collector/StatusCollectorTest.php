<?php

declare(strict_types=1);

namespace App\Tests\Unit\Collector;

use App\Collector\StatusCollector;
use App\Pts\ControllerEndpoint;
use App\Pts\PtsClient;
use App\Shared\Clock;
use App\Tests\Support\Fake\FakeHttpTransport;
use App\Tests\Support\PtsFixture;
use App\Tests\Support\TestDatabase;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertSame;

/**
 * Collector behaviour against scripted controller replies (test scenarios 18, completeness).
 */
final class StatusCollectorTest extends Unit
{
    private ConnectionInterface $db;
    private ControllerEndpoint $controller;
    private FakeHttpTransport $transport;

    protected function _before(): void
    {
        $this->db = TestDatabase::connection();
        TestDatabase::truncate($this->db);
        $this->controller = PtsFixture::seedController($this->db, 1, 2, [1]);
        $this->transport = new FakeHttpTransport();
    }

    public function testRecordsFirstFillAndSnapshotThenNothingWhileUnchanged(): void
    {
        $this->poll(PtsFixture::idle(1, 100, 1000.0, 10.0, 21.8), '06:50:00');
        $second = $this->poll(PtsFixture::idle(1, 100, 1000.0, 10.0, 21.8), '06:50:10');

        assertSame(1, $this->rows('fill_events'));
        assertSame(1, $this->rows('meter_snapshots'), 'unchanged meter writes no snapshot until the heartbeat is due');
        assertSame(0, $second->fills);
        assertSame('2026-10-11 03:40:16.000', $this->value('SELECT ended_at FROM fill_events'), 'controller local time stored as UTC');
    }

    public function testTransactionGapCountsMissedFillsAndTheirVolume(): void
    {
        $this->poll(PtsFixture::idle(1, 100, 1000.0, 10.0, 21.8), '06:50:00');
        // Three fills happened between polls (tx 101, 102, 103); we only see the last one (20 L).
        $result = $this->poll(PtsFixture::idle(1, 103, 1050.0, 20.0, 43.6), '06:55:00');

        assertSame(1, $result->fills);
        assertSame(2, $result->missedFills);
        assertSame('2', (string) $this->value('SELECT preceding_missed_count FROM fill_events WHERE pts_transaction_no = 103'));
        assertSame('30.000', (string) $this->value('SELECT preceding_missed_volume FROM fill_events WHERE pts_transaction_no = 103'));
    }

    public function testStatusWithoutMeterIsDeferredUntilMeterIsReported(): void
    {
        $this->poll(PtsFixture::idle(1, 100, 1000.0, 10.0, 21.8), '06:50:00');
        $deferred = $this->poll(PtsFixture::idle(1, 101, null, 5.0, 10.9), '06:51:00');
        assertSame(0, $deferred->fills);
        assertSame('100', (string) $this->value('SELECT last_transaction_no FROM pump_status_cache'));

        $recorded = $this->poll(PtsFixture::idle(1, 101, 1005.0, 5.0, 10.9), '06:51:10');
        assertSame(1, $recorded->fills);
        assertSame(0, $recorded->missedFills);
        assertSame(2, $this->rows('fill_events'));
    }

    public function testFillingStateDoesNotRecordAFillAndZeroVolumeIsNotASale(): void
    {
        $this->poll(PtsFixture::idle(1, 100, 1000.0, 10.0, 21.8), '06:50:00');
        $this->poll(PtsFixture::filling(1, 101, 3.2), '06:50:10');
        $this->poll(PtsFixture::idle(1, 101, 1000.0, 0.0, 0.0), '06:50:20');

        assertSame(1, $this->rows('fill_events'));
        assertSame('101', (string) $this->value('SELECT last_transaction_no FROM pump_status_cache'), 'tx advanced so no fill is reported missed later');
    }

    public function testHeartbeatSnapshotAndMeterRegression(): void
    {
        $this->poll(PtsFixture::idle(1, 100, 1000.0, 10.0, 21.8), '06:50:00');
        $this->poll(PtsFixture::idle(1, 100, 1000.0, 10.0, 21.8), '06:55:01');
        assertSame(2, $this->rows('meter_snapshots'), 'heartbeat after 5 minutes proves coverage');

        $regression = $this->poll(PtsFixture::idle(1, 1, 5.0, 5.0, 10.9), '06:56:00');
        assertSame(1, $regression->meterRegressions);
        assertSame(0, $regression->missedFills, 'a counter reset is not reported as missed fills');
    }

    /** Re-reading the same status after a restart is idempotent (test scenario 10/18 foundation). */
    public function testReplayAfterRestartDoesNotDuplicateFills(): void
    {
        $this->poll(PtsFixture::idle(1, 100, 1000.0, 10.0, 21.8), '06:50:00');
        $this->db->createCommand()->delete('pump_status_cache')->execute(); // simulate lost in-memory/cache state

        $this->poll(PtsFixture::idle(1, 100, 1000.0, 10.0, 21.8), '06:50:30');

        assertSame(1, $this->rows('fill_events'));
    }

    /** @param array<string, mixed> $packet */
    private function poll(array $packet, string $localTime): \App\Collector\CollectResult
    {
        $this->transport->queue(PtsFixture::statusResponder(static fn(int $pump): array => $packet));
        $clock = Clock::fixedAt(new DateTimeImmutable("2026-10-11 $localTime", new \DateTimeZone('+03:00')));

        return (new StatusCollector($this->db, new PtsClient($this->transport), $clock))->collect($this->controller);
    }

    private function rows(string $table): int
    {
        return (int) $this->db->createCommand("SELECT COUNT(*) FROM $table")->queryScalar();
    }

    private function value(string $sql): mixed
    {
        return $this->db->createCommand($sql)->queryScalar();
    }
}
