<?php

namespace App\Services\MarketData;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Single source of the Yahoo Finance weekly bar "week" rule
 * (UC-014, ADR-0017 D2, docs/architecture/data-model.md `weekly_prices`).
 * Used by both YahooFinanceChartClient and WeeklyPriceRecorder so that they
 * never apply diverging dedupe rules.
 *
 * JP / ^N225 bars come stamped on Sunday (= Monday 00:00 JST), US / ^GSPC
 * bars on Monday, and a last-trading-day bar may follow inside the same
 * week. Shifting by +1 day and taking the ISO-week Monday folds all of them
 * into the same week; foldByWeek() keeps only the first row of each week.
 */
class WeekDateNormalizer
{
    /**
     * The Monday of the last week that is over: a week is confirmed once the
     * next Monday has started (UC-018 state rules). Not weekStart() of a date
     * a week ago — that maps a Sunday to the next week, so on a Sunday it
     * would return the week still running (found in CHG-0033 Cycle 7a).
     */
    public function lastConfirmedWeek(?CarbonInterface $now = null): string
    {
        return ($now ?? now())->copy()->startOfWeek(CarbonInterface::MONDAY)->subWeek()->toDateString();
    }

    public function weekStart(string $ymd): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        if ($date === false || $date->format('Y-m-d') !== $ymd) {
            throw new InvalidArgumentException("Invalid date: {$ymd}");
        }

        $shifted = $date->modify('+1 day');

        return $shifted
            ->setISODate((int) $shifted->format('o'), (int) $shifted->format('W'), 1)
            ->format('Y-m-d');
    }

    /**
     * Keeps only the first row of each Monday-start week, in original order.
     *
     * @param  array<int, array{date: string, close: float, volume: int}>  $history
     * @return list<array{date: string, close: float, volume: int}>
     */
    public function foldByWeek(array $history): array
    {
        $byWeek = [];

        foreach ($history as $row) {
            $byWeek[$this->weekStart($row['date'])] ??= $row;
        }

        return array_values($byWeek);
    }
}
