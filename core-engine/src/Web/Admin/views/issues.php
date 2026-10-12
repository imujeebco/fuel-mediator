<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var list<array<string, mixed>> $issues
 * @var array<string, int> $counts
 * @var string|null $type
 * @var int|null $stationId
 * @var list<array<string, mixed>> $stations
 * @var Fmt $fmt
 */

$this->setTitle('Issues');
$this->setParameter('adminNav', $adminNav);
$tone = static fn(string $severity): string => match ($severity) { 'critical', 'high' => 'bad', 'warning' => 'warn', default => 'info' };
?>
<div class="row" style="justify-content: space-between">
    <div>
        <h1>Open issues</h1>
        <div class="muted small">Raised by reconciliation and fill matching. A late sale closes a "fill without sale" automatically.</div>
    </div>
    <form class="filter" method="get" action="/issues">
        <select name="station">
            <option value="">All stations</option>
            <?php foreach ($stations as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $stationId === (int) $s['id'] ? 'selected' : '' ?>><?= Fmt::e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type">
            <option value="">All types</option>
            <?php foreach ($counts as $t => $n): ?>
                <option value="<?= Fmt::e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= Fmt::e(Fmt::issueLabel($t)) ?> (<?= $n ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
    </form>
</div>

<div class="grid">
    <?php foreach ($counts as $t => $n): ?>
        <a class="card kpi" href="/issues?type=<?= Fmt::e($t) ?><?= $stationId !== null ? '&station=' . $stationId : '' ?>" style="text-decoration: none; color: inherit">
            <div class="v"><?= $n ?></div><div class="k"><?= Fmt::e(Fmt::issueLabel($t)) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<section class="card">
    <?php if ($issues === []): ?><div class="empty">No open issues.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>When</th><th>Station</th><th>Type</th><th>Severity</th><th class="n">Pump</th><th class="n">Litres</th><th class="n">SAR</th><th>Summary</th></tr></thead>
        <tbody><?php foreach ($issues as $i): ?>
            <tr>
                <td><?= $fmt->time($i['period_start'], 'd M H:i:s') ?></td>
                <td><?= Fmt::e($i['station_name']) ?></td>
                <td><?= Fmt::e(Fmt::issueLabel((string) $i['type'])) ?></td>
                <td><?= Fmt::pill((string) $i['severity'], $tone((string) $i['severity'])) ?></td>
                <td class="n"><?= Fmt::e($i['pump_number']) ?></td>
                <td class="n"><?= Fmt::num($i['volume'], 2) ?></td>
                <td class="n"><?= Fmt::num($i['amount'], 2) ?></td>
                <td class="wrap"><?= Fmt::e($i['summary']) ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
