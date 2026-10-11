<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Shared\Decimal;

final class VolumeReport
{
    /** @var list<VolumeLine> */
    public array $lines = [];
    public int $salesOnUnknownPumps = 0;
    public string $salesOnUnknownPumpsVolume = '0.000';

    public function __construct(
        public readonly int $stationId,
        public readonly string $fromUtc,
        public readonly string $toUtc,
        public readonly int $graceMinutes,
    ) {}

    /** clean | has_discrepancies | incomplete */
    public function status(): string
    {
        foreach ($this->lines as $line) {
            if ($line->coverage !== 'complete') {
                return 'incomplete';
            }
        }
        foreach ($this->lines as $line) {
            if ($line->flagged) {
                return 'has_discrepancies';
            }
        }

        return $this->salesOnUnknownPumps > 0 ? 'has_discrepancies' : 'clean';
    }

    /** @return array<string, int|string> */
    public function totals(): array
    {
        $dispensed = $sold = $missed = '0.000';
        $fills = $sales = $missedFills = 0;
        foreach ($this->lines as $line) {
            $dispensed = Decimal::add($dispensed, $line->dispensed);
            $sold = Decimal::add($sold, $line->sold);
            $missed = Decimal::add($missed, $line->missedVolume);
            $fills += $line->fills;
            $sales += $line->sales;
            $missedFills += $line->missedFills;
        }

        return [
            'fills' => $fills,
            'missed_fills' => $missedFills,
            'missed_volume' => $missed,
            'dispensed' => $dispensed,
            'sales' => $sales,
            'sold' => $sold,
            'gap' => Decimal::sub($dispensed, $sold),
            'sales_on_unknown_pumps' => $this->salesOnUnknownPumps,
            'status' => $this->status(),
        ];
    }
}
