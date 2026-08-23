<?php

namespace App\Actions\Candidate;

use App\Models\FinancialStatement;
use App\Models\Holding;
use App\Services\Candidate\CandidateOverlapCalculator;
use App\Services\Sector\SectorAllocationCalculator;

/**
 * UC-006 (新規投資候補の重複チェック): assembles the candidate check view —
 * overlap_rate/diversification_comment (reusing SectorAllocationCalculator via
 * CandidateOverlapCalculator), the same technical/fundamental indicator set as
 * UC-003 (ShowHoldingDetailAction), historical performance, and the watch
 * status/memo history.
 */
class ShowCandidateCheckAction
{
    public function __construct(
        private readonly CandidateOverlapCalculator $overlapCalculator,
        private readonly SectorAllocationCalculator $sectorAllocationCalculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(Holding $holding): array
    {
        $technicalIndicator = $holding->technicalIndicator;
        $fundamentalIndicator = $holding->fundamentalIndicator;

        $overlap = $this->overlapCalculator->calculate($holding, $this->sectorAllocationCalculator);

        $historicalPerformance = FinancialStatement::where('holding_id', $holding->id)
            ->orderByDesc('fiscal_period')
            ->get()
            ->map(fn (FinancialStatement $statement) => [
                'fiscal_period' => $statement->fiscal_period,
                'revenue' => $statement->revenue,
                'operating_income' => $statement->operating_income,
                'revenue_yoy_change' => $statement->revenue_yoy_change,
                'operating_income_yoy_change' => $statement->operating_income_yoy_change,
            ])
            ->values()
            ->all();

        $watchRecords = $holding->watchRecords()
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get();

        $watchMemoHistory = $watchRecords
            ->map(fn ($watchRecord) => [
                'recorded_at' => $watchRecord->recorded_at,
                'watch_status' => $watchRecord->watch_status,
                'memo' => $watchRecord->memo,
            ])
            ->values()
            ->all();

        return [
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'sector' => $holding->sectorClassification?->name ?? '未分類',
            'overlap_rate' => $overlap['overlap_rate'],
            'diversification_comment' => $overlap['diversification_comment'],
            'rsi' => $technicalIndicator->rsi ?? null,
            'macd' => $technicalIndicator->macd ?? null,
            'bollinger_band' => [
                'bb_upper' => $technicalIndicator->bb_upper ?? null,
                'bb_lower' => $technicalIndicator->bb_lower ?? null,
            ],
            'ma20' => $technicalIndicator->ma20 ?? null,
            'ma75' => $technicalIndicator->ma75 ?? null,
            'volume' => $technicalIndicator->volume ?? null,
            'volume_ma20' => $technicalIndicator->volume_ma20 ?? null,
            'week52_high' => $technicalIndicator->week52_high ?? null,
            'week52_low' => $technicalIndicator->week52_low ?? null,
            'relative_strength_vs_market' => $technicalIndicator->relative_strength_vs_market ?? null,
            'relative_strength_vs_sector' => $technicalIndicator->relative_strength_vs_sector ?? null,
            'per' => $fundamentalIndicator->per ?? null,
            'pbr' => $fundamentalIndicator->pbr ?? null,
            'roe' => $fundamentalIndicator->roe ?? null,
            'revenue_growth' => $fundamentalIndicator->revenue_growth ?? null,
            'equity_ratio' => $fundamentalIndicator->equity_ratio ?? null,
            'dividend_yield' => $fundamentalIndicator->dividend_yield ?? null,
            'eps_growth' => $fundamentalIndicator->eps_growth ?? null,
            'peg_ratio' => $fundamentalIndicator->peg_ratio ?? null,
            'historical_performance' => $historicalPerformance,
            'watch_status' => $watchRecords->first()?->watch_status,
            'watch_memo_history' => $watchMemoHistory,
        ];
    }
}
