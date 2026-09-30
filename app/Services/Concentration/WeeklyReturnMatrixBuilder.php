<?php

namespace App\Services\Concentration;

use DateTimeImmutable;
use DateTimeZone;

final class WeeklyReturnMatrixBuilder
{
    /**
     * @param  list<array{holding_id: int, instrument_type: string}>  $candidates
     * @param  array<int, array<string, float|int>>  $closesByHolding  holding_id => [Monday Y-m-d => close]
     * @param  array<string, float|int>  $soxCloses
     * @return array{window_start: ?string, window_end: ?string, returns: array<int, list<float>>, sox_returns: ?list<float>, excluded: array<int, string>}
     */
    public function build(array $candidates, array $closesByHolding, array $soxCloses, int $weeks = 52): array
    {
        $latest = null;
        foreach ($candidates as $candidate) {
            if ($candidate['instrument_type'] !== 'stock') {
                continue;
            }
            $series = $closesByHolding[$candidate['holding_id']] ?? [];
            if ($series !== []) {
                $max = max(array_keys($series));
                $latest = $latest === null || $max > $latest ? $max : $latest;
            }
        }

        $window = [];
        if ($latest !== null) {
            $end = new DateTimeImmutable($latest, new DateTimeZone('UTC'));
            for ($i = $weeks; $i >= 0; $i--) {
                $window[] = $end->modify('-'.($i * 7).' days')->format('Y-m-d');
            }
        }

        $returns = [];
        $excluded = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['holding_id'];
            if ($candidate['instrument_type'] !== 'stock') {
                $excluded[$id] = 'not_stock';

                continue;
            }
            $weekly = $this->weeklyReturns($closesByHolding[$id] ?? [], $window);
            if ($weekly === null) {
                $excluded[$id] = 'insufficient_history';
            } else {
                $returns[$id] = $weekly;
            }
        }

        return [
            'window_start' => $window[0] ?? null,
            'window_end' => $window === [] ? null : $window[count($window) - 1],
            'returns' => $returns,
            'sox_returns' => $this->weeklyReturns($soxCloses, $window),
            'excluded' => $excluded,
        ];
    }

    /**
     * @param  array<string, float|int>  $series
     * @param  list<string>  $window
     * @return ?list<float>
     */
    private function weeklyReturns(array $series, array $window): ?array
    {
        if ($window === []) {
            return null;
        }

        $closes = [];
        foreach ($window as $monday) {
            if (! isset($series[$monday]) || $series[$monday] <= 0) {
                return null;
            }
            $closes[] = (float) $series[$monday];
        }

        $returns = [];
        for ($i = 1, $n = count($closes); $i < $n; $i++) {
            $returns[] = $closes[$i] / $closes[$i - 1] - 1;
        }

        return $returns;
    }
}
