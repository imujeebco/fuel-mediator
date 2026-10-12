<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * @var Yiisoft\View\WebView $this
 * @var array $adminNav
 * @var string $csrf
 * @var string|null $error
 * @var string $next
 * @var bool $configured
 * @var string $user
 */

$this->setTitle('Sign in');
$this->setParameter('adminNav', $adminNav);
?>
<section class="card" style="max-width: 420px; margin: 40px auto; display: grid; gap: 12px">
    <h1>Sign in</h1>
    <?php if (!$configured): ?>
        <p class="muted small">No admin password is configured yet. From this machine the dashboard opens without login; from anywhere else,
            set <code>ADMIN_PASSWORD_HASH</code> in <code>core-engine/.env</code> first (<a href="/login/password-hash">generate the line</a>).</p>
    <?php endif; ?>
    <?php if ($error !== null): ?><div class="flash bad"><?= Fmt::e($error) ?></div><?php endif; ?>
    <form method="post" action="/login" style="display: grid; gap: 10px">
        <input type="hidden" name="_csrf" value="<?= Fmt::e($csrf) ?>">
        <input type="hidden" name="next" value="<?= Fmt::e($next) ?>">
        <label>User<br><input name="user" value="<?= Fmt::e($user) ?>" autocomplete="username" required style="width: 100%; padding: 7px"></label>
        <label>Password<br><input name="password" type="password" autocomplete="current-password" required style="width: 100%; padding: 7px"></label>
        <button class="btn primary" type="submit">Sign in</button>
    </form>
</section>
