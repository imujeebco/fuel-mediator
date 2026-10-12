<?php

declare(strict_types=1);

/**
 * Window selector tabs.
 *
 * @var string $windowKey
 * @var string $base URL path without query
 */

$labels = ['shift' => 'Current shift', 'today' => 'Today', '12h' => 'Last 12 h'];
?>
<div class="tabs">
    <?php foreach ($labels as $key => $label): ?>
        <a href="<?= htmlspecialchars($base) ?>?w=<?= $key ?>" class="<?= $windowKey === $key ? 'on' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>
