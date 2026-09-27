<?php

namespace Tests\Unit\Services\SignalOutcome;

use App\Services\SignalOutcome\WeekDateNormalizer;

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
| App\Services\SignalOutcome\WeekDateNormalizer does not exist yet, so every
| test below is expected to fail with "Class ... not found" (intended Red).
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
