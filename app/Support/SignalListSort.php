<?php

namespace App\Support;

/**
 * CHG-0027: 売買シグナル3一覧（利確検討・買い増し候補・整理検討）の並び順キー。
 *
 * RECOMMENDED は各一覧が従来から持つ透明マルチキー（既定。JSON API と
 * UC-013 の ClassifyHoldingsAction はこれに依存する）、MARKET_VALUE は
 * 評価額の大きい順（同額は RECOMMENDED で決着）。
 */
final class SignalListSort
{
    public const RECOMMENDED = 'recommended';

    public const MARKET_VALUE = 'market_value';

    public static function isValid(string $sort): bool
    {
        return in_array($sort, [self::RECOMMENDED, self::MARKET_VALUE], true);
    }

    /**
     * @param  callable(array<string, mixed>, array<string, mixed>): int  $recommended
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    public static function comparator(string $sort, callable $recommended): callable
    {
        if ($sort !== self::MARKET_VALUE) {
            return $recommended;
        }

        return fn (array $a, array $b) => ((float) $b['market_value'] <=> (float) $a['market_value'])
            ?: $recommended($a, $b);
    }
}
