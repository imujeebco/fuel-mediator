<?php

declare(strict_types=1);

use App\Web\Admin\Fmt;

/**
 * POST button for a dashboard action (CSRF token from the view injection).
 *
 * @var string $csrf
 * @var string $url
 * @var string $label
 * @var string|null $busy
 * @var string|null $class
 * @var string|null $confirm
 * @var string|null $title
 */
?>
<form class="inline" method="post" action="<?= Fmt::e($url) ?>" data-action<?= !empty($confirm) ? ' onsubmit="return confirm(' . Fmt::e(json_encode($confirm)) . ')"' : '' ?>>
    <input type="hidden" name="_csrf" value="<?= Fmt::e($csrf) ?>">
    <button type="submit" class="btn <?= Fmt::e($class ?? '') ?>" data-busy="<?= Fmt::e($busy ?? 'Working…') ?>"<?= !empty($title) ? ' title="' . Fmt::e($title) . '"' : '' ?>><?= Fmt::e($label) ?></button>
</form>
