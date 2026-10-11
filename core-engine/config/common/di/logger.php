<?php

declare(strict_types=1);

use App\Shared\Log\JsonLineFormat;
use Psr\Log\LoggerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;

/** @var array $params */

return [
    LoggerInterface::class => static function (Aliases $aliases, JsonLineFormat $format): Logger {
        $directory = $aliases->get('@runtime/logs');
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $target = (new StreamTarget($directory . '/app.log'))
            ->setFormat($format)
            // The JSON line already carries timestamp and level.
            ->setPrefix(static fn(): string => '');

        return new Logger([$target]);
    },
];
