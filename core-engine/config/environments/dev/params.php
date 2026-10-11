<?php

declare(strict_types=1);

return [
    'traceLink' => 'phpstorm://open?url=file://{file}&line={line}',

    // Compiled routes are cached in runtime/cache and never invalidated automatically; keep it off outside prod.
    'yiisoft/router-fastroute' => [
        'enableCache' => false,
    ],
];
