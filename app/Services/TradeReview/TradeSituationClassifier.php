<?php

namespace App\Services\TradeReview;

use App\Models\IndexWeeklyPrice;
use App\Models\TradeExecution;
use App\Models\WeeklyPrice;
use App\Services\Analysis\BuySignalDeterminationService;
use App\Services\MarketData\WeekDateNormalizer;
use App\Services\TradeReview\Support\PortfolioWeek;
use App\Services\TradeReview\Support\TradeContext;
use App\Services\TradeReview\Support\TradeSituation;
use Illuminate\Support\Carbon;

/**
 * Classifies each discretionary buy / sell by the situation it was made in
 * (UC-019 基本フロー1・2・4): 52週高値比, 対市場の相対強度 and 保有状況.
 * Each price axis prefers the value recorded at the time (TradeContext
 * metrics) and is otherwise recomputed from the weekly closes up to the week
 * before the trade week only — never from the trade week or later. The
 * band boundaries are the existing buy-signal thresholds. Earnings metrics
 * are never used. Routine trades are left out (UC-018 業務ルール).
 */
class TradeSituationClassifier
{
    private const WINDOW_BARS = 52;

    private const RETURN_WEEKS = 13;

    private const BENCHMARKS = ['jp' => 'nikkei225', 'us' => 'sp500'];

    public function __construct(private readonly WeekDateNormalizer $weekNormalizer) {}

    /**
     * $weeks is part of the contract shared with TradeContextFinder; the
     * holding axis is read from TradeContext, which was derived from it.
     *
     * @param  array<string, PortfolioWeek>  $weeks
     * @param  array<int, TradeContext>  $contexts  keyed by trade id
     * @return array<int, TradeSituation> keyed by trade id
     */
    public function classify(array $weeks, array $contexts): array
    {
        if ($contexts === []) {
            return [];
        }

        $trades = TradeExecution::query()
            ->with('holding:id,market')
            ->whereIn('id', array_keys($contexts))
            ->whereIn('kind', ['buy', 'sell'])
            ->where('is_routine', false)
            ->orderBy('id')
            ->get();

        if ($trades->isEmpty()) {
            return [];
        }

        $previousWeeks = [];

        foreach ($trades as $trade) {
            $previousWeeks[$trade->id] = Carbon::parse($this->weekNormalizer->weekStart($trade->trade_date->toDateString()))
                ->subWeek()
                ->toDateString();
        }

        $holdingIds = $trades->pluck('holding_id')->unique()->values()->all();
        $latest = max($previousWeeks);
        $unreliable = SplitData::load($holdingIds)->unreliable;
        $closes = $this->holdingCloses($holdingIds, $latest);
        $benchmarks = $this->benchmarkCloses($latest);
        $result = [];

        foreach ($trades as $trade) {
            $context = $contexts[$trade->id];
            $metrics = $context->metrics ?? [];
            $previous = $previousWeeks[$trade->id];
            $trusted = ! isset($unreliable[$trade->holding_id]);
            $stockCloses = $closes[$trade->holding_id] ?? [];

            $recordedHigh = isset($metrics['close'], $metrics['week52_high']);
            $highRatio = $recordedHigh
                ? $this->ratio((float) $metrics['close'], (float) $metrics['week52_high'])
                : ($trusted ? $this->recomputedHighRatio($stockCloses, $previous) : null);

            $recordedStrength = isset($metrics['relative_strength_vs_market']);
            $strength = $recordedStrength
                ? (float) $metrics['relative_strength_vs_market']
                : ($trusted ? $this->recomputedStrength($stockCloses, $benchmarks[self::BENCHMARKS[$trade->holding->market] ?? ''] ?? [], $previous) : null);

            $result[$trade->id] = new TradeSituation(
                tradeId: $trade->id,
                highRatioBand: match (true) {
                    $highRatio === null => 'unknown',
                    $highRatio >= BuySignalDeterminationService::RECENT_STRENGTH_THRESHOLD_RATE => 'near_high',
                    default => 'pullback',
                },
                relativeStrengthBand: match (true) {
                    $strength === null => 'unknown',
                    $strength >= BuySignalDeterminationService::MIN_RELATIVE_STRENGTH => 'strong',
                    default => 'weak',
                },
                holdingBand: $this->holdingBand($context),
                source: $recordedHigh && $recordedStrength ? 'recorded' : 'recomputed',
            );
        }

        return $result;
    }

    private function ratio(float $close, float $high): ?float
    {
        return $high > 0 ? $close / $high : null;
    }

    /**
     * The previous week's close ÷ the max of the latest 52 bars on or before
     * it (TechnicalIndicatorCalculator's array_slice($closes, -52)).
     *
     * @param  array<string, float>  $closes  week => close, ascending
     */
    private function recomputedHighRatio(array $closes, string $previous): ?float
    {
        if (! isset($closes[$previous])) {
            return null;
        }

        $window = array_slice(array_filter($closes, fn (string $week) => $week <= $previous, ARRAY_FILTER_USE_KEY), -self::WINDOW_BARS);

        if (count($window) < self::WINDOW_BARS) {
            return null;
        }

        return $this->ratio($closes[$previous], max($window));
    }

    /**
     * The stock's 13-week return % − the benchmark's 13-week return % over
     * the same weeks (TechnicalIndicatorCalculator's formula); null when any
     * of the four closes is missing.
     *
     * @param  array<string, float>  $stock  week => close
     * @param  array<string, float>  $benchmark  week => close
     */
    private function recomputedStrength(array $stock, array $benchmark, string $previous): ?float
    {
        $past = Carbon::parse($previous)->subWeeks(self::RETURN_WEEKS)->toDateString();
        $stockReturn = $this->returnPercent($stock[$past] ?? null, $stock[$previous] ?? null);
        $benchmarkReturn = $this->returnPercent($benchmark[$past] ?? null, $benchmark[$previous] ?? null);

        return $stockReturn === null || $benchmarkReturn === null ? null : $stockReturn - $benchmarkReturn;
    }

    private function returnPercent(?float $past, ?float $current): ?float
    {
        if ($past === null || $current === null || $past == 0.0) {
            return null;
        }

        return (($current - $past) / $past) * 100;
    }

    private function holdingBand(TradeContext $context): string
    {
        return match (true) {
            ! $context->heldBefore => 'none',
            $context->weightBefore < 0.01 => 'lt1',
            $context->weightBefore <= 0.05 => '1to5',
            default => 'gt5',
        };
    }

    /**
     * @param  list<int>  $holdingIds
     * @return array<int, array<string, float>> holding_id => [week => close], ascending
     */
    private function holdingCloses(array $holdingIds, string $latest): array
    {
        $closes = [];

        WeeklyPrice::query()
            ->toBase()
            ->select(['id', 'holding_id', 'week_date', 'close'])
            ->whereIn('holding_id', $holdingIds)
            ->where('week_date', '<=', $latest)
            ->orderBy('week_date')
            ->orderBy('id')
            ->chunk(5000, function ($rows) use (&$closes) {
                foreach ($rows as $row) {
                    $closes[(int) $row->holding_id][substr((string) $row->week_date, 0, 10)] = (float) $row->close;
                }
            });

        return $closes;
    }

    /**
     * @return array<string, array<string, float>> index_name => [week => close]
     */
    private function benchmarkCloses(string $latest): array
    {
        $closes = [];

        foreach (IndexWeeklyPrice::query()
            ->toBase()
            ->select(['index_name', 'week_date', 'close'])
            ->whereIn('index_name', array_values(self::BENCHMARKS))
            ->where('week_date', '<=', $latest)
            ->get() as $row) {
            $closes[(string) $row->index_name][substr((string) $row->week_date, 0, 10)] = (float) $row->close;
        }

        return $closes;
    }
}
