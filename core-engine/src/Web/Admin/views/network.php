<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var string $csrf
 * @var list<array<string, mixed>> $stations
 * @var array<int, array<string, mixed>> $sales
 * @var array<int, list<array<string, mixed>>> $sessions
 * @var array<int, int> $issues
 * @var array<int, string> $snapshots
 * @var array<int, App\Reconciliation\VolumeReport> $volumes
 * @var bool $showInactive
 * @var Fmt $fmt
 */

$this->setTitle('Network');
$this->setParameter('adminNav', $adminNav);
$button = fn(string $url, string $label, array $opts = []): string => $this->render(__DIR__ . '/_button.php', $opts + [
    'csrf' => $csrf, 'url' => $url, 'label' => $label, 'busy' => null, 'class' => null, 'confirm' => null, 'title' => null,
]);

$active = array_filter($stations, static fn(array $s): bool => (int) $s['active'] === 1);
$pts = array_filter($active, static fn(array $s): bool => ($s['mode_override'] ?? $s['mode']) === 'pts');
$collecting = array_filter($active, static fn(array $s): bool => (bool) $s['collecting']);
$tSales = $tLitres = $tAmount = $tOnline = $tPending = $tFlagged = 0;
foreach ($sales as $row) {
    $tSales += (int) $row['sales'];
    $tLitres += (float) $row['litres'];
    $tAmount += (float) $row['amount'];
    $tOnline += (int) $row['devices_online'];
    $tPending += (int) $row['pending'];
    $tFlagged += (int) $row['flagged'];
}
$tGap = 0.0;
foreach ($volumes as $report) {
    $tGap += (float) $report->totals()['gap'];
}
?>
<div class="row" style="justify-content: space-between">
    <div>
        <h1>Network</h1>
        <div class="muted small">All stations from Odoo. PTS / manual mode comes from each station's Odoo PTS configuration. Sales are today's since 00:00 station time, mirrored from Odoo by the sync job.</div>
    </div>
    <div class="actions">
        <?= $button('/actions/stations/import', 'Sync stations from Odoo', ['busy' => 'Reading Odoo…', 'title' => 'Refresh stations, PTS mode, controllers and pumps from Odoo']) ?>
        <?= $button('/actions/sync', 'Run sync now', ['class' => 'primary', 'busy' => 'Syncing…', 'title' => 'Pull sales and sessions for every station, then match fills']) ?>
        <a class="btn" href="/?all=<?= $showInactive ? '0' : '1' ?>"><?= $showInactive ? 'Hide inactive' : 'Show inactive' ?></a>
    </div>
</div>

<div class="grid">
    <div class="card kpi"><div class="v"><?= count($active) ?></div><div class="k">Active stations · <?= count($pts) ?> PTS, <?= count($active) - count($pts) ?> manual</div></div>
    <div class="card kpi"><div class="v"><?= count($collecting) ?></div><div class="k">Collected by the mediator (meters + fills)</div></div>
    <div class="card kpi"><div class="v"><?= Fmt::num($tSales) ?></div><div class="k">Sales today</div></div>
    <div class="card kpi"><div class="v"><?= Fmt::num($tLitres) ?> L</div><div class="k">Litres sold today</div></div>
    <div class="card kpi"><div class="v"><?= Fmt::num($tAmount) ?></div><div class="k">SAR sold today</div></div>
    <div class="card kpi"><div class="v"><?= $tOnline ?></div><div class="k">Devices active (10 min)</div></div>
    <div class="card kpi"><div class="v" style="color: var(--<?= Fmt::gapTone($tGap, 40.0) ?>)"><?= Fmt::gap($tGap) ?></div><div class="k">Pumped − sold today (collected stations)</div></div>
    <div class="card kpi"><div class="v"><a href="/issues"><?= array_sum($issues) ?></a></div><div class="k">Open issues · <?= $tPending ?> pending sales</div></div>
</div>

<section class="card">
    <div class="frame"><table>
        <thead><tr>
            <th>Station</th><th>Mode</th><th>Controller</th><th>Collection</th><th>Open session</th>
            <th class="n">Sales</th><th class="n">Litres</th><th class="n">SAR</th><th class="n">Devices</th>
            <th class="n">Pumped − sold</th><th class="n">Pending</th><th class="n">Issues</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($stations as $s):
            $id = (int) $s['id'];
            $mode = (string) ($s['mode_override'] ?? $s['mode']);
            $sl = $sales[$id] ?? null;
            $open = $sessions[$id] ?? [];
            $vol = $volumes[$id] ?? null;
            $ctrlId = $s['controller_id'];
            $okAge = $fmt->ageSeconds($s['last_ok_at']);
            $snapAge = $fmt->ageSeconds($snapshots[$id] ?? null);
        ?>
            <tr class="<?= (int) $s['active'] === 1 ? '' : 'inactive' ?>">
                <td>
                    <a href="/station/<?= $id ?>"><strong><?= Fmt::e($s['name']) ?></strong></a>
                    <div class="muted small"><?= Fmt::e($s['odoo_company_name'] ?? '') ?> · code <?= Fmt::e($s['code']) ?><?= (int) $s['active'] === 1 ? '' : ' · inactive' ?></div>
                </td>
                <td><?= $mode === 'pts' ? Fmt::pill('PTS', 'info') : Fmt::pill('Manual', 'idle') ?></td>
                <td>
                    <?php if ($ctrlId === null): ?><span class="muted">none</span>
                    <?php else: ?>
                        <?= Fmt::e($s['controller_name']) ?><div class="muted small"><?= (int) $s['pumps'] ?> pumps<?= $s['config_synced_at'] ? ' · setup read ' . $fmt->age($s['config_synced_at']) : ' · setup not read' ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($ctrlId === null): ?>—
                    <?php elseif ($s['collecting']): ?>
                        <?= Fmt::pill($okAge !== null && $okAge < 120 ? 'Collecting' : 'Collecting · no answer', $okAge !== null && $okAge < 120 ? 'ok' : 'bad') ?>
                        <div class="muted small">meter <?= $fmt->age($snapshots[$id] ?? null) ?><?= $s['last_error'] && $fmt->ageSeconds($s['last_error_at']) < 1800 ? ' · ' . Fmt::e(mb_substr((string) $s['last_error'], 0, 40)) : '' ?></div>
                    <?php else: ?>
                        <?= Fmt::pill('Not collected', 'idle') ?>
                        <?php if (!$s['secret_ok']): ?><div class="muted small">password missing (<?= Fmt::e($s['secret_ref']) ?>)</div><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="small">
                    <?php foreach ($open as $o): ?>
                        <?= Fmt::e($o['name']) ?> <span class="muted">since <?= $fmt->time($o['start_at'], 'H:i') ?></span><br>
                    <?php endforeach; ?>
                    <?= $open === [] ? '<span class="muted">—</span>' : '' ?>
                </td>
                <td class="n"><?= $sl ? Fmt::num($sl['sales']) : '—' ?></td>
                <td class="n"><?= $sl ? Fmt::num($sl['litres']) : '—' ?></td>
                <td class="n"><?= $sl ? Fmt::num($sl['amount'], 2) : '—' ?></td>
                <td class="n"><?= $sl ? (int) $sl['devices_online'] . ' / ' . (int) $sl['devices'] : '—' ?></td>
                <td class="n"><?php if ($vol !== null): $g = $vol->totals()['gap']; ?><span style="color: var(--<?= Fmt::gapTone($g, 40.0) ?>)"><?= Fmt::gap($g) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td class="n"><?= $sl && (int) $sl['pending'] > 0 ? Fmt::pill((string) (int) $sl['pending'], 'warn') : '0' ?></td>
                <td class="n"><?= isset($issues[$id]) ? '<a href="/issues?station=' . $id . '">' . $issues[$id] . '</a>' : '0' ?></td>
                <td>
                    <div class="actions">
                        <?php if ((int) $s['active'] === 1): ?>
                            <?= $button("/actions/station/$id/sync", 'Sync', ['busy' => '…', 'title' => 'Pull sales and sessions now']) ?>
                        <?php endif; ?>
                        <?php if ($ctrlId !== null && (int) $s['active'] === 1 && $mode === 'pts'): ?>
                            <?= $button("/actions/controller/$ctrlId/config", 'Read setup', ['busy' => '…', 'title' => 'Read pumps, nozzles and grades from the controller (read-only)']) ?>
                            <?php if ($s['collecting']): ?>
                                <?= $button("/actions/controller/$ctrlId/disable", 'Stop', ['class' => 'danger', 'confirm' => 'Stop collecting meters and fills for ' . $s['name'] . '?']) ?>
                            <?php else: ?>
                                <?= $button("/actions/controller/$ctrlId/enable", 'Collect', ['confirm' => 'Start read-only polling of ' . $s['name'] . "'s controller?"]) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
