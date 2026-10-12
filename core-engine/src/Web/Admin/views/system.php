<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var string $csrf
 * @var list<array{group: string, check: string, ok: bool|null, detail: string}> $checks
 * @var list<string> $pending
 * @var list<string> $cron
 * @var string $revision
 */

$this->setTitle('System');
$this->setParameter('adminNav', $adminNav);
$failed = count(array_filter($checks, static fn(array $c): bool => $c['ok'] === false));
$group = null;
?>
<div class="row" style="justify-content: space-between">
    <div>
        <h1>System</h1>
        <div class="muted small">Installation health for hosts without a shell. Version <?= Fmt::e($revision) ?>.</div>
    </div>
    <div class="actions">
        <?php if ($pending !== []): ?>
            <?= $this->render(__DIR__ . '/_button.php', ['csrf' => $csrf, 'url' => '/actions/system/migrate', 'label' => 'Apply ' . count($pending) . ' migration(s)',
                'busy' => 'Applying…', 'class' => 'primary', 'confirm' => 'Apply pending database migrations now?', 'title' => implode(', ', $pending)]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="grid">
    <div class="card kpi"><div class="v" style="color: var(--<?= $failed === 0 ? 'ok' : 'bad' ?>)"><?= $failed === 0 ? 'OK' : $failed ?></div><div class="k"><?= $failed === 0 ? 'All checks pass' : 'Checks failing' ?></div></div>
    <div class="card kpi"><div class="v"><?= count($pending) ?></div><div class="k">Pending migrations</div></div>
</div>

<section class="card">
    <div class="frame"><table>
        <thead><tr><th>Area</th><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
        <tbody><?php foreach ($checks as $c): ?>
            <tr>
                <td><?= $group !== $c['group'] ? '<strong>' . Fmt::e($c['group']) . '</strong>' : '' ?></td>
                <td><?= Fmt::e($c['check']) ?></td>
                <td><?= $c['ok'] === null ? Fmt::pill('Info', 'idle') : ($c['ok'] ? Fmt::pill('OK', 'ok') : Fmt::pill('Fix', 'bad')) ?></td>
                <td class="wrap"><?= Fmt::e($c['detail']) ?></td>
            </tr>
        <?php $group = $c['group']; endforeach; ?></tbody>
    </table></div>
</section>

<section class="card">
    <h2>Cron jobs</h2>
    <p class="muted small">On hosts without long-running processes (CWP / CloudLinux), add these two jobs in the panel's cron editor (every minute).
        The collector stops itself after 55 s and the next run takes over; its lease prevents two from overlapping.</p>
    <div class="frame"><table><tbody>
        <?php foreach ($cron as $line): ?><tr><td><code><?= Fmt::e($line) ?></code></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
