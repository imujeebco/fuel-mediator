<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var string $csrf
 * @var string|null $line
 * @var string|null $error
 * @var string $user
 */

$this->setTitle('Admin password');
$this->setParameter('adminNav', $adminNav);
?>
<section class="card" style="max-width: 640px; margin: 40px auto; display: grid; gap: 12px">
    <h1>Admin password line for .env</h1>
    <p class="muted small">Type the password you want for the dashboard. The page shows the line to paste into <code>core-engine/.env</code>
        (user <code><?= Fmt::e($user) ?></code>, change it with <code>ADMIN_USER</code>). Nothing is saved here: only someone who can edit
        <code>.env</code> can use the result.</p>
    <?php if ($error !== null): ?><div class="flash bad"><?= Fmt::e($error) ?></div><?php endif; ?>
    <form method="post" action="/login/password-hash" style="display: grid; gap: 10px">
        <input type="hidden" name="_csrf" value="<?= Fmt::e($csrf) ?>">
        <label>New password (12+ characters)<br><input name="password" type="password" autocomplete="new-password" required style="width: 100%; padding: 7px"></label>
        <button class="btn primary" type="submit">Generate line</button>
    </form>
    <?php if ($line !== null): ?>
        <div class="card"><code style="word-break: break-all"><?= Fmt::e($line) ?></code></div>
        <p class="muted small">Paste it into <code>.env</code>, save, then <a href="/login">sign in</a>.</p>
    <?php endif; ?>
</section>
