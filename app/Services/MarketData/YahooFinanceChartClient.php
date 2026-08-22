<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;

/**
 * Fetches weekly price history from the unofficial Yahoo Finance chart API
 * (v8, range=2y&interval=1wk).
 *
 * docs/adr/ADR-0004-analysis-engine-indicator-expansion.md (§1)
 */
final class YahooFinanceChartClient
{
    private const BASE_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

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

        $result = $response->json('chart.result');

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

        if (count($history) > $weeks) {
            $history = array_slice($history, -$weeks);
        }

        return $history;
    }
}
