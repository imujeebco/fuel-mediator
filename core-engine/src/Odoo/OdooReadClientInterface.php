<?php

declare(strict_types=1);

namespace App\Odoo;

/**
 * Read-only access to Odoo. There is deliberately no write method (decision D8).
 */
interface OdooReadClientInterface
{
    /**
     * @param list<mixed> $domain
     * @param list<string> $fields
     * @param array<string, mixed> $context
     *
     * @throws OdooException
     *
     * @return list<array<string, mixed>>
     */
    public function searchRead(
        string $model,
        array $domain,
        array $fields,
        ?int $limit = null,
        ?string $order = null,
        array $context = [],
    ): array;

    /**
     * @param list<mixed> $domain
     *
     * @throws OdooException
     */
    public function searchCount(string $model, array $domain, array $context = []): int;
}
