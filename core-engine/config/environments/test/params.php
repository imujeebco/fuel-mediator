<?php

declare(strict_types=1);

return [
    // Compiled routes are cached in runtime/cache and never invalidated automatically; keep it off outside prod.
    'yiisoft/router-fastroute' => [
        'enableCache' => false,
    ],
];
