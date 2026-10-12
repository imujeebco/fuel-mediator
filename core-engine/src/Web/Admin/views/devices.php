<?php

declare(strict_types=1);

use App\Dashboard\DashboardQueries;
use App\Web\Admin\Fmt;

/**
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var list<array{station: array<string, mixed>, fmt: Fmt, window: array<string, mixed>, devices: list<array<string, mixed>>}> $groups
 * @var string $windowKey
 * @var list<array<string, mixed>> $stations
 * @var int|null $only
 */

$this->setTitle('Devices');
$this->setParameter('adminNav', $adminNav);
?>
<div class="row" style="justify-content: space-between">
    <div>
        <h1>Devices</h1>
        <div class="muted small">
            Every FuelPay terminal that registered a sale in the window, plus terminals that talked to the mediator.
            <strong>Via mediator</strong> = its Odoo URL points at this mediator; <strong>Direct to Odoo</strong> = still on fuelksa.odoo.com.
            Online = activity in the last <?= DashboardQueries::ONLINE_SECONDS / 60 ?> minutes.
        </div>
    </div>
    <div class="actions">
        <form class="filter" method="get" action="/devices">
            <input type="hidden" name="w" value="<?= Fmt::e($windowKey) ?>">
            <select name="station" onchange="this.form.submit()">
                <option value="">All stations with activity</option>
                <?php foreach ($stations as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $only === (int) $s['id'] ? 'selected' : '' ?>><?= Fmt::e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?= $this->render(__DIR__ . '/_window.php', ['windowKey' => $windowKey, 'base' => '/devices']) ?>
    </div>
</div>

<?php foreach ($groups as $g):
    $fmt = $g['fmt'];
    $devices = $g['devices'];
    $online = count(array_filter($devices, static fn(array $d): bool => (bool) $d['online']));
    $via = count(array_filter($devices, static fn(array $d): bool => $d['via_mediator'] !== null));
?>
<section class="card">
    <div class="row" style="justify-content: space-between">
        <h2><?= Fmt::e($g['station']['name']) ?></h2>
        <span class="muted small"><?= $online ?> online · <?= count($devices) ?> seen · <?= $via ?> via mediator · <?= Fmt::e($g['window']['label']) ?> from <?= $fmt->time($g['window']['from'], 'd M H:i') ?></span>
    </div>
    <?php if ($devices === []): ?><div class="empty">No device activity in this window.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr>
            <th>Device</th><th>Status</th><th>Connection</th><th>Last activity</th><th>Workers</th>
            <th class="n">Sales</th><th class="n">Litres</th><th class="n">SAR</th><th class="n">Cash</th><th class="n">Card</th>
            <th class="n">With PTS tx</th><th class="n">Pending</th><th class="n">Flagged</th><th>POS config</th><th>App / IP</th>
        </tr></thead>
        <tbody><?php foreach ($devices as $d):
            $via = $d['via_mediator'];
            $sales = (int) $d['sales'];
        ?>
            <tr>
                <td><strong><?= Fmt::e($d['terminal_id']) ?></strong></td>
                <td><?= Fmt::pill($d['online'] ? 'Online' : 'Idle', $d['online'] ? 'ok' : 'idle') ?></td>
                <td><?= $via !== null ? Fmt::pill('Via mediator', 'info') : Fmt::pill('Direct to Odoo', 'idle') ?></td>
                <td><?= $fmt->time($d['last_activity_at'], 'H:i:s') ?> <span class="muted small">(<?= $fmt->age($d['last_activity_at']) ?>)</span></td>
                <td class="wrap"><?= Fmt::e($d['operators']) ?></td>
                <td class="n"><?= $sales ?></td>
                <td class="n"><?= Fmt::num($d['litres'], 1) ?></td>
                <td class="n"><?= Fmt::num($d['amount'], 2) ?></td>
                <td class="n"><?= Fmt::num($d['cash'], 2) ?></td>
                <td class="n"><?= Fmt::num($d['card'], 2) ?></td>
                <td class="n"><?= $sales > 0 ? round(100 * (int) $d['pts_tx'] / $sales) . '%' : '—' ?></td>
                <td class="n"><?= (int) $d['pending'] > 0 ? Fmt::pill((string) (int) $d['pending'], 'warn') : '0' ?></td>
                <td class="n"><?= (int) $d['issues'] > 0 ? Fmt::pill((string) (int) $d['issues'], 'bad') : '0' ?></td>
                <td><?= Fmt::e($d['configs']) ?></td>
                <td class="small"><?= $via !== null ? Fmt::e(($via['app_version'] ?? '') . ' · ' . ($via['last_ip'] ?? '')) : '—' ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
