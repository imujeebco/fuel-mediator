<?php

declare(strict_types=1);

namespace App\Odoo;

use RuntimeException;

final class OdooException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $odooErrorName = '',
        public readonly bool $isTransport = false,
    ) {
        parent::__construct($message);
    }
}
