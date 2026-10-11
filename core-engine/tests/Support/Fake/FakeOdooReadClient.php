<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Odoo\OdooReadClientInterface;

/**
 * In-memory Odoo: returns fixture rows per model (domain is ignored; fixtures are pre-filtered).
 */
final class FakeOdooReadClient implements OdooReadClientInterface
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param array<string, list<array<string, mixed>>> $rowsByModel
     */
    public function __construct(private array $rowsByModel = []) {}

    public function set(string $model, array $rows): void
    {
        $this->rowsByModel[$model] = $rows;
    }

    public function searchRead(string $model, array $domain, array $fields, ?int $limit = null, ?string $order = null, array $context = []): array
    {
        $this->calls[] = $model;
        return $this->rowsByModel[$model] ?? [];
    }

    public function searchCount(string $model, array $domain, array $context = []): int
    {
        return count($this->rowsByModel[$model] ?? []);
    }
}
