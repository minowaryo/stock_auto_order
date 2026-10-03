<?php

namespace App\Actions\SignalOutcome;

use App\Models\IndexWeeklyPrice;
use App\Models\SignalOccurrence;
use App\Models\WeeklyPrice;
use App\Services\MarketData\WeekDateNormalizer;
use App\Services\SignalOutcome\ExcessReturnCalculator;
use App\Services\SignalOutcome\OutcomeStatisticsCalculator;
use App\Services\SignalOutcome\SignalOutcomeVerdictEvaluator;
use App\Support\DisplayTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * UC-014 / ADR-0017 D4/D7/D8: read-only aggregation of signal occurrences by
 * source × signal_type × horizon (excess return vs. 日経平均 / S&P500).
 * Mean / t-value / verdict use one sample per observed week (the mean of that
 * week's matured excess returns; ADR-0017 D7 revision, CHG-0020 Cycle5) because
 * same-week occurrences move together; median / hit rate stay per occurrence.
 * Price series are only UPSERTed on CSV import, so the latest imported week holds
 * a mid-week close: a week is final only when it is before both the as-of week
 * and the market index's latest week (finalWeekBoundaries()).
 * Never writes and never changes any threshold.
 */
class ShowSignalOutcomesAction
{
    public const SOURCES = ['take_profit', 'buy', 'watchlist_buy'];

    public const MARKETS = ['jp', 'us'];

    public const HORIZONS = [4, 13, 26];

    private const INDEX_BY_MARKET = ['jp' => 'nikkei225', 'us' => 'sp500'];

    public function __construct(
        private readonly ExcessReturnCalculator $excessReturnCalculator,
        private readonly OutcomeStatisticsCalculator $statisticsCalculator,
        private readonly SignalOutcomeVerdictEvaluator $verdictEvaluator,
        private readonly WeekDateNormalizer $weekDateNormalizer,
    ) {}

    /**
     * @return array{has_occurrences: bool, as_of_week: string, groups: list<array<string, mixed>>}
     */
    public function execute(?string $source = null, ?string $market = null): array
    {
        if ($source !== null && ! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown source: {$source}");
        }

        if ($market !== null && ! in_array($market, self::MARKETS, true)) {
            throw new InvalidArgumentException("Unknown market: {$market}");
        }

        $asOfWeek = $this->weekDateNormalizer->weekStart(
            now()->setTimezone(DisplayTime::timezone())->format('Y-m-d')
        );

        $occurrences = SignalOccurrence::query()
            ->select(['id', 'holding_id', 'source', 'signal_type', 'observed_week', 'metrics'])
            ->with('holding:id,symbol_code,symbol_name,market')
            ->when($source !== null, fn (Builder $q) => $q->where('source', $source))
            ->when($market !== null, fn (Builder $q) => $q->whereHas('holding', fn (Builder $h) => $h->where('market', $market)))
            ->orderByDesc('observed_week')
            ->orderByDesc('id')
            ->get();

        $stockCloses = $this->stockCloses($occurrences->pluck('holding_id')->unique()->values()->all());
        $indexCloses = $this->indexCloses();
        $finalBefore = $this->finalWeekBoundaries($indexCloses, $asOfWeek);

        $groups = [];

        foreach ($occurrences as $occurrence) {
            $holding = $occurrence->holding;
            $observedWeek = $occurrence->observed_week->format('Y-m-d');
            $indexName = self::INDEX_BY_MARKET[$holding->market] ?? null;

            $excessReturns = [];
            $statuses = [];

            foreach (self::HORIZONS as $horizon) {
                $result = $this->excessReturnCalculator->calculate(
                    $observedWeek,
                    $horizon,
                    $stockCloses[$occurrence->holding_id] ?? [],
                    $indexName !== null ? ($indexCloses[$indexName] ?? []) : [],
                    $indexName !== null ? $finalBefore[$indexName] : $asOfWeek,
                );
                $excessReturns[$horizon] = $result['excess_return'];
                $statuses[$horizon] = $result['status'];
            }

            $key = $occurrence->source.'/'.$occurrence->signal_type;
            $groups[$key] ??= [
                'source' => $occurrence->source,
                'signal_type' => $occurrence->signal_type,
                'occurrences' => [],
            ];
            $groups[$key]['occurrences'][] = [
                'symbol_code' => $holding->symbol_code,
                'symbol_name' => $holding->symbol_name,
                'market' => $holding->market,
                'observed_week' => $observedWeek,
                'metrics' => $occurrence->metrics,
                'excess_returns' => $excessReturns,
                'statuses' => $statuses,
            ];
        }

        $sourceOrder = array_flip(self::SOURCES);
        uasort($groups, fn (array $a, array $b) => [$sourceOrder[$a['source']], $a['signal_type']]
            <=> [$sourceOrder[$b['source']], $b['signal_type']]);

        return [
            'has_occurrences' => SignalOccurrence::query()->exists(),
            'as_of_week' => $asOfWeek,
            'groups' => array_values(array_map(fn (array $g) => $this->summarize($g, $asOfWeek), $groups)),
        ];
    }

    /**
     * @param  array{source: string, signal_type: string, occurrences: list<array<string, mixed>>}  $group
     * @return array<string, mixed>
     */
    private function summarize(array $group, string $asOfWeek): array
    {
        $firstObservedWeek = min(array_column($group['occurrences'], 'observed_week'));
        $horizons = [];

        foreach (self::HORIZONS as $horizon) {
            $counts = ['matured' => 0, 'pending' => 0, 'unavailable' => 0];
            $matured = [];
            $byWeek = [];

            foreach ($group['occurrences'] as $row) {
                $counts[$row['statuses'][$horizon]]++;

                if ($row['statuses'][$horizon] === 'matured') {
                    $matured[] = $row['excess_returns'][$horizon];
                    $byWeek[$row['observed_week']][] = $row['excess_returns'][$horizon];
                }
            }

            // Median / hit rate per occurrence; mean / t / verdict per observed week (1 week = 1 sample).
            $occurrenceStats = $this->statisticsCalculator->calculate($matured, $group['source']);
            $weeklyMeans = array_map(fn (array $values) => array_sum($values) / count($values), $byWeek);
            $weeklyStats = $this->statisticsCalculator->calculate(array_values($weeklyMeans), $group['source']);

            $byYear = [];
            foreach ($weeklyMeans as $week => $weeklyMean) {
                $byYear[(int) substr((string) $week, 0, 4)][] = $weeklyMean;
            }
            $yearlyMeans = array_map(fn (array $values) => array_sum($values) / count($values), $byYear);

            $verdict = $this->verdictEvaluator->evaluate(
                $group['source'],
                $horizon,
                $occurrenceStats['matured_count'],
                count($byWeek),
                $weeklyStats['mean'],
                $weeklyStats['t_value'],
                $firstObservedWeek,
                $asOfWeek,
                $yearlyMeans,
            );

            $horizons[$horizon] = [
                'matured_count' => $occurrenceStats['matured_count'],
                'matured_week_count' => count($byWeek),
                'pending_count' => $counts['pending'],
                'unavailable_count' => $counts['unavailable'],
                'mean' => $weeklyStats['mean'],
                'median' => $occurrenceStats['median'],
                't_value' => $weeklyStats['t_value'],
                'hit_rate' => $occurrenceStats['hit_rate'],
                'verdict' => $verdict['verdict'],
                'provisional' => $verdict['provisional'],
            ];
        }

        return [
            'source' => $group['source'],
            'signal_type' => $group['signal_type'],
            'occurrence_count' => count($group['occurrences']),
            'horizons' => $horizons,
            'occurrences' => $group['occurrences'],
        ];
    }

    /**
     * First not-yet-final week per index: the earlier of the as-of week and the index's
     * latest imported week. With no index rows the as-of week is kept, so the missing
     * index surfaces as 算出不可 (UC-014 エラーケース) rather than 結果待ち.
     *
     * @param  array<string, array<string, float>>  $indexCloses
     * @return array<string, string> index_name => Y-m-d
     */
    private function finalWeekBoundaries(array $indexCloses, string $asOfWeek): array
    {
        $boundaries = [];

        foreach (self::INDEX_BY_MARKET as $indexName) {
            $weeks = array_keys($indexCloses[$indexName] ?? []);
            $boundaries[$indexName] = $weeks === [] ? $asOfWeek : min($asOfWeek, max($weeks));
        }

        return $boundaries;
    }

    /**
     * @param  list<int>  $holdingIds
     * @return array<int, array<string, float>> holding_id => [week_date => close]
     */
    private function stockCloses(array $holdingIds): array
    {
        if ($holdingIds === []) {
            return [];
        }

        return $this->closesBy(
            WeeklyPrice::query()->select(['holding_id', 'week_date', 'close'])->whereIn('holding_id', $holdingIds)->get(),
            'holding_id',
        );
    }

    /**
     * @return array<string, array<string, float>> index_name => [week_date => close]
     */
    private function indexCloses(): array
    {
        return $this->closesBy(
            IndexWeeklyPrice::query()->select(['index_name', 'week_date', 'close'])
                ->whereIn('index_name', array_values(self::INDEX_BY_MARKET))->get(),
            'index_name',
        );
    }

    /**
     * @return array<int|string, array<string, float>>
     */
    private function closesBy(Collection $rows, string $key): array
    {
        $closes = [];

        foreach ($rows as $row) {
            $closes[$row->{$key}][$row->week_date->format('Y-m-d')] = (float) $row->close;
        }

        return $closes;
    }
}
