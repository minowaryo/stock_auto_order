<?php

namespace App\Services\SignalOutcome;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Normalizes Yahoo Finance weekly bar dates to the Monday week start
 * (UC-014, ADR-0017 D2, docs/architecture/data-model.md `weekly_prices`).
 *
 * JP / ^N225 bars come stamped on Sunday (= Monday 00:00 JST), US / ^GSPC
 * bars on Monday, and a trailing last-trading-day bar may follow. Shifting
 * by +1 day and taking the ISO-week Monday folds all of them into the same
 * week.
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
}
