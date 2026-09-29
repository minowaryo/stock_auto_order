<?php

namespace Tests\Unit\Support;

use App\Support\DisplayTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| DisplayTime — Red phase Unit Test (CHG-0024, ADR-0018)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0018-display-timezone-manila.md D1〜D3
|   - docs/product/ui-guidelines.md「日時・日付の表示」
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Support\DisplayTime (static helper)
|       dateTime(?CarbonInterface $at): ?string  … 'Y-m-d H:i' in the display tz, null → null
|       date(?CarbonInterface $at): ?string      … 'Y-m-d' in the display tz, null → null
|       timezone(): string                       … config('app.display_timezone')
|   - config('app.display_timezone') defaults to 'Asia/Manila' (env APP_DISPLAY_TIMEZONE)
|   - storage / app timezone stays UTC; the given instance is never mutated
|
| Not yet implemented: the class and the config key. Expected Red:
| "Class "App\Support\DisplayTime" not found".
|
*/

uses(TestCase::class);

test('表示タイムゾーンの既定値はAsia/Manilaである', function () {
    expect(config('app.display_timezone'))->toBe('Asia/Manila');
    expect(DisplayTime::timezone())->toBe('Asia/Manila');
});

test('UTCの日時はマニラ時間（UTC+8）のY-m-d H:iで表示される', function () {
    $at = Carbon::parse('2026-09-27 02:00:00', 'UTC');

    expect(DisplayTime::dateTime($at))->toBe('2026-09-27 10:00');
});

test('UTC16:00以降の日時はマニラ時間では翌日の日時・日付になる（日付の境界）', function () {
    $at = Carbon::parse('2026-09-26 16:30:00', 'UTC');

    expect(DisplayTime::dateTime($at))->toBe('2026-09-27 00:30');
    expect(DisplayTime::date($at))->toBe('2026-09-27');
});

test('UTC15:59の日時はマニラ時間でもまだ同じ日付である（日付の境界の直前）', function () {
    $at = Carbon::parse('2026-09-26 15:59:00', 'UTC');

    expect(DisplayTime::dateTime($at))->toBe('2026-09-26 23:59');
    expect(DisplayTime::date($at))->toBe('2026-09-26');
});

test('nullを渡すとnullを返す', function () {
    expect(DisplayTime::dateTime(null))->toBeNull();
    expect(DisplayTime::date(null))->toBeNull();
});

test('設定値app.display_timezoneを変更すると表示タイムゾーンが切り替わる', function () {
    config(['app.display_timezone' => 'Asia/Tokyo']);
    $at = Carbon::parse('2026-09-26 16:30:00', 'UTC');

    expect(DisplayTime::timezone())->toBe('Asia/Tokyo');
    expect(DisplayTime::dateTime($at))->toBe('2026-09-27 01:30');
    expect(DisplayTime::date($at))->toBe('2026-09-27');
});

test('変換しても渡した日時インスタンスはUTCのまま変更されない', function () {
    $at = Carbon::parse('2026-09-26 16:30:00', 'UTC');

    DisplayTime::dateTime($at);
    DisplayTime::date($at);

    expect($at->getTimezone()->getName())->toBe('UTC');
    expect($at->format('Y-m-d H:i'))->toBe('2026-09-26 16:30');
});
