<?php

declare(strict_types=1);

namespace App\Reconciliation;

final class MatchResult
{
    public int $confirmed = 0;
    public int $probable = 0;
    public int $pending = 0;
    public int $unmatchedFills = 0;
    public int $salesWithoutFill = 0;
    public int $duplicateCandidates = 0;

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'confirmed_links' => $this->confirmed,
            'probable_links' => $this->probable,
            'fills_pending_grace' => $this->pending,
            'unmatched_fills' => $this->unmatchedFills,
            'sales_without_fill' => $this->salesWithoutFill,
            'duplicate_candidates' => $this->duplicateCandidates,
        ];
    }
}
