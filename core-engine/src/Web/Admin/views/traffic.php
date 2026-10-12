<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var array{relay: list<array<string, mixed>>, commands: list<array<string, mixed>>, errors: list<array<string, mixed>>, failedCommands: list<array<string, mixed>>} $traffic
 * @var int $minutes
 * @var Fmt $fmt
 */

$this->setTitle('Traffic');
$this->setParameter('adminNav', $adminNav);
$outcomeTone = static fn(string $o): string => match ($o) { 'ok' => 'ok', 'denied' => 'warn', default => 'bad' };
$stateTone = static fn(string $s): string => match ($s) {
    'confirmed', 'resolved_executed' => 'ok', 'resolved_not_executed', 'received', 'queued', 'sent' => 'idle', default => 'bad',
};
$total = array_sum(array_map(static fn(array $r): int => (int) $r['n'], $traffic['relay']));
?>
<div class="row" style="justify-content: space-between">
    <div>
        <h1>Traffic</h1>
        <div class="muted small">Device calls relayed to Odoo and pump commands sent to controllers. Only devices pointed at the mediator appear here.</div>
    </div>
    <div class="tabs">
        <?php foreach ([15 => '15 min', 60 => '1 h', 240 => '4 h', 1440 => '24 h'] as $m => $label): ?>
            <a href="/traffic?m=<?= $m ?>" class="<?= $minutes === $m ? 'on' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<section class="card">
    <h2>Odoo relay (<?= $total ?> calls)</h2>
    <?php if ($traffic['relay'] === []): ?><div class="empty">No relayed calls in this period. Devices are not pointed at the mediator yet.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>Model</th><th>Method</th><th>Outcome</th><th class="n">Calls</th><th class="n">Avg ms</th><th class="n">Max ms</th><th>Last</th></tr></thead>
        <tbody><?php foreach ($traffic['relay'] as $r): ?>
            <tr><td><?= Fmt::e($r['model']) ?></td><td><?= Fmt::e($r['method']) ?></td><td><?= Fmt::pill((string) $r['outcome'], $outcomeTone((string) $r['outcome'])) ?></td>
                <td class="n"><?= (int) $r['n'] ?></td><td class="n"><?= Fmt::num($r['avg_ms']) ?></td><td class="n"><?= Fmt::num($r['max_ms']) ?></td><td><?= $fmt->age($r['last_at']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Pump commands</h2>
    <?php if ($traffic['commands'] === []): ?><div class="empty">No pump commands in this period.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>Command</th><th>State</th><th class="n">Count</th><th>Last</th></tr></thead>
        <tbody><?php foreach ($traffic['commands'] as $c): ?>
            <tr><td><?= Fmt::e($c['type']) ?></td><td><?= Fmt::pill((string) $c['state'], $stateTone((string) $c['state'])) ?></td><td class="n"><?= (int) $c['n'] ?></td><td><?= $fmt->age($c['last_at']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Relay errors (<?= count($traffic['errors']) ?>)</h2>
    <?php if ($traffic['errors'] === []): ?><div class="empty">None.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>When</th><th>Call</th><th>Outcome</th><th class="n">ms</th><th>Message</th><th>Correlation</th></tr></thead>
        <tbody><?php foreach ($traffic['errors'] as $e): ?>
            <tr><td><?= $fmt->time($e['created_at']) ?></td><td><?= Fmt::e(($e['model'] ? $e['model'] . '.' : '') . $e['method']) ?></td>
                <td><?= Fmt::pill((string) $e['outcome'], $outcomeTone((string) $e['outcome'])) ?></td><td class="n"><?= Fmt::num($e['latency_ms']) ?></td>
                <td class="wrap"><?= Fmt::e($e['error_message']) ?></td><td class="small"><?= Fmt::e($e['correlation_id']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Pump commands needing attention (<?= count($traffic['failedCommands']) ?>)</h2>
    <?php if ($traffic['failedCommands'] === []): ?><div class="empty">None.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>When</th><th>Command</th><th class="n">Pump</th><th>State</th><th>Error</th><th>Reference</th></tr></thead>
        <tbody><?php foreach ($traffic['failedCommands'] as $c): ?>
            <tr><td><?= $fmt->time($c['received_at']) ?></td><td><?= Fmt::e($c['type']) ?></td><td class="n"><?= Fmt::e($c['pump_number']) ?></td>
                <td><?= Fmt::pill((string) $c['state'], $stateTone((string) $c['state'])) ?></td><td class="wrap"><?= Fmt::e($c['error']) ?></td><td class="small"><?= Fmt::e($c['public_id']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
