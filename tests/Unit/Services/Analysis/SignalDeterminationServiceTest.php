<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\SignalDeterminationService;
use App\Services\Analysis\TechnicalIndicatorCalculator;

/*
|--------------------------------------------------------------------------
| SignalDeterminationService — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0004-analysis-engine-indicator-expansion.md
|   - docs/architecture/data-model.md (`signals` table, `signal_type` enum)
|   - docs/product/use-cases.md (UC-004 利確シグナル一覧)
|   - Task description given for this Red-phase generation (7 signal_type
|     determination rules, using TechnicalIndicatorCalculator called twice
|     — "this week" / "one week ago" — since `technical_indicators` only
|     caches the latest value and has no history of its own).
|
| App\Services\Analysis\SignalDeterminationService does not exist yet (no
| file under app/Services/Analysis/ with that name). Every test below is
| expected to fail with a fatal "Class \"App\Services\Analysis\
| SignalDeterminationService\" not found" error at the `new
| SignalDeterminationService(...)` line inside the Act step — this is the
| intentional, expected Red state (same convention as
| tests/Unit/Services/Analysis/TechnicalIndicatorCalculatorTest.php before
| that class existed).
|
| This class is pure calculation logic on top of the already-implemented,
| already-Green TechnicalIndicatorCalculator (no DB/HTTP dependency), so
| this is a Unit Test with no RefreshDatabase.
|
| Fixture verification methodology (important for Gate 4 review):
|   All price/volume fixtures below were numerically verified against the
|   real (already-Green) TechnicalIndicatorCalculator via an independent
|   reference script BEFORE being hard-coded here (same practice as
|   TechnicalIndicatorCalculatorTest's MACD fixtures) — this test suite
|   does not re-derive TechnicalIndicatorCalculator's own math by hand;
|   it trusts that already-tested dependency and focuses on verifying
|   SignalDeterminationService's own comparison/threshold logic layered
|   on top of it (previous-vs-current comparison, >=/< boundaries, null
|   guards, reason_summary content).
|
| Assertion style:
|   - For signal_type "FIRE" tests where the fixture is not guaranteed to
|     be the *only* signal that could technically fire for that price
|     history (e.g. a long series that happens to also satisfy another
|     signal's threshold), assertions use a "does the expected signal
|     appear in the result, with the right reason_summary content"
|     pattern (sdFindSignal), NOT strict array equality — this keeps the
|     test focused on the rule under test without requiring incidental
|     full-fixture isolation from every other rule.
|   - For fixtures that were verified to produce *no other* signal at all
|     (calm baseline, insufficient-data, the two short 14/25-point
|     isolated fixtures, and the multi-signal combo case), assertions use
|     exact equality / toEqualCanonicalizing for a stronger guarantee.
|   - reason_summary assertions only pin down values that are unambiguous
|     regardless of the implementation's exact rounding/formatting choice
|     (e.g. round(92.857...) can only reasonably be "93" under any normal
|     rounding rule; whole-number fixture values like week52_high=150 need
|     no rounding at all). Where the task gave no concrete reason_summary
|     wording example (macd_dead_cross, bollinger_overheat), assertions
|     only check for a relevant Japanese keyword, not exact phrasing.
|
*/

/**
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function sdPriceHistory(array $closes, int|array $volumes = 1000, string $startDate = '2024-01-01'): array
{
    $history = [];
    $date = new \DateTimeImmutable($startDate);

    foreach ($closes as $i => $close) {
        $volume = is_array($volumes) ? $volumes[$i] : $volumes;

        $history[] = [
            'date' => $date->modify("+{$i} weeks")->format('Y-m-d'),
            'close' => (float) $close,
            'volume' => (int) $volume,
        ];
    }

    return $history;
}

/**
 * Builds an arithmetic close-price sequence: [$start, $start+$step, ..., $start+$steps*$step]
 * ($steps+1 elements total).
 *
 * @return array<int, float>
 */
function sdLinearCloses(float $start, float $step, int $steps): array
{
    $closes = [$start];

    for ($i = 0; $i < $steps; $i++) {
        $closes[] = end($closes) + $step;
    }

    return $closes;
}

/**
 * @param  array<int, array{signal_type: string, reason_summary: string}>  $signals
 * @return array{signal_type: string, reason_summary: string}|null
 */
function sdFindSignal(array $signals, string $signalType): ?array
{
    foreach ($signals as $signal) {
        if ($signal['signal_type'] === $signalType) {
            return $signal;
        }
    }

    return null;
}

/**
 * @param  array<int, array{signal_type: string, reason_summary: string}>  $signals
 * @return array<int, string>
 */
function sdSignalTypes(array $signals): array
{
    return array_column($signals, 'signal_type');
}

function sdService(): SignalDeterminationService
{
    return new SignalDeterminationService(new TechnicalIndicatorCalculator);
}

// -----------------------------------------------------------------------
// 1. rsi_reversal（RSI高水準からの反落）
// -----------------------------------------------------------------------

test('RSIが70以上から反落した場合、rsi_reversalシグナルが発生する', function () {
    // Arrange: 15週分は連続上昇（previousのRSIウィンドウは全て陽線 -> avg_loss=0 -> RSI=100）、
    // 16週目だけ下落させて反落を作る。previous rsi=100, current rsi≈92.857 (verified).
    $closes = array_merge(range(100, 114), [113]);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    $signal = sdFindSignal($result, 'rsi_reversal');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('100')->toContain('93');
});

test('RSIが上昇を続けている場合（反落していない）、rsi_reversalシグナルは発生しない', function () {
    // Arrange: 16週連続で毎週+1、反落なし（previous rsi=100, current rsi=100, not <）
    $closes = range(100, 115);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    expect(sdSignalTypes($result))->not->toContain('rsi_reversal');
});

test('直近のRSIが70未満の場合、その後下落してもrsi_reversalシグナルは発生しない（境界値）', function () {
    // Arrange: previous rsi≈69.23（70未満）, current rsi≈61.54（さらに下落）
    // previousが70未満のガードにより、下落幅が大きくても反落シグナルは出ない想定
    $closes = [100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 109, 108, 107, 106, 105, 104];
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    expect(sdSignalTypes($result))->not->toContain('rsi_reversal');
});

// -----------------------------------------------------------------------
// 2. macd_dead_cross（MACDデッドクロス）
// -----------------------------------------------------------------------

test('MACDがシグナル線を下抜けした場合、macd_dead_crossシグナルが発生する', function () {
    // Arrange: 40週連続+3の上昇後、1週だけ-1の下落。
    // previous (macd-signal)=0（>=0のガードを満たす）, current (macd-signal)≈-0.255（<0）
    $closes = sdLinearCloses(100.0, 3.0, 40);
    $closes[] = end($closes) - 1.0;
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    $signal = sdFindSignal($result, 'macd_dead_cross');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('MACD');
});

test('上昇トレンドが継続している場合（デッドクロスしていない）、macd_dead_crossシグナルは発生しない', function () {
    // Arrange: 42週連続+3の上昇のみ（反転なし）。previous/current の(macd-signal)は共に0で、
    // current < 0 を満たさないため発生しない
    $closes = sdLinearCloses(100.0, 3.0, 41);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    expect(sdSignalTypes($result))->not->toContain('macd_dead_cross');
});

test('前週時点で既にMACDがシグナル線を下回っている場合、さらに下落してもmacd_dead_crossシグナルは再発生しない（境界値）', function () {
    // Arrange: 上のFIREケースにもう1週分の下落を追加。previousの(macd-signal)は既に負（≈-0.255）
    // となり ">= 0" ガードを満たさないため、currentがさらに負でも発生しない
    $closes = sdLinearCloses(100.0, 3.0, 40);
    $closes[] = end($closes) - 1.0;
    $closes[] = end($closes) - 1.0;
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    expect(sdSignalTypes($result))->not->toContain('macd_dead_cross');
});

// -----------------------------------------------------------------------
// 3. bollinger_overheat（ボリンジャーバンド上限付近での過熱）
// -----------------------------------------------------------------------

test('終値がボリンジャーバンド上限を上回った場合、bollinger_overheatシグナルが発生する', function () {
    // Arrange: 19週は横ばい(100)、最終週だけ130まで急騰。bb_upper≈114.92 < 130（verified）
    $closes = array_merge(array_fill(0, 19, 100.0), [130.0]);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    $signal = sdFindSignal($result, 'bollinger_overheat');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('ボリンジャーバンド');
});

test('終値がボリンジャーバンド上限を下回っている場合、bollinger_overheatシグナルは発生しない', function () {
    // Arrange: 20週連続+1の緩やかな上昇。bb_upper≈121.33 > 119（終値, verified）
    $closes = range(100, 119);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    expect(sdSignalTypes($result))->not->toContain('bollinger_overheat');
});

// -----------------------------------------------------------------------
// 4. week52_high_pullback（52週高値からの反落）
// -----------------------------------------------------------------------

test('52週高値から10%以上下落した場合、week52_high_pullbackシグナルが発生する', function () {
    // Arrange: 51週で100->150まで上昇後、最終週に130まで下落。
    // week52_high=150, 閾値(90%)=135, 130<=135 -> 発生（verified）
    $closes = array_merge(range(100, 150), [130.0]);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    $signal = sdFindSignal($result, 'week52_high_pullback');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('150')->toContain('130');
});

test('52週高値からの下落率が10%未満の場合、week52_high_pullbackシグナルは発生しない（境界値）', function () {
    // Arrange: 同じ52週高値150に対し、最終週の終値を136に変更。
    // 閾値(90%)=135, 136>135 -> 発生しない（verified）
    $closes = array_merge(range(100, 150), [136.0]);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    expect(sdSignalTypes($result))->not->toContain('week52_high_pullback');
});

// -----------------------------------------------------------------------
// 5. peg_overvalued（PEG割高）
// -----------------------------------------------------------------------

test('PEGレシオが2.0以上の場合、peg_overvaluedシグナルが発生する（境界値）', function () {
    // Arrange: 80週分の緩やかな上昇（他のいかなるシグナルも発生しないことを確認済みの
    // 「穏やかな価格推移」フィクスチャ、後述の空配列テストと同一データ）+ PEGレシオちょうど2.0
    $closes = range(100, 179);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory, pegRatio: 2.0);

    // Assert: このフィクスチャは他のいかなるシグナルも発生しないため、peg_overvaluedのみが
    // 単独で含まれることまで厳密に確認する
    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('peg_overvalued');
    expect($result[0]['reason_summary'])->toContain('2');
});

test('PEGレシオが2.0未満の場合、peg_overvaluedシグナルは発生しない（境界値）', function () {
    // Arrange: 同じ「穏やかな価格推移」フィクスチャ + PEGレシオ1.99（閾値のすぐ手前）
    $closes = range(100, 179);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory, pegRatio: 1.99);

    // Assert
    expect($result)->toBe([]);
});

// -----------------------------------------------------------------------
// 6. relative_strength_weakening（相対力の低下）
// -----------------------------------------------------------------------

test('対市場の相対力がマイナスの場合、relative_strength_weakeningシグナルが発生する', function () {
    // Arrange: 14週で100->113（13週騰落率+13.0%）、市場の13週騰落率を+20.0%として
    // 相対力 = 13.0 - 20.0 = -7.0（対市場で劣後、verified）
    $closes = range(100, 113);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory, marketReturn13w: 20.0);

    // Assert: このフィクスチャ（14週分のみ）は他のいかなるシグナルの必要データ件数も
    // 満たさないため、relative_strength_weakeningのみが単独で発生することまで確認する
    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('relative_strength_weakening');
    expect($result[0]['reason_summary'])->toContain('-7');
});

test('対市場の相対力がちょうど0の場合、relative_strength_weakeningシグナルは発生しない（境界値）', function () {
    // Arrange: 市場の13週騰落率を+13.0%（銘柄自身の騰落率と同値）とし、相対力=0.0
    $closes = range(100, 113);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory, marketReturn13w: 13.0);

    // Assert
    expect($result)->toBe([]);
});

test('対市場の相対力がプラスの場合、relative_strength_weakeningシグナルは発生しない', function () {
    // Arrange: 市場の13週騰落率を+12.0%とし、相対力=13.0-12.0=+1.0（対市場で優位）
    $closes = range(100, 113);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory, marketReturn13w: 12.0);

    // Assert
    expect($result)->toBe([]);
});

// -----------------------------------------------------------------------
// 7. volume_spike_decline（出来高急増を伴う下落）
// -----------------------------------------------------------------------

test('出来高が20週平均の1.5倍以上、かつ株価が前週比下落した場合、volume_spike_declineシグナルが発生する', function () {
    // Arrange: 20週分の価格推移（前週比-5安の週を最終週に配置）+ 出来高が最終週だけ1.6倍に急増
    // volume=1900, volume_ma20=1187.5, ratio=1.6（>=1.5, verified）
    $closes = array_merge(array_fill(0, 6, 100.0), array_fill(0, 12, 95.0), [100.0, 95.0]);
    $volumes = array_merge(array_fill(0, 19, 1150), [1900]);
    $priceHistory = sdPriceHistory($closes, $volumes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    $signal = sdFindSignal($result, 'volume_spike_decline');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('出来高');
    expect($signal['reason_summary'])->toContain('1.6');
});

test('出来高が急増していても株価が前週比で下落していない場合、volume_spike_declineシグナルは発生しない', function () {
    // Arrange: 上記FIREケースと同じ出来高急増（1.6倍）だが、最終週の終値を105（前週比上昇）に変更
    $closes = array_merge(array_fill(0, 6, 100.0), array_fill(0, 12, 95.0), [100.0, 105.0]);
    $volumes = array_merge(array_fill(0, 19, 1150), [1900]);
    $priceHistory = sdPriceHistory($closes, $volumes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    expect(sdSignalTypes($result))->not->toContain('volume_spike_decline');
});

test('株価は前週比下落しているが出来高の急増が1.5倍未満の場合、volume_spike_declineシグナルは発生しない（境界値）', function () {
    // Arrange: 株価は前週比下落（100->95）だが、最終週の出来高は1500（ratio≈1.28、1.5倍未満, verified）
    $closes = array_merge(array_fill(0, 6, 100.0), array_fill(0, 12, 95.0), [100.0, 95.0]);
    $volumes = array_merge(array_fill(0, 19, 1150), [1500]);
    $priceHistory = sdPriceHistory($closes, $volumes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    expect(sdSignalTypes($result))->not->toContain('volume_spike_decline');
});

// -----------------------------------------------------------------------
// 複数シグナルの同時発生
// -----------------------------------------------------------------------

test('RSI高水準からの反落とボリンジャーバンド過熱が同時に成立する場合、両方のシグナルが発生する', function () {
    // Arrange: 25週分のデータ。前半に大きな上昇(+60)を作りRSIウィンドウから抜け落ちさせ、
    // 中盤に小さな下落(-10)と上昇(+5)を挟み、最終週にそれより小さい上昇(+49)を加えることで
    // previous rsi≈86.67(>=70) -> current rsi=84.375(<previous) の反落を作りつつ、
    // 最終週の終値204がボリンジャーバンド上限(≈201.13)を上回るようにする（両方とも verified）。
    // マーケット/セクター騰落率・PEGレシオはいずれも渡さない（null）ことで、
    // これらに依存しないシグナルがnullでも正しく判定されることも合わせて確認する。
    $closes = array_fill(0, 10, 100.0); // idx0-9
    $closes[] = 160.0; // idx10 (100+60)
    $closes = array_merge($closes, array_fill(0, 4, 160.0)); // idx11-14
    $closes[] = 150.0; // idx15 (160-10)
    $closes = array_merge($closes, array_fill(0, 2, 150.0)); // idx16-17
    $closes[] = 155.0; // idx18 (150+5)
    $closes = array_merge($closes, array_fill(0, 5, 155.0)); // idx19-23
    $closes[] = 204.0; // idx24 (155+49)

    expect($closes)->toHaveCount(25);

    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory, marketReturn13w: null, sectorReturn13w: null, pegRatio: null);

    // Assert: このフィクスチャは他のいかなるシグナルも発生しないことを確認済みのため、
    // 2種類がちょうど揃って発生することまで厳密に確認する
    expect(sdSignalTypes($result))->toEqualCanonicalizing(['rsi_reversal', 'bollinger_overheat']);

    $rsiSignal = sdFindSignal($result, 'rsi_reversal');
    expect($rsiSignal['reason_summary'])->toContain('87')->toContain('84');

    $bbSignal = sdFindSignal($result, 'bollinger_overheat');
    expect($bbSignal['reason_summary'])->toContain('ボリンジャーバンド');
});

// -----------------------------------------------------------------------
// シグナルなし・データ不足
// -----------------------------------------------------------------------

test('穏やかな価格推移の場合、いずれのシグナルも発生せず空配列が返る', function () {
    // Arrange: 80週連続+1の緩やかな上昇のみ（急騰・急落・出来高急増なし）。
    // ベンチマーク・PEGレシオも渡さない
    $closes = range(100, 179);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory);

    // Assert
    expect($result)->toBe([]);
});

test('データ不足（15週未満）の場合、例外を投げずいずれのシグナルも発生しない', function () {
    // Arrange: 10週分のみ（RSI(15週必要)・MACD(26週必要)・BB(20週必要)・52週高値(52週必要)・
    // 出来高20週平均(20週必要)・相対力(14週必要)のいずれも算出不可のはず）。
    // 相対力が算出さえされればマイナスとなるような極端なベンチマーク騰落率をあえて渡し、
    // 「データ不足によりnullとなるためガードで弾かれる」ことを明示的に確認する
    $closes = range(100, 109);
    $priceHistory = sdPriceHistory($closes);

    // Act
    $result = sdService()->determine($priceHistory, marketReturn13w: -50.0, sectorReturn13w: -50.0);

    // Assert
    expect($result)->toBe([]);
});
