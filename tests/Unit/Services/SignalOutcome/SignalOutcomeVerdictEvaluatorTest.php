<?php

use App\Services\SignalOutcome\SignalOutcomeVerdictEvaluator;

/*
| SignalOutcomeVerdictEvaluator — Red phase Unit Test (UC-014 / CHG-0020 Cycle3, ADR-0017 D7)
| Expected Red: Class "App\Services\SignalOutcome\SignalOutcomeVerdictEvaluator" not found.
|
| CHG-0020 Cycle5 (ADR-0017 D7 改訂): new 4th parameter maturedWeekCount (distinct observed weeks
| among matured occurrences). Expected Red: ArgumentCountError / shifted arguments until implemented.
|
| Priority: suspicious (|mean| > 10/20/30 for +4/+13/+26 weeks, regardless of counts)
| > pending (matured < 30, matured weeks < max(13, 3 × horizon) i.e. +4w 13 / +13w 39 / +26w 78,
|   or |t| <= 2.0)
| > before 3 years accumulated: expected sign → working (provisional) / opposite → not_working
| > after 3 years: expected sign AND >= 2 of the 3 most recent yearly means with expected sign → working.
| Accumulated 3 years ⇔ firstObservedWeek + 3 years <= asOfWeek.
*/

// 1 year accumulated (provisional window)
const SOVE_FIRST = '2025-09-29';
const SOVE_AS_OF = '2026-09-28';
// More than 3 years accumulated
const SOVE_FIRST_OLD = '2023-01-02';

function sove(): SignalOutcomeVerdictEvaluator
{
    return new SignalOutcomeVerdictEvaluator;
}

dataset('just above suspicious threshold', [
    '+4週 10.01' => [4, 10.01],
    '+4週 -10.01' => [4, -10.01],
    '+13週 20.01' => [13, 20.01],
    '+13週 -20.01' => [13, -20.01],
    '+26週 30.01' => [26, 30.01],
    '+26週 -30.01' => [26, -30.01],
]);

test('UC-014: 平均超過リターンの絶対値が閾値超なら異常を疑う', function (int $horizon, float $mean) {
    $result = sove()->evaluate('buy', $horizon, 100, 100, $mean, 5.0, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'suspicious', 'provisional' => false]);
})->with('just above suspicious threshold');

dataset('exactly at suspicious threshold', [
    '+4週 10.0' => [4, 10.0],
    '+13週 20.0' => [13, 20.0],
    '+26週 30.0' => [26, 30.0],
]);

test('UC-014: 平均超過リターンの絶対値がちょうど閾値なら異常扱いせず次の判定に進む', function (int $horizon, float $mean) {
    $result = sove()->evaluate('buy', $horizon, 100, 100, $mean, 5.0, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'working', 'provisional' => true]);
})->with('exactly at suspicious threshold');

test('UC-014: 異常を疑うは件数不足の判断保留より優先する', function () {
    $result = sove()->evaluate('buy', 4, 3, 100, 15.0, null, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'suspicious', 'provisional' => false]);
});

test('UC-014: 結果到来が29件ならt値が大きくても判断保留', function () {
    $result = sove()->evaluate('buy', 4, 29, 100, 3.0, 5.0, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'pending', 'provisional' => false]);
});

dataset('one week short of required matured weeks', [
    '+4週 12週（必要13週）' => [4, 12],
    '+13週 38週（必要39週）' => [13, 38],
    '+26週 77週（必要78週）' => [26, 77],
]);

test('UC-014: 結果が出た発生週が評価期間の3倍（最低13週）に1週足りなければt値が大きくても判断保留', function (int $horizon, int $weeks) {
    $result = sove()->evaluate('buy', $horizon, 100, $weeks, 3.0, 2.5, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'pending', 'provisional' => false]);
})->with('one week short of required matured weeks');

dataset('exactly required matured weeks', [
    '+4週 13週' => [4, 13],
    '+13週 39週' => [13, 39],
    '+26週 78週' => [26, 78],
]);

test('UC-014: 結果が出た発生週がちょうど評価期間の3倍（最低13週）で符号が期待通り・有意なら機能している（暫定）', function (int $horizon, int $weeks) {
    $result = sove()->evaluate('buy', $horizon, 100, $weeks, 3.0, 2.5, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'working', 'provisional' => true]);
})->with('exactly required matured weeks');

test('UC-014: 異常を疑うは発生週不足（1週）の判断保留より優先する', function () {
    $result = sove()->evaluate('take_profit', 4, 30, 1, 15.0, 5.0, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'suspicious', 'provisional' => false]);
});

test('UC-014: t値がちょうど2.0なら判断保留', function () {
    $result = sove()->evaluate('buy', 4, 30, 100, 3.0, 2.0, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'pending', 'provisional' => false]);
});

test('UC-014: t値がちょうど-2.0なら判断保留', function () {
    $result = sove()->evaluate('take_profit', 4, 30, 100, -3.0, -2.0, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'pending', 'provisional' => false]);
});

test('UC-014: t値がnullなら判断保留', function () {
    $result = sove()->evaluate('buy', 4, 30, 100, 3.0, null, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'pending', 'provisional' => false]);
});

test('UC-014: 買い増しで符号が期待通り・有意・30件なら蓄積3年未満は機能している（暫定）', function () {
    $result = sove()->evaluate('buy', 4, 30, 100, 3.0, 2.5, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'working', 'provisional' => true]);
});

test('UC-014: ウォッチリスト押し目買いで符号が期待通り・有意なら機能している（暫定）', function () {
    $result = sove()->evaluate('watchlist_buy', 13, 30, 100, 3.0, 2.5, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'working', 'provisional' => true]);
});

test('UC-014: 利確検討で平均がマイナス・有意なら機能している（暫定）', function () {
    $result = sove()->evaluate('take_profit', 26, 30, 100, -3.0, -2.5, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'working', 'provisional' => true]);
});

test('UC-014: 買い増しで平均がマイナス・有意なら機能していない', function () {
    $result = sove()->evaluate('buy', 4, 30, 100, -3.0, -2.5, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'not_working', 'provisional' => false]);
});

test('UC-014: 利確検討で平均がプラス・有意なら機能していない', function () {
    $result = sove()->evaluate('take_profit', 4, 30, 100, 3.0, 2.5, SOVE_FIRST, SOVE_AS_OF, []);

    expect($result)->toBe(['verdict' => 'not_working', 'provisional' => false]);
});

test('UC-014: 蓄積3年以上で直近3年中2年の符号が一致すれば機能している（確定）', function () {
    $result = sove()->evaluate('buy', 4, 120, 100, 3.0, 2.5, SOVE_FIRST_OLD, SOVE_AS_OF, [
        2024 => 4.0,
        2025 => 2.0,
        2026 => -1.0,
    ]);

    expect($result)->toBe(['verdict' => 'working', 'provisional' => false]);
});

test('UC-014: 蓄積3年以上で直近3年中1年しか符号が一致しなければ機能していない', function () {
    $result = sove()->evaluate('buy', 4, 120, 100, 3.0, 2.5, SOVE_FIRST_OLD, SOVE_AS_OF, [
        2024 => 9.0,
        2025 => -1.0,
        2026 => -0.5,
    ]);

    expect($result)->toBe(['verdict' => 'not_working', 'provisional' => false]);
});

test('UC-014: 蓄積3年以上の利確検討は年平均がマイナスの年を一致として数える', function () {
    $result = sove()->evaluate('take_profit', 13, 120, 100, -3.0, -2.5, SOVE_FIRST_OLD, SOVE_AS_OF, [
        2024 => -2.0,
        2025 => 1.0,
        2026 => -4.0,
    ]);

    expect($result)->toBe(['verdict' => 'working', 'provisional' => false]);
});

test('UC-014: 蓄積3年以上でも全体の符号が期待と逆なら機能していない', function () {
    $result = sove()->evaluate('buy', 4, 120, 100, -3.0, -2.5, SOVE_FIRST_OLD, SOVE_AS_OF, [
        2024 => 1.0,
        2025 => 1.0,
        2026 => 1.0,
    ]);

    expect($result)->toBe(['verdict' => 'not_working', 'provisional' => false]);
});

test('UC-014: 年次の符号は直近3年だけで数え、それより古い年は数えない', function () {
    // Counting all 4 years would give 2 matches (2023, 2024); the 3 most recent give only 1.
    // Keys are deliberately unordered.
    $result = sove()->evaluate('buy', 4, 120, 100, 3.0, 2.5, SOVE_FIRST_OLD, SOVE_AS_OF, [
        2026 => -1.0,
        2023 => 5.0,
        2025 => -2.0,
        2024 => 4.0,
    ]);

    expect($result)->toBe(['verdict' => 'not_working', 'provisional' => false]);
});

test('UC-014: 初回発生からちょうど3年で蓄積3年とみなし年次の符号を判定に使う', function () {
    // firstObservedWeek + 3 years == asOfWeek (calendar boundary of the `<=` rule).
    // Yearly signs fail (1 of 3), so a non-provisional not_working proves the 3-year branch was taken.
    $result = sove()->evaluate('buy', 4, 60, 100, 3.0, 2.5, '2023-10-02', '2026-10-02', [
        2024 => 1.0,
        2025 => -1.0,
        2026 => -1.0,
    ]);

    expect($result)->toBe(['verdict' => 'not_working', 'provisional' => false]);
});

test('UC-014: 初回発生から3年に1週足りなければ暫定判定のまま', function () {
    $result = sove()->evaluate('buy', 4, 60, 100, 3.0, 2.5, '2023-10-02', '2026-09-25', [
        2024 => 1.0,
        2025 => -1.0,
        2026 => -1.0,
    ]);

    expect($result)->toBe(['verdict' => 'working', 'provisional' => true]);
});

test('UC-014: 評価期間が4/13/26以外なら例外', function () {
    sove()->evaluate('buy', 8, 30, 100, 3.0, 2.5, SOVE_FIRST, SOVE_AS_OF, []);
})->throws(InvalidArgumentException::class);

test('UC-014: 未知のsourceは例外', function () {
    sove()->evaluate('sell', 4, 30, 100, 3.0, 2.5, SOVE_FIRST, SOVE_AS_OF, []);
})->throws(InvalidArgumentException::class);
