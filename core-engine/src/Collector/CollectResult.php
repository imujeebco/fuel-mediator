<?php

declare(strict_types=1);

namespace App\Collector;

final class CollectResult
{
    public int $pumpsPolled = 0;
    public int $pumpErrors = 0;
    public int $fills = 0;
    public int $missedFills = 0;
    public int $snapshots = 0;
    public int $meterRegressions = 0;

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'pumps' => $this->pumpsPolled,
            'pump_errors' => $this->pumpErrors,
            'fills' => $this->fills,
            'missed_fills' => $this->missedFills,
            'snapshots' => $this->snapshots,
            'meter_regressions' => $this->meterRegressions,
        ];
    }
}
