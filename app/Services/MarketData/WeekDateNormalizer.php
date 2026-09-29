<?php

namespace App\Services\MarketData;

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
