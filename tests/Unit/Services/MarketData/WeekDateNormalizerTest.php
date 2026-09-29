<?php

namespace Tests\Unit\Services\MarketData;

use App\Services\MarketData\WeekDateNormalizer;

/*
|--------------------------------------------------------------------------
| WeekDateNormalizer — Red phase Unit Test (UC-014 / F-014, CHG-0020 Cycle1)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D2
|   - docs/architecture/data-model.md `weekly_prices`
|     「Gate4で実測確認する事項」(週の基準日への正規化)
|
| Measured against Yahoo Finance on 2026-09-27:
|   - JP stocks / ^N225 weekly bars are stamped Sunday 15:00 UTC
|     (= Monday 00:00 JST), so gmdate('Y-m-d') yields a SUNDAY
|     (2026-09-20 means the week starting Mon 2026-09-21).
|   - US stocks / ^GSPC weekly bars yield the MONDAY itself (2026-09-21).
|   - Yahoo may append a trailing bar for the last trading day
|     (e.g. Fri 2026-09-25) that belongs to the same week.
|
| Contract proposed here (flag at Gate 4 if a different shape is preferred):
|   weekStart(string $ymd): string = Monday of the ISO week containing
|   ($ymd + 1 day), formatted Y-m-d. Pure, no DB/HTTP dependency. Called on
|   an instance so it works whether the implementation is static or not.
|
| Relocated (CHG-0020 /review fix): the normalizer is shared by the generic
| MarketData layer (YahooFinanceChartClient) and SignalOutcome
| (WeeklyPriceRecorder), so it lives in App\Services\MarketData to avoid
| MarketData depending on the SignalOutcome namespace. It also exposes
| foldByWeek() so both callers apply ONE dedupe rule (first row per
| Monday-start week wins) instead of two diverging ones.
|
|   foldByWeek(array $history): array
|     $history = array<int, array{date: string, close: float, volume: int}>
|     returns a 0-indexed list in the original order, keeping only the FIRST
|     row of each weekStart() week.
|
| Expected Red: "Class App\Services\MarketData\WeekDateNormalizer not found"
| until the class is moved, then "undefined method foldByWeek" until added.
|
*/

test('JP株・日経平均の日曜日付（月曜0時JST相当）は翌日の月曜に正規化される', function () {
    // Arrange
    $normalizer = new WeekDateNormalizer;

    // Act
    $result = $normalizer->weekStart('2026-09-20');

    // Assert
    expect($result)->toBe('2026-09-21');
});

test('US株・S&P500の月曜日付はその月曜のまま', function () {
    $normalizer = new WeekDateNormalizer;

    expect($normalizer->weekStart('2026-09-21'))->toBe('2026-09-21');
});

test('週末尾に付く最終取引日（金曜）の足は同じ週の月曜に正規化される', function () {
    $normalizer = new WeekDateNormalizer;

    expect($normalizer->weekStart('2026-09-25'))->toBe('2026-09-21');
});

test('土曜日付は同じ週の月曜に正規化される', function () {
    $normalizer = new WeekDateNormalizer;

    expect($normalizer->weekStart('2026-09-26'))->toBe('2026-09-21');
});

test('年末の日曜日付は年をまたがず翌日の月曜（2026-12-28）に正規化される', function () {
    $normalizer = new WeekDateNormalizer;

    expect($normalizer->weekStart('2026-12-27'))->toBe('2026-12-28');
});

test('年明けの金曜日付は前年末の月曜（2026-12-28）に正規化される', function () {
    $normalizer = new WeekDateNormalizer;

    expect($normalizer->weekStart('2027-01-01'))->toBe('2026-12-28');
});

describe('foldByWeek（同一週の行を週の最初の行に畳み込む）', function () {
    test('末尾に同一週の最終取引日の行がある場合、末尾の行は除外され週足の行が残る', function () {
        // Arrange: JP-style Sunday stamps + trailing Friday bar of the last week
        $normalizer = new WeekDateNormalizer;
        $history = [
            ['date' => '2026-09-13', 'close' => 2950.0, 'volume' => 52000000],
            ['date' => '2026-09-20', 'close' => 2989.5, 'volume' => 54901300],
            ['date' => '2026-09-25', 'close' => 2989.5, 'volume' => 21084000],
        ];

        // Act
        $result = $normalizer->foldByWeek($history);

        // Assert
        expect($result)->toBe([
            ['date' => '2026-09-13', 'close' => 2950.0, 'volume' => 52000000],
            ['date' => '2026-09-20', 'close' => 2989.5, 'volume' => 54901300],
        ]);
    });

    test('系列の途中に同一週の重複行がある場合も除外され、後続の新しい週の行は残る', function () {
        // Arrange: trailing Fri 09-25 bar sits in the MIDDLE because the next
        // week's Sunday bar (09-27) follows it
        $normalizer = new WeekDateNormalizer;
        $history = [
            ['date' => '2026-09-13', 'close' => 2950.0, 'volume' => 52000000],
            ['date' => '2026-09-20', 'close' => 2989.5, 'volume' => 54901300],
            ['date' => '2026-09-25', 'close' => 2989.5, 'volume' => 21084000],
            ['date' => '2026-09-27', 'close' => 3010.0, 'volume' => 48000000],
        ];

        // Act
        $result = $normalizer->foldByWeek($history);

        // Assert
        expect($result)->toBe([
            ['date' => '2026-09-13', 'close' => 2950.0, 'volume' => 52000000],
            ['date' => '2026-09-20', 'close' => 2989.5, 'volume' => 54901300],
            ['date' => '2026-09-27', 'close' => 3010.0, 'volume' => 48000000],
        ]);
    });

    test('空配列を渡すと空配列を返す', function () {
        $normalizer = new WeekDateNormalizer;

        expect($normalizer->foldByWeek([]))->toBe([]);
    });

    test('戻り値は元の順序を保った0始まりの連番リストで、日付キーの連想配列にならない', function () {
        // Arrange: US-style Monday stamps with a mid-series same-week duplicate
        $normalizer = new WeekDateNormalizer;
        $history = [
            ['date' => '2026-09-07', 'close' => 330.0, 'volume' => 150000000],
            ['date' => '2026-09-11', 'close' => 331.0, 'volume' => 30000000],
            ['date' => '2026-09-14', 'close' => 335.5, 'volume' => 155000000],
            ['date' => '2026-09-21', 'close' => 341.07, 'volume' => 162053000],
        ];

        // Act
        $result = $normalizer->foldByWeek($history);

        // Assert
        expect(array_keys($result))->toBe([0, 1, 2]);
        expect(array_column($result, 'date'))->toBe(['2026-09-07', '2026-09-14', '2026-09-21']);
    });
});
