<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * Pumped vs sold table (grade or pump lines) from a VolumeReport.
 *
 * @var App\Reconciliation\VolumeReport $report
 * @var string $first First column header
 */

$totals = $report->totals();
?>
<div class="frame">
    <table>
        <thead><tr>
            <th><?= Fmt::e($first) ?></th><th class="n">Fills</th><th class="n">Pumped</th><th class="n">Sales</th>
            <th class="n">Sold</th><th class="n">Sold SAR</th><th class="n">Pumped − sold</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($report->lines as $line): ?>
            <tr>
                <td><?= Fmt::e($line->label) ?></td>
                <td class="n"><?= $line->fills ?><?= $line->missedFills > 0 ? ' <span class="muted small">+' . $line->missedFills . ' between polls</span>' : '' ?></td>
                <td class="n"><?= Fmt::litres($line->dispensed) ?></td>
                <td class="n"><?= $line->sales ?></td>
                <td class="n"><?= Fmt::litres($line->sold) ?></td>
                <td class="n"><?= Fmt::sar($line->soldAmount) ?></td>
                <td class="n"><?= Fmt::gap($line->gap) ?></td>
                <td><?php
                    $tone = Fmt::gapTone($line->gap, $report->groupBy === 'pump' ? 5.0 : 20.0);
                    echo $line->coverage !== 'complete'
                        ? Fmt::pill('Meter gap', 'warn')
                        : Fmt::pill($tone === 'ok' ? 'Balanced' : ($tone === 'bad' ? 'Not sold' : 'Oversold'), $tone);
                ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td><strong>All</strong></td>
            <td class="n"><strong><?= (int) $totals['fills'] ?></strong></td>
            <td class="n"><strong><?= Fmt::litres($totals['dispensed']) ?></strong></td>
            <td class="n"><strong><?= (int) $totals['sales'] ?></strong></td>
            <td class="n"><strong><?= Fmt::litres($totals['sold']) ?></strong></td>
            <td></td>
            <td class="n"><strong><?= Fmt::gap($totals['gap']) ?></strong></td>
            <td><?= Fmt::pill(Fmt::gapTone($totals['gap'], 40.0) === 'ok' ? 'Balanced' : 'Check', Fmt::gapTone($totals['gap'], 40.0)) ?></td>
        </tr>
        </tbody>
    </table>
</div>
