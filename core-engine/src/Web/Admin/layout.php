<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * Admin dashboard layout.
 *
 * @var string $content
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Router\CurrentRoute $currentRoute
 * @var Yiisoft\Router\UrlGeneratorInterface $urlGenerator
 */

$params = $this->getParameter('adminNav', []);
$stations = $params['collecting'] ?? [];
$active = $params['active'] ?? '';
$refresh = $params['refresh'] ?? true;
$flash = $params['flash'] ?? null;

$this->beginPage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Fmt::e($this->getTitle()) ?> · FuelPay Mediator</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        :root {
            --bg: #f4f6f8; --surface: #fff; --fg: #18222a; --muted: #5f6c75; --line: #dde3e7; --accent: #0f6e8c;
            --ok: #2f7d4f; --ok-bg: #e5f3ea; --warn: #9a5d06; --warn-bg: #fbf0dc; --bad: #b3372b; --bad-bg: #f9e4e1;
            --info: #2d5d9b; --info-bg: #e5eefa; --idle: #6b7780; --idle-bg: #eef1f3;
            --mono: "JetBrains Mono", ui-monospace, Consolas, monospace;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #11171b; --surface: #182126; --fg: #e3e9ec; --muted: #93a2aa; --line: #29343a; --accent: #4fb3d4;
                --ok: #6cc28f; --ok-bg: #16281e; --warn: #e3a64a; --warn-bg: #2c2214; --bad: #ef7f72; --bad-bg: #2f1a17;
                --info: #8ab4ea; --info-bg: #17233a; --idle: #93a2aa; --idle-bg: #1f292e;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 15px/1.45 "Segoe UI", system-ui, sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        header.top { background: var(--surface); border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 5; }
        header.top .in { max-width: 1400px; margin: 0 auto; padding: 10px 16px; display: flex; gap: 18px; align-items: center; flex-wrap: wrap; }
        .brand { font-weight: 700; letter-spacing: .02em; }
        nav { display: flex; gap: 4px; flex-wrap: wrap; flex: 1; }
        nav a { padding: 6px 10px; border-radius: 8px; color: var(--fg); }
        nav a.on { background: var(--info-bg); color: var(--info); font-weight: 600; }
        .refresh { color: var(--muted); font-size: 13px; display: flex; gap: 8px; align-items: center; }
        .refresh button { border: 1px solid var(--line); background: var(--surface); color: var(--fg); border-radius: 6px; padding: 3px 8px; cursor: pointer; }
        main { max-width: 1400px; margin: 0 auto; padding: 18px 16px 48px; display: grid; gap: 18px; }
        h1 { margin: 0; font-size: 22px; } h2 { margin: 0 0 10px; font-size: 16px; }
        .muted { color: var(--muted); } .small { font-size: 13px; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: baseline; }
        .grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 14px; min-width: 0; }
        .kpi .v { font: 600 22px/1.2 var(--mono); }
        .kpi .k { color: var(--muted); font-size: 13px; }
        .frame { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; font-size: 14px; }
        th, td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--line); vertical-align: top; white-space: nowrap; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }
        tr:last-child td { border-bottom: 0; }
        td.n, th.n { text-align: right; font-family: var(--mono); font-variant-numeric: tabular-nums; }
        td.wrap { white-space: normal; min-width: 220px; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .pill.ok { color: var(--ok); background: var(--ok-bg); } .pill.warn { color: var(--warn); background: var(--warn-bg); }
        .pill.bad { color: var(--bad); background: var(--bad-bg); } .pill.info { color: var(--info); background: var(--info-bg); }
        .pill.idle { color: var(--idle); background: var(--idle-bg); }
        .tabs { display: flex; gap: 6px; } .tabs a { padding: 4px 10px; border: 1px solid var(--line); border-radius: 999px; font-size: 13px; color: var(--fg); }
        .tabs a.on { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn { display: inline-block; border: 1px solid var(--line); background: var(--surface); color: var(--fg); border-radius: 7px; padding: 5px 11px; font: inherit; font-size: 13px; cursor: pointer; }
        .btn:hover { border-color: var(--accent); color: var(--accent); }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn.danger:hover { border-color: var(--bad); color: var(--bad); }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        form.inline { display: inline; margin: 0; }
        .actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .flash { border-radius: 10px; padding: 10px 14px; border: 1px solid var(--line); }
        .flash.ok { background: var(--ok-bg); color: var(--ok); } .flash.bad { background: var(--bad-bg); color: var(--bad); }
        tr.inactive td { opacity: .55; }
        .pumps { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); }
        .pump { border-left: 4px solid var(--idle); }
        .pump.filling { border-left-color: var(--info); } .pump.nozzle { border-left-color: var(--warn); }
        .pump.offline { border-left-color: var(--bad); opacity: .8; }
        .pump .no { font: 700 18px var(--mono); }
        .pump .live { font: 600 16px var(--mono); }
        details > summary { cursor: pointer; font-weight: 600; padding: 4px 0; }
        .empty { color: var(--muted); padding: 10px 0; }
        form.filter { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        form.filter select, form.filter button { padding: 4px 8px; border-radius: 6px; border: 1px solid var(--line); background: var(--surface); color: var(--fg); }
    </style>
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>
<header class="top">
    <div class="in">
        <span class="brand">FuelPay Mediator</span>
        <nav>
            <a href="/" class="<?= $active === 'network' ? 'on' : '' ?>">Network</a>
            <?php foreach ($stations as $s): ?>
                <a href="/station/<?= (int) $s['id'] ?>" class="<?= $active === 'station:' . $s['id'] ? 'on' : '' ?>"><?= Fmt::e($s['name']) ?></a>
            <?php endforeach; ?>
            <a href="/devices" class="<?= $active === 'devices' ? 'on' : '' ?>">Devices</a>
            <a href="/issues" class="<?= $active === 'issues' ? 'on' : '' ?>">Issues</a>
            <a href="/traffic" class="<?= $active === 'traffic' ? 'on' : '' ?>">Traffic</a>
        </nav>
        <?php if ($refresh): ?>
            <span class="refresh"><span id="rf-label">Auto-refresh 15 s</span><button type="button" id="rf-toggle">Pause</button></span>
        <?php endif; ?>
    </div>
</header>
<main>
    <?php if (is_array($flash)): ?>
        <div class="flash <?= Fmt::e($flash['tone'] ?? 'ok') ?>"><?= Fmt::e($flash['text'] ?? '') ?></div>
    <?php endif; ?>
    <?= $content ?>
</main>
<?php if ($refresh): ?>
<script>
(() => {
    const key = 'fm-autorefresh';
    let on = true;
    try { on = localStorage.getItem(key) !== 'off'; } catch (e) {}
    const btn = document.getElementById('rf-toggle'), label = document.getElementById('rf-label');
    // A pressed action button shows progress and stops the page from reloading under it.
    document.querySelectorAll('form[data-action]').forEach(f => f.addEventListener('submit', () => {
        on = false; clearTimeout(timer);
        f.querySelectorAll('button').forEach(b => { b.disabled = true; b.textContent = b.dataset.busy || 'Working…'; });
    }));
    let timer = null;
    const apply = () => {
        btn.textContent = on ? 'Pause' : 'Resume';
        label.textContent = on ? 'Auto-refresh 15 s' : 'Auto-refresh paused';
        clearTimeout(timer);
        if (on) timer = setTimeout(() => location.reload(), 15000);
    };
    btn.addEventListener('click', () => { on = !on; try { localStorage.setItem(key, on ? 'on' : 'off'); } catch (e) {} apply(); });
    apply();
})();
</script>
<?php endif; ?>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
