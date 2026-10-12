<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var list<array<string, mixed>> $cards
 * @var string $windowKey
 */

$this->setTitle('Overview');
$this->setParameter('adminNav', $adminNav);
?>
<div class="row" style="justify-content: space-between">
    <h1>Overview</h1>
    <?= $this->render(__DIR__ . '/_window.php', ['windowKey' => $windowKey, 'base' => '/']) ?>
</div>

<?php if ($cards === []): ?>
    <div class="card empty">No station is enabled on this mediator yet (<code>yii controller enable</code>).</div>
<?php endif; ?>

<?php foreach ($cards as $card):
    /** @var Fmt $fmt */
    $fmt = $card['fmt'];
    $s = $card['station'];
    $ctrlAge = $fmt->ageSeconds($s['last_ok_at']);
    $snapAge = $fmt->ageSeconds($card['lastSnapshot']);
    $circuitOpen = $s['circuit_open_until'] !== null && $fmt->ageSeconds($s['circuit_open_until']) === 0;
    $ctrlTone = $ctrlAge !== null && $ctrlAge < 120 && !$circuitOpen ? 'ok' : ($ctrlAge !== null && $ctrlAge < 900 ? 'warn' : 'bad');
    $snapTone = $snapAge !== null && $snapAge < 420 ? 'ok' : 'bad';
    $filling = 0;
    $offline = 0;
    foreach ($card['pumps'] as $p) {
        $state = (string) ($p['state'] ?? '');
        $filling += str_contains(strtolower($state), 'filling') ? 1 : 0;
        $offline += $p['observed_at'] === null || $fmt->ageSeconds($p['observed_at']) > 900 || str_contains(strtolower($state), 'offline') ? 1 : 0;
    }
    $totals = $card['volume']->totals();
    $ex = $card['exceptions'];
    $unmatchedSar = array_sum(array_map(static fn(array $f): float => (float) $f['amount'], $ex['unmatched']));
    $duplicates = count(array_filter($ex['unlinked'], static fn(array $r): bool => $r['match_state'] === 'duplicate_candidate'));
    $pending = count(array_filter($ex['unlinked'], static fn(array $r): bool => $r['payment_status'] === 'PENDING'));
?>
<section class="card" style="display: grid; gap: 14px">
    <div class="row" style="justify-content: space-between">
        <div class="row">
            <h2 style="margin: 0"><a href="/station/<?= (int) $s['id'] ?>?w=<?= Fmt::e($windowKey) ?>"><?= Fmt::e($s['name']) ?></a></h2>
            <?= Fmt::pill('Controller ' . ($ctrlTone === 'ok' ? 'online' : ($ctrlTone === 'warn' ? 'slow' : 'unreachable')), $ctrlTone) ?>
            <?= Fmt::pill('Collector ' . ($snapTone === 'ok' ? 'running' : 'stopped'), $snapTone) ?>
        </div>
        <span class="muted small">
            <?= Fmt::e($card['window']['label']) ?> from <?= $fmt->time($card['window']['from'], 'd M H:i') ?>
            <?= $card['window']['sessions'] !== [] ? '· POS sessions ' . Fmt::e(implode(', ', $card['window']['sessions'])) : '' ?>
        </span>
    </div>

    <div class="grid">
        <div class="card kpi"><div class="v"><?= Fmt::litres($totals['dispensed'], 0) ?></div><div class="k">Pumped (controller meters)</div></div>
        <div class="card kpi"><div class="v"><?= Fmt::litres($totals['sold'], 0) ?></div><div class="k">Sold (Odoo)</div></div>
        <div class="card kpi"><div class="v" style="color: var(--<?= Fmt::gapTone($totals['gap'], 40.0) ?>)"><?= Fmt::gap($totals['gap']) ?></div><div class="k">Pumped − sold</div></div>
        <div class="card kpi"><div class="v"><?= $filling ?> / <?= count($card['pumps']) ?></div><div class="k">Pumps filling now<?= $offline > 0 ? " · $offline not reporting" : '' ?></div></div>
        <div class="card kpi"><div class="v"><a href="/devices"><?= $card['devicesOnline'] ?> / <?= $card['devicesTotal'] ?></a></div><div class="k">Devices online · <?= $card['devicesViaMediator'] ?> via mediator</div></div>
        <div class="card kpi"><div class="v" style="color: var(--<?= $ex['unmatched'] === [] ? 'ok' : 'bad' ?>)"><?= count($ex['unmatched']) ?></div><div class="k">Fills without sale · <?= Fmt::sar($unmatchedSar) ?></div></div>
        <div class="card kpi"><div class="v" style="color: var(--<?= $duplicates === 0 ? 'ok' : 'warn' ?>)"><?= $duplicates ?></div><div class="k">Possible duplicate sales</div></div>
        <div class="card kpi"><div class="v"><?= count($ex['settled']) ?></div><div class="k">Fills reported already paid (D21)</div></div>
    </div>

    <div>
        <h2>Pumped vs sold by grade</h2>
        <?= $this->render(__DIR__ . '/_volume.php', ['report' => $card['volume'], 'first' => 'Grade']) ?>
    </div>

    <div class="row muted small">
        <span>Controller last answered <?= $fmt->age($s['last_ok_at']) ?><?= $s['last_error'] ? ' · last error: ' . Fmt::e(mb_substr((string) $s['last_error'], 0, 90)) . ' (' . $fmt->age($s['last_error_at']) . ')' : '' ?></span>
        <span>· Last meter reading <?= $fmt->age($card['lastSnapshot']) ?></span>
        <span>· Pending sales <?= $pending ?></span>
        <span>· Open issues <a href="/issues?station=<?= (int) $s['id'] ?>"><?= array_sum($card['issueCounts']) ?></a></span>
    </div>
</section>
<?php endforeach; ?>
