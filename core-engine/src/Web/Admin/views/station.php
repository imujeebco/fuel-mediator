<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * Station page: works for every station. Meter-based sections appear only where the mediator collects.
 *
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var string $csrf
 * @var array<string, mixed> $station
 * @var Fmt $fmt
 * @var array{key: string, label: string, from: string, to: string, sessions: list<int>} $window
 * @var bool $collecting
 * @var App\Reconciliation\VolumeReport|null $volume
 * @var App\Reconciliation\VolumeReport|null $byPump
 * @var list<array<string, mixed>> $pumps
 * @var list<array<string, mixed>> $fills
 * @var array{unmatched: list<array<string, mixed>>, unlinked: list<array<string, mixed>>, settled: list<array<string, mixed>>} $exceptions
 * @var list<array<string, mixed>> $salesByGrade
 * @var list<array<string, mixed>> $sessions
 * @var list<array<string, mixed>> $devices
 * @var string|null $lastSnapshot
 */

$this->setTitle((string) $station['name']);
$this->setParameter('adminNav', $adminNav);
$sid = (int) $station['id'];
$mode = (string) ($station['mode_override'] ?? $station['mode']);
$ctrlId = $station['controller_id'];
$button = fn(string $url, string $label, array $opts = []): string => $this->render(__DIR__ . '/_button.php', $opts + [
    'csrf' => $csrf, 'url' => $url, 'label' => $label, 'busy' => null, 'class' => null, 'confirm' => null, 'title' => null,
]);
$matchLabel = static fn(?string $state): string => match ($state) {
    'sale_without_fill' => 'No fill', 'duplicate_candidate' => 'Possible duplicate', default => (string) $state,
};
?>
<div class="row" style="justify-content: space-between">
    <div>
        <h1>
            <?= Fmt::e($station['name']) ?>
            <?= $mode === 'pts' ? Fmt::pill('PTS', 'info') : Fmt::pill('Manual', 'idle') ?>
            <?= $collecting ? Fmt::pill('Collected', 'ok') : Fmt::pill('Not collected', 'idle') ?>
        </h1>
        <div class="muted small">
            <?= Fmt::e($station['odoo_company_name'] ?? '') ?> · code <?= Fmt::e($station['code']) ?> ·
            <?php if ($ctrlId !== null): ?>controller <?= Fmt::e($station['url']) ?> · last answered <?= $fmt->age($station['last_ok_at']) ?> ·<?php else: ?>no PTS controller in Odoo ·<?php endif; ?>
            <?php if ($collecting): ?>last meter reading <?= $fmt->age($lastSnapshot) ?> ·<?php endif; ?>
            <?= Fmt::e($window['label']) ?> from <?= $fmt->time($window['from'], 'd M H:i') ?>
            <?= $window['sessions'] !== [] ? '(POS sessions ' . Fmt::e(implode(', ', $window['sessions'])) . ')' : '' ?>
        </div>
    </div>
    <div class="actions">
        <?= $button("/actions/station/$sid/sync", 'Sync sales now', ['busy' => 'Syncing…']) ?>
        <?php if ($ctrlId !== null && $mode === 'pts'): ?>
            <?= $button("/actions/controller/$ctrlId/config", 'Read pump setup', ['busy' => 'Reading…', 'title' => 'Read pumps, nozzles and grades from the controller (read-only)']) ?>
            <?php if ($collecting): ?>
                <?= $button("/actions/controller/$ctrlId/disable", 'Stop collection', ['class' => 'danger', 'confirm' => 'Stop collecting meters and fills here?']) ?>
            <?php else: ?>
                <?= $button("/actions/controller/$ctrlId/enable", 'Start collection', ['class' => 'primary', 'confirm' => 'Start read-only polling of this controller?']) ?>
            <?php endif; ?>
        <?php endif; ?>
        <?= $this->render(__DIR__ . '/_window.php', ['windowKey' => $window['key'], 'base' => "/station/$sid"]) ?>
    </div>
</div>

<?php if (!$collecting): ?>
<div class="card">
    <strong>Sales only.</strong>
    <?php if ($ctrlId === null): ?>
        This station has no PTS controller in Odoo, so pumped litres cannot be measured; the figures below come from Odoo sales.
    <?php elseif (!$station['secret_ok']): ?>
        The mediator does not read this controller yet: its password is not configured
        (<code><?= Fmt::e($station['secret_ref']) ?></code> in the server environment, decision D17). Then read the pump setup and start collection.
    <?php else: ?>
        The mediator does not read this controller yet. Read the pump setup, then start collection to reconcile meters against sales.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($collecting): ?>
<section>
    <h2>Pumps, live</h2>
    <div class="pumps">
        <?php foreach ($pumps as $p):
            $d = $p['data'];
            $state = strtolower((string) ($p['state'] ?? ''));
            $stale = $p['observed_at'] === null || $fmt->ageSeconds($p['observed_at']) > 900;
            $class = $stale || str_contains($state, 'offline') ? 'offline' : (str_contains($state, 'filling') ? 'filling' : (!empty($d['NozzleUp']) ? 'nozzle' : ''));
            $f = $p['last_fill'];
            $fillTone = $f === null ? 'idle' : ($f['state'] === 'matched' ? ($f['settled'] ? 'info' : 'ok') : ($f['state'] === 'unmatched' ? 'bad' : 'warn'));
        ?>
        <div class="card pump <?= $class ?>">
            <div class="row" style="justify-content: space-between">
                <span class="no">P<?= (int) $p['pump_number'] ?></span>
                <span class="muted small"><?= Fmt::e($p['fuel_name']) ?></span>
            </div>
            <?php if ($class === 'filling'): ?>
                <div class="live"><?= Fmt::litres($d['Volume'] ?? null, 2) ?> · <?= Fmt::sar($d['Amount'] ?? null) ?></div>
                <div class="small"><?= Fmt::pill('Filling · tx ' . ($d['Transaction'] ?? '?'), 'info') ?></div>
            <?php elseif ($class === 'offline'): ?>
                <div class="small"><?= Fmt::pill($stale ? 'No status ' . $fmt->age($p['observed_at']) : 'Offline', 'bad') ?></div>
            <?php else: ?>
                <div class="small"><?= Fmt::pill($class === 'nozzle' ? 'Nozzle up' : 'Idle', $class === 'nozzle' ? 'warn' : 'idle') ?></div>
            <?php endif; ?>
            <?php if ($f !== null): ?>
                <div class="small" style="margin-top: 6px">
                    Last fill <?= $fmt->time($f['ended_at'], 'H:i') ?> · <?= Fmt::litres($f['volume'], 2) ?> · <?= Fmt::sar($f['amount']) ?><br>
                    tx <?= Fmt::e($f['pts_transaction_no']) ?> <?= Fmt::pill($f['settled'] ? 'Paid elsewhere' : Fmt::stateLabel((string) $f['state']), $fillTone) ?>
                </div>
            <?php endif; ?>
            <div class="muted small">Meter <?= Fmt::num($d['LastTotalVolume'] ?? null, 2) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card">
    <h2>Pumped vs sold by grade</h2>
    <?= $this->render(__DIR__ . '/_volume.php', ['report' => $volume, 'first' => 'Grade']) ?>
    <details style="margin-top: 10px">
        <summary>By pump</summary>
        <p class="muted small">Sales with PTS off carry a pump number typed by the worker, so pump-level differences before the PTS switch can be typing errors.</p>
        <?= $this->render(__DIR__ . '/_volume.php', ['report' => $byPump, 'first' => 'Pump']) ?>
    </details>
</section>
<?php endif; ?>

<section class="card">
    <h2>Sales by grade (Odoo)</h2>
    <?php if ($salesByGrade === []): ?><div class="empty">No approved sales in this window.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>Grade</th><th class="n">Sales</th><th class="n">Litres</th><th class="n">SAR</th><th class="n">Cash</th><th class="n">Card</th></tr></thead>
        <tbody><?php foreach ($salesByGrade as $g): ?>
            <tr><td><?= Fmt::e($g['fuel_name'] ?? '(no grade)') ?></td><td class="n"><?= (int) $g['sales'] ?></td><td class="n"><?= Fmt::num($g['litres'], 1) ?></td>
                <td class="n"><?= Fmt::num($g['amount'], 2) ?></td><td class="n"><?= Fmt::num($g['cash'], 2) ?></td><td class="n"><?= Fmt::num($g['card'], 2) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>POS sessions</h2>
    <?php if ($sessions === []): ?><div class="empty">No session mirrored for this window yet (run a sync).</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>Session</th><th>Odoo id</th><th>POS config</th><th>State</th><th>Opened</th><th>Closed</th><th class="n">Cash counted</th></tr></thead>
        <tbody><?php foreach ($sessions as $ss): ?>
            <tr><td><?= Fmt::e($ss['name']) ?></td><td><?= (int) $ss['odoo_session_id'] ?></td><td><?= Fmt::e($ss['odoo_pos_config_id']) ?></td>
                <td><?= Fmt::pill((string) $ss['state'], $ss['state'] === 'closed' ? 'idle' : 'ok') ?></td>
                <td><?= $fmt->time($ss['start_at'], 'd M H:i') ?></td><td><?= $fmt->time($ss['stop_at'], 'd M H:i') ?></td>
                <td class="n"><?= Fmt::num($ss['cash_register_balance_end_real'], 2) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($collecting): ?>
<section class="card">
    <h2>Fills without a sale (<?= count($exceptions['unmatched']) ?>)</h2>
    <?php if ($exceptions['unmatched'] === []): ?><div class="empty">None in this window.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>Ended</th><th class="n">Pump</th><th>Grade</th><th class="n">Litres</th><th class="n">SAR</th><th>PTS tx</th></tr></thead>
        <tbody><?php foreach ($exceptions['unmatched'] as $u): ?>
            <tr><td><?= $fmt->time($u['ended_at']) ?></td><td class="n"><?= (int) $u['pump_number'] ?></td><td><?= Fmt::e($u['fuel_name']) ?></td>
                <td class="n"><?= Fmt::num($u['volume'], 2) ?></td><td class="n"><?= Fmt::num($u['amount'], 2) ?></td><td><?= Fmt::e($u['pts_transaction_no']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= $collecting ? 'Sales without a fill, possible duplicates and pending sales' : 'Pending sales' ?> (<?= count($exceptions['unlinked']) ?>)</h2>
    <?php if ($exceptions['unlinked'] === []): ?><div class="empty">None in this window.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>Registered</th><th class="n">Pump</th><th>Grade</th><th class="n">Litres</th><th class="n">SAR</th><th>PTS tx</th><th>Status</th><th>Method</th><th>Device</th><th>Worker</th></tr></thead>
        <tbody><?php foreach ($exceptions['unlinked'] as $u): ?>
            <tr><td><?= $fmt->time($u['sale_created_at']) ?></td><td class="n"><?= (int) $u['pump_number'] ?></td><td><?= Fmt::e($u['fuel_name']) ?></td>
                <td class="n"><?= Fmt::num($u['volume'], 2) ?></td><td class="n"><?= Fmt::num($u['amount'], 2) ?></td><td><?= Fmt::e($u['pts_transaction_id']) ?></td>
                <td><?= $u['payment_status'] === 'PENDING' ? Fmt::pill('Pending', 'warn') : Fmt::pill($matchLabel($u['match_state']), $u['match_state'] === 'duplicate_candidate' ? 'bad' : 'warn') ?></td>
                <td><?= Fmt::e($u['payment_method']) ?></td><td><?= Fmt::e($u['terminal_id']) ?></td><td><?= Fmt::e($u['operator_name']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($collecting): ?>
<section class="card">
    <h2>Fills reported to devices as already paid (<?= count($exceptions['settled']) ?>)</h2>
    <p class="muted small">Decision D21: the fill was paid by another sale record (manual, wrong pump or wrong transaction number), so devices no longer ask for it again.</p>
    <?php if ($exceptions['settled'] === []): ?><div class="empty">None in this window. Devices must reach Odoo through the mediator for this to apply.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>Fill ended</th><th class="n">Pump</th><th>PTS tx</th><th class="n">SAR</th><th>Paid as</th><th>Method</th><th>Device</th><th class="n">Times applied</th><th>Last</th></tr></thead>
        <tbody><?php foreach ($exceptions['settled'] as $s): ?>
            <tr><td><?= $fmt->time($s['ended_at']) ?></td><td class="n"><?= (int) $s['pump_number'] ?></td><td><?= Fmt::e($s['pts_transaction_no']) ?></td>
                <td class="n"><?= Fmt::num($s['amount'], 2) ?></td><td>pump <?= Fmt::e($s['sale_pump']) ?><?= $s['sale_tx'] ? ' · tx ' . Fmt::e($s['sale_tx']) : ' · no tx' ?></td>
                <td><?= Fmt::e($s['payment_method']) ?></td><td><?= Fmt::e($s['terminal_id']) ?></td><td class="n"><?= (int) $s['applied_count'] ?></td><td><?= $fmt->age($s['last_applied_at']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Recent fills (<?= count($fills) ?>)</h2>
    <div class="frame"><table>
        <thead><tr><th>Ended</th><th class="n">Pump</th><th>Grade</th><th>PTS tx</th><th class="n">Litres</th><th class="n">SAR</th><th>State</th><th>Matched sale</th><th>Device</th><th>Worker</th></tr></thead>
        <tbody><?php foreach ($fills as $f):
            $tone = $f['settled'] ? 'info' : ($f['state'] === 'matched' ? ($f['confidence'] === 'probable' ? 'warn' : 'ok') : ($f['state'] === 'unmatched' ? 'bad' : 'idle'));
            $label = $f['settled'] ? 'Paid elsewhere' : ($f['state'] === 'matched' && $f['confidence'] === 'probable' ? 'Paid (probable)' : Fmt::stateLabel((string) $f['state']));
        ?>
            <tr>
                <td><?= $fmt->time($f['ended_at']) ?></td><td class="n"><?= (int) $f['pump_number'] ?></td><td><?= Fmt::e($f['fuel_name']) ?></td>
                <td><?= Fmt::e($f['pts_transaction_no']) ?><?= (int) $f['preceding_missed_count'] > 0 ? ' <span class="muted small">(' . (int) $f['preceding_missed_count'] . ' missed before)</span>' : '' ?></td>
                <td class="n"><?= Fmt::num($f['volume'], 2) ?></td><td class="n"><?= Fmt::num($f['amount'], 2) ?></td>
                <td><?= Fmt::pill($label, $tone) ?></td>
                <td><?php if ($f['sale_at'] !== null): ?>
                    <?= $fmt->time($f['sale_at']) ?> · <?= Fmt::e($f['payment_method']) ?> <?= Fmt::num($f['sale_amount'], 2) ?>
                    <span class="muted small">by <?= Fmt::e($f['method'] === 'tx_id' ? 'tx number' : 'amount + litres') ?><?= (int) $f['sale_pump'] !== (int) $f['pump_number'] ? ', sale says pump ' . (int) $f['sale_pump'] : '' ?></span>
                <?php endif; ?></td>
                <td><?= Fmt::e($f['terminal_id']) ?></td><td><?= Fmt::e($f['operator_name']) ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
</section>
<?php endif; ?>

<section class="card">
    <h2>Devices (<?= count($devices) ?>)</h2>
    <?php if ($devices === []): ?><div class="empty">No device activity in this window.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>Device</th><th>Status</th><th>Connection</th><th>Last activity</th><th>Workers</th><th class="n">Sales</th><th class="n">SAR</th><th class="n">Pending</th><th class="n">Flagged</th></tr></thead>
        <tbody><?php foreach ($devices as $d): ?>
            <tr><td><strong><?= Fmt::e($d['terminal_id']) ?></strong></td>
                <td><?= Fmt::pill($d['online'] ? 'Online' : 'Idle', $d['online'] ? 'ok' : 'idle') ?></td>
                <td><?= $d['via_mediator'] !== null ? Fmt::pill('Via mediator', 'info') : Fmt::pill('Direct to Odoo', 'idle') ?></td>
                <td><?= $fmt->time($d['last_activity_at']) ?> <span class="muted small">(<?= $fmt->age($d['last_activity_at']) ?>)</span></td>
                <td class="wrap"><?= Fmt::e($d['operators']) ?></td><td class="n"><?= (int) $d['sales'] ?></td><td class="n"><?= Fmt::num($d['amount'], 2) ?></td>
                <td class="n"><?= (int) $d['pending'] ?></td><td class="n"><?= (int) $d['issues'] ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
