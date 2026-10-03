<?php

namespace App\Services\SignalOutcome;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * UC-014 / ADR-0017 D7: verdict on whether a signal source works.
 * Samples are observed weeks (CHG-0020 Cycle5, D7 revision): occurrences in the same
 * week move together, so pending also requires enough distinct matured weeks.
 * Priority: suspicious > pending > sign check (provisional before 3 years accumulated,
 * otherwise confirmed by the yearly means of the 3 most recent years).
 */
final class SignalOutcomeVerdictEvaluator
{
    /** |mean excess return| (%) above which the result is treated as suspicious, per horizon (weeks). */
    private const SUSPICIOUS_THRESHOLDS = [4 => 10.0, 13 => 20.0, 26 => 30.0];

    private const MIN_MATURED_COUNT = 30;

    /** Required matured weeks = max(MIN_MATURED_WEEKS, MATURED_WEEKS_PER_HORIZON × horizon weeks). */
    private const MIN_MATURED_WEEKS = 13;

    private const MATURED_WEEKS_PER_HORIZON = 3;

    /** |t| must exceed this to be significant. */
    private const T_THRESHOLD = 2.0;

    private const ACCUMULATION_YEARS = 3;

    private const RECENT_YEARS = 3;

    private const MIN_MATCHING_YEARS = 2;

    private const BUY_SOURCES = ['buy', 'watchlist_buy'];

    private const TAKE_PROFIT_SOURCE = 'take_profit';

    /**
     * @param  array<int, float>  $yearlyMeans  year => mean excess return (%)
     * @return array{verdict: string, provisional: bool}
     */
    public function evaluate(
        string $source,
        int $horizonWeeks,
        int $maturedCount,
        int $maturedWeekCount,
        ?float $mean,
        ?float $tValue,
        string $firstObservedWeek,
        string $asOfWeek,
        array $yearlyMeans,
    ): array {
        if (! array_key_exists($horizonWeeks, self::SUSPICIOUS_THRESHOLDS)) {
            throw new InvalidArgumentException("Unsupported horizon: {$horizonWeeks}");
        }

        $isBuy = in_array($source, self::BUY_SOURCES, true);

        if (! $isBuy && $source !== self::TAKE_PROFIT_SOURCE) {
            throw new InvalidArgumentException("Unknown source: {$source}");
        }

        if ($mean !== null && abs($mean) > self::SUSPICIOUS_THRESHOLDS[$horizonWeeks]) {
            return $this->verdict('suspicious');
        }

        if ($mean === null
            || $maturedCount < self::MIN_MATURED_COUNT
            || $maturedWeekCount < $this->requiredMaturedWeeks($horizonWeeks)
            || $tValue === null
            || abs($tValue) <= self::T_THRESHOLD) {
            return $this->verdict('pending');
        }

        if (! $this->hasExpectedSign($mean, $isBuy)) {
            return $this->verdict('not_working');
        }

        if (! $this->hasAccumulatedYears($firstObservedWeek, $asOfWeek)) {
            return $this->verdict('working', true);
        }

        krsort($yearlyMeans);
        $recent = array_slice($yearlyMeans, 0, self::RECENT_YEARS, true);
        $matches = count(array_filter($recent, fn (float $m) => $this->hasExpectedSign($m, $isBuy)));

        return $this->verdict($matches >= self::MIN_MATCHING_YEARS ? 'working' : 'not_working');
    }

    private function requiredMaturedWeeks(int $horizonWeeks): int
    {
        return max(self::MIN_MATURED_WEEKS, self::MATURED_WEEKS_PER_HORIZON * $horizonWeeks);
    }

    private function hasExpectedSign(float $value, bool $isBuy): bool
    {
        return $isBuy ? $value > 0 : $value < 0;
    }

    private function hasAccumulatedYears(string $firstObservedWeek, string $asOfWeek): bool
    {
        $threshold = (new DateTimeImmutable($firstObservedWeek))->modify('+'.self::ACCUMULATION_YEARS.' years');

        return $threshold <= new DateTimeImmutable($asOfWeek);
    }

    /**
     * @return array{verdict: string, provisional: bool}
     */
    private function verdict(string $verdict, bool $provisional = false): array
    {
        return ['verdict' => $verdict, 'provisional' => $provisional];
    }
}
