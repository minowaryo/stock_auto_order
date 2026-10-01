<?php

namespace App\Actions\Concentration;

use App\Models\HoldingSnapshot;
use App\Models\IndexWeeklyPrice;
use App\Models\Snapshot;
use App\Models\WeeklyPrice;
use App\Services\Concentration\CorrelationMatrixCalculator;
use App\Services\Concentration\EffectiveBetCalculator;
use App\Services\Concentration\HoldingWeightCalculator;
use App\Services\Concentration\PrincipalComponentAnalyzer;
use App\Services\Concentration\SoxBetaCalculator;
use App\Services\Concentration\TopHoldingsConcentrationCalculator;
use App\Services\Concentration\WeeklyReturnMatrixBuilder;

/**
 * UC-015 (集中度ダッシュボード, ADR-0019): read-only orchestration of the
 * pure Concentration services over the latest snapshot's holdings.
 * Percent outputs are in % units (14.7, not 0.147).
 */
class ShowConcentrationDashboardAction
{
    private const MATRIX_LIMIT = 20;

    private const MIN_INCLUDED = 2;

    public function __construct(
        private readonly HoldingWeightCalculator $weightCalculator,
        private readonly WeeklyReturnMatrixBuilder $matrixBuilder,
        private readonly CorrelationMatrixCalculator $correlationCalculator,
        private readonly PrincipalComponentAnalyzer $principalComponentAnalyzer,
        private readonly EffectiveBetCalculator $effectiveBetCalculator,
        private readonly SoxBetaCalculator $soxBetaCalculator,
        private readonly TopHoldingsConcentrationCalculator $topHoldingsCalculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $snapshot = Snapshot::query()->orderByDesc('snapshotted_at')->orderByDesc('id')->first();
        if ($snapshot === null) {
            return $this->emptyResult();
        }

        $holdingSnapshots = HoldingSnapshot::query()
            ->with('holding')
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('id')
            ->get();

        $candidates = [];
        $positions = [];
        $holdingsById = [];
        foreach ($holdingSnapshots as $holdingSnapshot) {
            $holding = $holdingSnapshot->holding;
            $holdingsById[$holding->id] = $holding;
            $candidates[] = ['holding_id' => $holding->id, 'instrument_type' => $holding->instrument_type];
            $positions[] = [
                'holding_id' => $holding->id,
                'instrument_type' => $holding->instrument_type,
                'quantity' => $holdingSnapshot->quantity,
                'current_price' => $holdingSnapshot->current_price,
            ];
        }

        $marketValues = $this->weightCalculator->marketValues($positions);
        $built = $this->matrixBuilder->build($candidates, $this->weeklyCloses(array_keys($holdingsById)), $this->soxCloses());

        $returns = $built['returns'];
        $included = array_keys($returns);
        // Market value desc; usort is stable so ties keep holding-snapshot order.
        usort($included, fn (int $a, int $b) => $marketValues[$b] <=> $marketValues[$a]);
        $weights = $this->weightCalculator->normalize($marketValues, $included);
        $enough = count($included) >= self::MIN_INCLUDED;

        $betas = $built['sox_returns'] === null
            ? array_fill_keys($included, null)
            : $this->soxBetaCalculator->betas($returns, $built['sox_returns']);

        $matrixIds = $enough ? array_slice($included, 0, self::MATRIX_LIMIT) : [];
        $correlations = $matrixIds === []
            ? []
            : $this->correlationCalculator->calculate(array_intersect_key($returns, array_flip($matrixIds)));

        $totalValue = array_sum($marketValues);
        $includedValue = array_sum(array_intersect_key($marketValues, array_flip($included)));
        $pc1 = $enough ? $this->principalComponentAnalyzer->firstComponentShare($returns) : null;
        $top5 = $this->topHoldingsCalculator->topWeight($marketValues, 5);

        return [
            'has_snapshot' => true,
            'window_start_week' => $built['window_start'],
            'window_end_week' => $built['window_end'],
            'included_count' => count($included),
            'coverage_rate' => $totalValue > 0 ? $includedValue / $totalValue * 100 : null,
            'pc1_share' => $pc1 === null ? null : $pc1 * 100,
            'effective_number_of_bets' => $enough ? $this->effectiveBetCalculator->calculate($returns, $weights) : null,
            'portfolio_sox_beta' => $enough && $built['sox_returns'] !== null
                ? $this->soxBetaCalculator->portfolioBeta($betas, $weights)
                : null,
            'top5_weight' => $top5 === null ? null : $top5 * 100,
            'correlation_matrix' => array_map(fn (int $id) => [
                'holding_id' => $id,
                'symbol_code' => $holdingsById[$id]->symbol_code,
                'symbol_name' => $holdingsById[$id]->symbol_name,
                'correlations' => array_map(fn (int $other) => $correlations[$id][$other], $matrixIds),
            ], $matrixIds),
            'hidden_count' => count($included) - count($matrixIds),
            'sox_betas' => array_map(fn (int $id) => [
                'symbol_code' => $holdingsById[$id]->symbol_code,
                'symbol_name' => $holdingsById[$id]->symbol_name,
                'weight' => ($weights[$id] ?? 0.0) * 100,
                'sox_beta' => $betas[$id] ?? null,
            ], $included),
            'excluded' => array_map(fn (int $id, string $reason) => [
                'symbol_code' => $holdingsById[$id]->symbol_code,
                'symbol_name' => $holdingsById[$id]->symbol_name,
                'reason' => $reason,
            ], array_keys($built['excluded']), array_values($built['excluded'])),
        ];
    }

    /**
     * @param  list<int>  $holdingIds
     * @return array<int, array<string, float>> holding_id => [Monday Y-m-d => close]
     */
    private function weeklyCloses(array $holdingIds): array
    {
        $closes = [];
        WeeklyPrice::query()
            ->select(['holding_id', 'week_date', 'close'])
            ->whereIn('holding_id', $holdingIds)
            ->get()
            ->each(function (WeeklyPrice $price) use (&$closes) {
                $closes[$price->holding_id][$price->week_date->format('Y-m-d')] = (float) $price->close;
            });

        return $closes;
    }

    /**
     * @return array<string, float> Monday Y-m-d => close
     */
    private function soxCloses(): array
    {
        return IndexWeeklyPrice::query()
            ->select(['week_date', 'close'])
            ->where('index_name', 'sox')
            ->get()
            ->mapWithKeys(fn (IndexWeeklyPrice $price) => [$price->week_date->format('Y-m-d') => (float) $price->close])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(): array
    {
        return [
            'has_snapshot' => false,
            'window_start_week' => null,
            'window_end_week' => null,
            'included_count' => 0,
            'coverage_rate' => null,
            'pc1_share' => null,
            'effective_number_of_bets' => null,
            'portfolio_sox_beta' => null,
            'top5_weight' => null,
            'correlation_matrix' => [],
            'hidden_count' => 0,
            'sox_betas' => [],
            'excluded' => [],
        ];
    }
}
