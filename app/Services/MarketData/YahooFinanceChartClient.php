<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches weekly price history from the unofficial Yahoo Finance chart API
 * (v8, range=2y&interval=1wk).
 *
 * Two rules are applied before the $weeks slicing so that no week is ever
 * counted twice:
 *   1. A trailing volume=0 placeholder whose close equals the previous close
 *      (in-progress current week) is dropped.
 *   2. Rows falling in the same week (Yahoo's last-trading-day bar, which can
 *      appear anywhere in the series, CHG-0022) are folded into the first row
 *      of that week via WeekDateNormalizer::foldByWeek() (ADR-0017 D2).
 *
 * docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§1)
 */
final class YahooFinanceChartClient
{
    private const BASE_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    public function __construct(
        private readonly WeekDateNormalizer $weekDateNormalizer = new WeekDateNormalizer,
    ) {}

    /**
     * @return array<int, array{date: string, close: float, volume: int}>
     */
    public function fetchWeeklyHistory(string $symbol, int $weeks): array
    {
        $response = Http::get(self::BASE_URL.$symbol, [
            'range' => '2y',
            'interval' => '1wk',
        ]);

        if (! $response->successful()) {
            return [];
        }

        $history = $this->rowsFrom($response->json('chart.result'));

        if (count($history) > $weeks) {
            $history = array_slice($history, -$weeks);
        }

        return $history;
    }

    /**
     * Weekly history for a longer range, optionally with stock splits
     * (ADR-0024 D5 backfill). Never throws: the outcome is in the status
     * (404 → not_found; other non-2xx or a connection error → failed; a 200
     * without usable bars → empty).
     */
    public function fetchHistory(string $symbol, string $range, bool $withSplits = false): PriceHistory
    {
        $query = ['range' => $range, 'interval' => '1wk'];

        if ($withSplits) {
            $query['events'] = 'splits';
        }

        try {
            $response = Http::get(self::BASE_URL.$symbol, $query);
        } catch (ConnectionException $e) {
            return new PriceHistory(PriceHistory::FAILED, message: $e->getMessage());
        }

        if ($response->status() === 404) {
            return new PriceHistory(PriceHistory::NOT_FOUND, message: 'HTTP 404');
        }

        if (! $response->successful()) {
            return new PriceHistory(PriceHistory::FAILED, message: 'HTTP '.$response->status());
        }

        $result = $response->json('chart.result');
        $rows = $this->rowsFrom($result);

        if ($rows === []) {
            return new PriceHistory(PriceHistory::EMPTY);
        }

        [$splits, $incomplete] = $withSplits ? $this->splitsFrom($result) : [[], false];

        return new PriceHistory(PriceHistory::OK, $rows, $splits, $incomplete);
    }

    /**
     * The shared bar rules: skip bars without close/volume, drop the
     * unconfirmed tail placeholder, fold same-week bars.
     *
     * @param  array<int, array<string, mixed>>|null  $result  chart.result
     * @return list<array{date: string, close: float, volume: int}>
     */
    private function rowsFrom(?array $result): array
    {
        if (empty($result)) {
            return [];
        }

        $timestamps = $result[0]['timestamp'] ?? [];
        $closes = $result[0]['indicators']['quote'][0]['close'] ?? [];
        $volumes = $result[0]['indicators']['quote'][0]['volume'] ?? [];

        $history = [];

        foreach ($timestamps as $index => $timestamp) {
            $close = $closes[$index] ?? null;
            $volume = $volumes[$index] ?? null;

            if ($close === null || $volume === null) {
                continue;
            }

            $history[] = [
                'date' => gmdate('Y-m-d', $timestamp),
                'close' => (float) $close,
                'volume' => (int) $volume,
            ];
        }

        // Yahoo Finance's weekly chart series can end with a placeholder for
        // the still-in-progress current week (volume=0, close identical to
        // the previous confirmed week's close). Drop it so callers never
        // mistake it for the latest confirmed week.
        $lastIndex = count($history) - 1;
        if ($lastIndex > 0
            && $history[$lastIndex]['volume'] === 0
            && $history[$lastIndex]['close'] === $history[$lastIndex - 1]['close']
        ) {
            array_pop($history);
        }

        // Yahoo can also emit a last-trading-day bar (nonzero volume) dated
        // inside the same week as a weekly bar, not only at the tail. Keep
        // the first row of each week (shared rule with WeeklyPriceRecorder).
        return $this->weekDateNormalizer->foldByWeek($history);
    }

    /**
     * Whole-number splits only (stock_splits stores integer ratios); any
     * other entry is dropped and reported through the incomplete flag.
     *
     * @param  array<int, array<string, mixed>>  $result  chart.result
     * @return array{0: list<array{date: string, numerator: int, denominator: int}>, 1: bool}
     */
    private function splitsFrom(array $result): array
    {
        $splits = [];
        $incomplete = false;

        foreach (($result[0]['events']['splits'] ?? []) as $split) {
            $numerator = $split['numerator'] ?? null;
            $denominator = $split['denominator'] ?? null;
            $at = $split['date'] ?? null;

            if (! is_numeric($numerator) || ! is_numeric($denominator) || ! is_numeric($at)
                || (float) $numerator !== floor((float) $numerator) || (float) $denominator !== floor((float) $denominator)
                || (int) $numerator < 1 || (int) $denominator < 1) {
                $incomplete = true;

                continue;
            }

            $splits[] = ['date' => gmdate('Y-m-d', (int) $at), 'numerator' => (int) $numerator, 'denominator' => (int) $denominator];
        }

        usort($splits, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return [$splits, $incomplete];
    }
}
