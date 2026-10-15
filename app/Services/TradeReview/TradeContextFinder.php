<?php

namespace App\Services\TradeReview;

use App\Models\Holding;
use App\Models\IndicatorObservation;
use App\Models\SignalOccurrence;
use App\Models\TradeExecution;
use App\Services\MarketData\WeekDateNormalizer;
use App\Services\TradeReview\Support\PortfolioWeek;
use App\Services\TradeReview\Support\TradeContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Links each trade to what was already recorded before its trade day began
 * (UC-018 基本フロー7, ADR-0027 D4 as revised on 2026-10-11): signals and
 * indicator observations made in the 8 days before 00:00 of the trade date
 * in the market's time zone. Nothing is stored; the records it reads are
 * append-only, so the result is the same every time.
 */
class TradeContextFinder
{
    private const WINDOW_DAYS = 8;

    private const TIME_ZONES = ['jp' => 'Asia/Tokyo', 'us' => 'America/New_York'];

    public function __construct(private readonly WeekDateNormalizer $weeks) {}

    /**
     * @param  array<string, PortfolioWeek>  $weeks
     * @return array<int, TradeContext> keyed by trade id
     */
    public function find(array $weeks): array
    {
        $trades = TradeExecution::query()
            ->whereIn('kind', ['buy', 'sell', 'tsumitate'])
            ->orderBy('trade_date')
            ->orderBy('id')
            ->get();

        if ($trades->isEmpty()) {
            return [];
        }

        $holdingIds = $trades->pluck('holding_id')->unique()->values()->all();
        $signals = SignalOccurrence::query()->whereIn('holding_id', $holdingIds)->orderBy('created_at')->orderBy('id')->get()->groupBy('holding_id');
        $observations = IndicatorObservation::query()->whereIn('holding_id', $holdingIds)->orderBy('observed_at')->orderBy('id')->get()->groupBy('holding_id');
        $markets = Holding::query()->pluck('market', 'id')->all();
        $contexts = [];

        foreach ($trades as $trade) {
            $start = Carbon::parse($trade->trade_date->toDateString(), self::TIME_ZONES[$trade->market] ?? 'UTC')->utc();
            $from = $start->copy()->subDays(self::WINDOW_DAYS);
            $inWindow = fn (Carbon $at) => $at->gte($from) && $at->lt($start);

            $linked = $this->filter($signals->get($trade->holding_id), fn (SignalOccurrence $s) => $inWindow($s->created_at));
            $observation = $this->filter($observations->get($trade->holding_id), fn (IndicatorObservation $o) => $inWindow($o->observed_at))->last();
            $latestSignal = $linked->filter(fn (SignalOccurrence $s) => $s->metrics !== null)->last();

            [$metrics, $observedAt] = $observation !== null
                ? [$observation->metrics, $observation->observed_at]
                : [$latestSignal?->metrics, $latestSignal?->created_at];

            [$held, $weight] = $this->holdingBefore($trade, $weeks, $markets);

            $contexts[$trade->id] = new TradeContext(
                tradeId: $trade->id,
                signals: $linked->map(fn (SignalOccurrence $s) => [
                    'source' => $s->source,
                    'signal_type' => $s->signal_type,
                    'observed_week' => $s->observed_week->toDateString(),
                ])->values()->all(),
                metrics: $metrics,
                observedAt: $observedAt?->utc()->toDateTimeString(),
                known: $linked->isNotEmpty() || $metrics !== null,
                heldBefore: $held,
                weightBefore: $weight,
            );
        }

        return $contexts;
    }

    /**
     * @template T
     *
     * @param  Collection<int, T>|null  $records
     * @return Collection<int, T>
     */
    private function filter(?Collection $records, callable $keep): Collection
    {
        return ($records ?? collect())->filter($keep)->values();
    }

    /**
     * Whether the holding was held at the end of the week before the trade
     * week, and its share of the same market's value then.
     *
     * @param  array<string, PortfolioWeek>  $weeks
     * @param  array<int, string>  $markets
     * @return array{0: bool, 1: float}
     */
    private function holdingBefore(TradeExecution $trade, array $weeks, array $markets): array
    {
        $before = $weeks[Carbon::parse($this->weeks->weekStart($trade->trade_date->toDateString()))->subWeek()->toDateString()] ?? null;

        if ($before === null || ($before->quantities[$trade->holding_id] ?? 0.0) <= 0) {
            return [false, 0.0];
        }

        $values = $before->holdingValuesJpy + $before->excludedEstimatesJpy;
        $total = 0.0;

        foreach ($values as $id => $value) {
            $total += ($markets[$id] ?? null) === $trade->market ? $value : 0.0;
        }

        return [true, $total > 0 ? ($values[$trade->holding_id] ?? 0.0) / $total : 0.0];
    }
}
