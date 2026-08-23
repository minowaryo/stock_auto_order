<?php

namespace App\Actions\Sector;

use App\Services\Candidate\NewCandidateFinder;
use App\Services\Sector\SectorAllocationCalculator;

/**
 * UC-005 (セクター配分ダッシュボード): combines sector allocation rows
 * (SectorAllocationCalculator) with NewCandidateFinder's rebalance candidate
 * list (UC-008, remapped to sector_name/reason/suggested_purchase_amount),
 * excluding candidates whose sector is currently 偏り警告 (overweight).
 */
class ShowSectorDashboardAction
{
    public function __construct(
        private readonly SectorAllocationCalculator $calculator,
        private readonly NewCandidateFinder $finder,
    ) {}

    /**
     * @return array{sectors: array<int, array<string, mixed>>, rebalance_candidates: array<int, array<string, mixed>>}
     */
    public function execute(): array
    {
        $sectors = $this->calculator->calculate();

        $overweightSectorNames = collect($sectors)
            ->filter(fn (array $sector) => $sector['allocation_status'] === '偏り警告')
            ->pluck('sector_name')
            ->all();

        $rebalanceCandidates = collect($this->finder->find())
            ->reject(fn (array $candidate) => in_array($candidate['matched_theme'], $overweightSectorNames, true))
            ->map(fn (array $candidate) => [
                'symbol_code' => $candidate['symbol_code'],
                'symbol_name' => $candidate['symbol_name'],
                'sector_name' => $candidate['matched_theme'],
                'reason' => $candidate['fundamental_summary'],
                'suggested_purchase_amount' => $candidate['suggested_amount'],
                'nisa_recommended' => $candidate['nisa_recommended'],
            ])
            ->values()
            ->all();

        return [
            'sectors' => $sectors,
            'rebalance_candidates' => $rebalanceCandidates,
        ];
    }
}
