<?php

namespace App\Services\SignalOutcome;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * UC-014 / ADR-0017 D4/D5: excess return (%) of a stock over the index across a horizon.
 * Inputs are split-adjusted weekly closes keyed by Monday 'Y-m-d'. A target week at or after
 * asOfWeek is still in progress, so the outcome is pending.
 */
final class ExcessReturnCalculator
{
    private const DAYS_PER_WEEK = 7;

    /**
     * @param  array<string, float>  $stockCloses
     * @param  array<string, float>  $indexCloses
     * @return array{status: string, stock_return: ?float, index_return: ?float, excess_return: ?float}
     */
    public function calculate(
        string $observedWeek,
        int $horizonWeeks,
        array $stockCloses,
        array $indexCloses,
        string $asOfWeek,
    ): array {
        if ($horizonWeeks <= 0) {
            throw new InvalidArgumentException('horizonWeeks must be positive.');
        }

        $targetWeek = (new DateTimeImmutable($observedWeek))
            ->modify('+'.($horizonWeeks * self::DAYS_PER_WEEK).' days')
            ->format('Y-m-d');

        if ($targetWeek >= $asOfWeek) {
            return $this->empty('pending');
        }

        $stockReturn = $this->returnPercent($stockCloses, $observedWeek, $targetWeek);
        $indexReturn = $this->returnPercent($indexCloses, $observedWeek, $targetWeek);

        if ($stockReturn === null || $indexReturn === null) {
            return $this->empty('unavailable');
        }

        return [
            'status' => 'matured',
            'stock_return' => $stockReturn,
            'index_return' => $indexReturn,
            'excess_return' => $stockReturn - $indexReturn,
        ];
    }

    /**
     * @param  array<string, float>  $closes
     */
    private function returnPercent(array $closes, string $fromWeek, string $toWeek): ?float
    {
        $from = $closes[$fromWeek] ?? null;
        $to = $closes[$toWeek] ?? null;

        if ($from === null || $to === null || $from <= 0 || $to <= 0) {
            return null;
        }

        return ($to / $from - 1) * 100;
    }

    /**
     * @return array{status: string, stock_return: null, index_return: null, excess_return: null}
     */
    private function empty(string $status): array
    {
        return [
            'status' => $status,
            'stock_return' => null,
            'index_return' => null,
            'excess_return' => null,
        ];
    }
}
