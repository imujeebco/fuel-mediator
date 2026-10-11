<?php

declare(strict_types=1);

namespace App\Station\Import;

final class ImportResult
{
    public int $stations = 0;
    public int $controllers = 0;
    public int $pumps = 0;
    public int $nozzles = 0;
    public int $skippedPumps = 0;
    public int $skippedNozzles = 0;
    public bool $dryRun = false;

    /** @var list<array{odoo_id: int, name: string, active: bool, mode: string}> */
    public array $stationsDetail = [];

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'stations' => $this->stations,
            'controllers' => $this->controllers,
            'pumps' => $this->pumps,
            'nozzles' => $this->nozzles,
            'skipped_pumps' => $this->skippedPumps,
            'skipped_nozzles' => $this->skippedNozzles,
            'dry_run' => $this->dryRun,
        ];
    }
}
