<?php

declare(strict_types=1);

use App\Price\PriceService;
use App\Web\Admin\Fmt;

/**
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var string $csrf
 * @var list<array<string, mixed>> $stations
 * @var array<int, list<array<string, mixed>>> $rows
 * @var list<array<string, mixed>> $pushes
 * @var array<int, string> $optedIn
 * @var Fmt $fmt
 */

$this->setTitle('Prices');
$this->setParameter('adminNav', $adminNav);
$button = fn(string $url, string $label, array $opts = []): string => $this->render(__DIR__ . '/_button.php', $opts + [
    'csrf' => $csrf, 'url' => $url, 'label' => $label, 'busy' => null, 'class' => null, 'confirm' => null, 'title' => null,
]);
$stateTone = static fn(string $s): string => match ($s) { 'ok' => 'ok', 'mismatch' => 'bad', 'pump_mismatch' => 'warn', default => 'idle' };
$stateLabel = static fn(string $s): string => match ($s) {
    'ok' => 'Matches Odoo', 'mismatch' => 'Differs from Odoo', 'pump_mismatch' => 'Pump shows old price', 'no_odoo_price' => 'No Odoo price', default => $s,
};
$pushTone = static fn(string $s): string => match ($s) { 'verified' => 'ok', 'sent' => 'info', 'rejected', 'mismatch', 'failed' => 'bad', default => 'idle' };
?>
<div>
    <h1>Prices</h1>
    <div class="muted small">
        Odoo (station price lines) is the source. The controller's prices are read from the controller; pumps report the price they
        will charge; the sale column is the last price charged in the past 3 hours. <strong>Auto-push</strong> sends an Odoo change to the
        controller within about a minute and reads it back to verify. Automatic pushes never move a price by more than
        <?= (int) (PriceService::MAX_AUTO_CHANGE * 100) ?>% or outside <?= PriceService::MIN_PRICE ?>–<?= PriceService::MAX_PRICE ?> SAR/L; a manual push may exceed the percentage.
        Devices cannot set prices (D27), and devices going through the mediator authorize at the controller's current price (D26).
    </div>
</div>

<?php foreach ($stations as $s):
    $id = (int) $s['id'];
    $lines = $rows[$id] ?? [];
    $auto = ($optedIn[$id] ?? '0') === '1';
    $bad = count(array_filter($lines, static fn(array $r): bool => $r['state'] === 'mismatch'));
?>
<section class="card">
    <div class="row" style="justify-content: space-between">
        <div class="row">
            <h2 style="margin: 0"><a href="/station/<?= $id ?>"><?= Fmt::e($s['name']) ?></a></h2>
            <?= $lines === [] ? Fmt::pill('Not read yet', 'idle') : ($bad === 0 ? Fmt::pill('Prices match Odoo', 'ok') : Fmt::pill("$bad grade(s) differ", 'bad')) ?>
            <?= $auto ? Fmt::pill('Auto-push on', 'info') : Fmt::pill('Auto-push off', 'idle') ?>
        </div>
        <div class="actions">
            <?php if (!$s['secret_ok']): ?>
                <span class="muted small">no controller password yet</span>
            <?php else: ?>
                <?= $button("/actions/station/$id/prices/read", 'Read controller prices', ['busy' => 'Reading…']) ?>
                <?= $button("/actions/station/$id/prices/push", 'Push Odoo prices now', ['class' => 'primary', 'busy' => 'Pushing…',
                    'confirm' => "Send Odoo's prices to {$s['name']}'s controller now? The pumps charge the new prices from the next fill."]) ?>
                <form class="inline" method="post" action="/actions/station/<?= $id ?>/prices/auto" data-action
                      onsubmit="return confirm(<?= Fmt::e(json_encode($auto ? 'Turn automatic price pushes off for this station?' : 'Turn automatic price pushes on? Odoo price changes will be sent to this controller automatically.')) ?>)">
                    <input type="hidden" name="_csrf" value="<?= Fmt::e($csrf) ?>">
                    <input type="hidden" name="on" value="<?= $auto ? '0' : '1' ?>">
                    <button class="btn" type="submit"><?= $auto ? 'Auto-push off' : 'Auto-push on' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($lines === []): ?>
        <div class="empty">The controller's prices have not been read yet. Press "Read controller prices".</div>
    <?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>Grade</th><th class="n">Odoo</th><th>Effective</th><th class="n">Controller</th><th class="n">Pumps report</th><th class="n">Last sale</th><th>State</th><th>Read</th><th>Controller price changed</th></tr></thead>
        <tbody><?php foreach ($lines as $r): ?>
            <tr>
                <td><?= Fmt::e($r['grade']) ?> <span class="muted small">#<?= (int) $r['grade_id'] ?></span></td>
                <td class="n"><?= $r['odoo_price'] === null ? '—' : Fmt::num($r['odoo_price'], 2) ?></td>
                <td class="small"><?= Fmt::e($r['odoo_effective_date'] ?? '') ?></td>
                <td class="n"><?= Fmt::num($r['controller_price'], 2) ?></td>
                <td class="n"><?= $r['pump_prices'] === [] ? '—' : Fmt::e(implode(', ', array_unique(array_map(static fn(float $p): string => number_format($p, 2), $r['pump_prices'])))) ?></td>
                <td class="n"><?= $r['sale_price'] === null ? '—' : Fmt::num($r['sale_price'], 2) ?></td>
                <td><?= Fmt::pill($stateLabel((string) $r['state']), $stateTone((string) $r['state'])) ?></td>
                <td class="small"><?= $fmt->age($r['observed_at']) ?></td>
                <td class="small"><?= $r['price_changed_at'] ? $fmt->time($r['price_changed_at'], 'd M H:i') : '—' ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card">
    <h2>Price pushes</h2>
    <?php if ($pushes === []): ?><div class="empty">No price push yet.</div><?php else: ?>
    <div class="frame"><table>
        <thead><tr><th>When</th><th>Station</th><th>By</th><th>Result</th><th>Requested</th><th>Detail</th></tr></thead>
        <tbody><?php foreach ($pushes as $p):
            $requested = json_decode((string) $p['requested'], true);
        ?>
            <tr>
                <td><?= $fmt->time($p['created_at'], 'd M H:i:s') ?></td>
                <td><?= Fmt::e($p['station']) ?></td>
                <td><?= Fmt::e($p['triggered_by']) ?></td>
                <td><?= Fmt::pill((string) $p['state'], $pushTone((string) $p['state'])) ?></td>
                <td class="small"><?= is_array($requested) ? Fmt::e(implode(', ', array_map(static fn(array $l): string => '#' . $l['FuelGradeId'] . ' ' . number_format((float) $l['Price'], 2), $requested))) : '' ?></td>
                <td class="wrap small"><?= Fmt::e($p['error']) ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
