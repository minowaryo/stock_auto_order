<?php

namespace App\Actions\Holding;

use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Services\Analysis\FundamentalMetricToneEvaluator;
use App\Services\Analysis\ValuationBenchmarkJudge;
use App\Support\DisplayTime;

/**
 * UC-003 (銘柄詳細表示): assembles a single holding's detail view — price
 * history chart data, current technical/fundamental indicator snapshot,
 * the latest sell-timing signal (if any), and the memo history.
 */
class ShowHoldingDetailAction
{
    /**
     * chart_period → 何年前までのスナップショットを含めるか
     * (docs/product/use-cases.md UC-003, 省略時は3y).
     *
     * @var array<string, int>
     */
    private const CHART_PERIOD_YEARS = [
        '1y' => 1,
        '3y' => 3,
        '5y' => 5,
        '10y' => 10,
    ];

    public function __construct(
        private readonly ValuationBenchmarkJudge $valuationJudge,
        private readonly FundamentalMetricToneEvaluator $toneEvaluator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(Holding $holding, ?string $chartPeriod = null): array
    {
        $years = self::CHART_PERIOD_YEARS[$chartPeriod ?? '3y'] ?? self::CHART_PERIOD_YEARS['3y'];
        $cutoff = now()->subYears($years);

        $holdingSnapshots = $holding->holdingSnapshots()
            ->with('snapshot', 'signals')
            ->get()
            ->sortBy(fn (HoldingSnapshot $holdingSnapshot) => $holdingSnapshot->snapshot->snapshotted_at);

        $latestHoldingSnapshot = $holdingSnapshots->last();

        $priceHistory = $holdingSnapshots
            ->filter(fn (HoldingSnapshot $holdingSnapshot) => $holdingSnapshot->snapshot->snapshotted_at->greaterThanOrEqualTo($cutoff))
            ->map(fn (HoldingSnapshot $holdingSnapshot) => [
                'date' => DisplayTime::date($holdingSnapshot->snapshot->snapshotted_at),
                'close_price' => $holdingSnapshot->current_price,
                'ma20' => $holdingSnapshot->ma20,
                'ma75' => $holdingSnapshot->ma75,
            ])
            ->values()
            ->all();

        $technicalIndicator = $holding->technicalIndicator;
        $fundamentalIndicator = $holding->fundamentalIndicator;

        $market = $holding->market;
        $sectorName = $holding->sectorClassification->name ?? null;
        $float = fn (string $key): ?float => ($fundamentalIndicator->{$key} ?? null) === null ? null : (float) $fundamentalIndicator->{$key};
        $metricTones = [];
        foreach (['roe', 'equity_ratio', 'operating_margin', 'revenue_growth', 'operating_income_growth'] as $metric) {
            $metricTones[$metric] = $this->toneEvaluator->tone($metric, $float($metric), $market, $sectorName);
        }

        [$signalResult, $signalReason] = $this->resolveSignal($latestHoldingSnapshot);

        $memoHistory = $holding->memos()
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($memo) => [
                'body' => $memo->body,
                'recorded_at' => $memo->recorded_at,
            ])
            ->values()
            ->all();

        return [
            'symbol_code' => $holding->symbol_code,
            'symbol_name' => $holding->symbol_name,
            'average_cost' => $latestHoldingSnapshot->average_cost ?? null,
            'price_history' => $priceHistory,
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
            'operating_margin' => $fundamentalIndicator->operating_margin ?? null,
            'dividend_yield' => $fundamentalIndicator->dividend_yield ?? null,
            'eps_growth' => $fundamentalIndicator->eps_growth ?? null,
            'peg_ratio' => $fundamentalIndicator->peg_ratio ?? null,
            'per_verdict' => $this->valuationJudge->judge('per', $float('per'), $market, $sectorName),
            'pbr_verdict' => $this->valuationJudge->judge('pbr', $float('pbr'), $market, $sectorName),
            'metric_tones' => $metricTones,
            'signal_result' => $signalResult,
            'signal_reason' => $signalReason,
            'memo_history' => $memoHistory,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSignal(?HoldingSnapshot $latestHoldingSnapshot): array
    {
        $signal = $latestHoldingSnapshot?->signals->first();

        if ($signal) {
            return ['利確検討', $signal->reason_summary];
        }

        return ['シグナルなし', '利確検討が必要なシグナルは検出されていません'];
    }
}
