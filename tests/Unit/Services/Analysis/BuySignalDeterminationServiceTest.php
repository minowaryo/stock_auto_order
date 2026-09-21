<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\BuySignalDeterminationService;
use App\Services\Analysis\FundamentalHealthEvaluator;
use App\Services\Analysis\TechnicalIndicatorCalculator;

/*
|--------------------------------------------------------------------------
| BuySignalDeterminationService — Red phase Unit Test (UC-010)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-010 既存保有株の買い増しタイミングレコメンド,
|     業務ルール「買い増しシグナル種別」「全シグナル共通の前提条件（2026-08-23追加）」)
|   - docs/architecture/data-model.md (`buy_signals` table, 「保留・確定が必要な
|     初期パラメータ値」の買い増しシグナル7種の閾値・共通前提条件、「分析ロジックの
|     計算仕様」節の MA20乖離率算出式)
|   - docs/adr/ADR-0007-existing-holding-add-on-buy-recommendation.md
|     (Decision D3, Addendum 2026-08-23)
|   - tests/Unit/Services/Analysis/SignalDeterminationServiceTest.php (sell-side
|     precedent this file mirrors: naming convention, "current vs previous"
|     TechnicalIndicatorCalculator double-call pattern, fixture verification
|     methodology, assertion style)
|
| App\Services\Analysis\BuySignalDeterminationService does not exist yet (no
| file under app/Services/Analysis/ with that name). Every test below is
| expected to fail with a fatal "Class \"App\Services\Analysis\
| BuySignalDeterminationService\" not found" error at the `new
| BuySignalDeterminationService(...)` line inside the Act step (via the
| bsdService() helper) — this is the intentional, expected Red state (same
| convention as SignalDeterminationServiceTest before that class existed).
|
| This class is pure calculation logic on top of the already-implemented,
| already-Green TechnicalIndicatorCalculator (no DB/HTTP dependency), so
| this is a Unit Test with no RefreshDatabase.
|
| Assumed signature (mirrors SignalDeterminationService::determine() exactly,
| per the task instructions):
|   determine(array $priceHistory, ?float $marketReturn13w = null,
|     ?float $sectorReturn13w = null, ?float $pegRatio = null): array
|   returning array<int, array{signal_type: string, reason_summary: string}>
|
| -------------------------------------------------------------------------
| All-signals-common precondition (the central new design point vs. the
| sell side, added 2026-08-23 during Gate2/3 review — see ADR-0007 Addendum):
| -------------------------------------------------------------------------
|   None of the 7 buy_signal_types may fire unless ALL of the following
|   hold, regardless of whether that signal's own individual threshold
|   condition is independently satisfied:
|     (A) 直前の好調さ: within the last 13 weeks (last 13 elements of
|         $priceHistory, or fewer if history is shorter), at least one
|         close is >= week52_high * 0.85, OR the stock is fundamentally
|         healthy (FundamentalHealthEvaluator::evaluate() === 'passed',
|         ADR-0016 D1, 2026-09-21 relaxation).
|     (B) 連れ安の確認: relative_strength_vs_sector is preferred when
|         available; otherwise relative_strength_vs_market is used
|         (ADR-0015 D4). The selected value must be not null and >= -5.0.
|     (C) 週足MA75の中期トレンドが明確に下向き（=== false）と判定されて
|         いないこと（ADR-0015 D5）。null（データ不足）はブロックしない。
|   This means every "fire" fixture below needs >= 52 weeks of price
|   history (to make week52_high/week52_low non-null) AND a benchmark return
|   chosen so the preferred relative strength is >= -5.0, on top of that
|   signal's own individual condition. Dedicated tests (see the
|   「前提条件による抑制」/OR-relaxation/対セクター相対力/MA75 sections below)
|   demonstrate that an individual condition being met is NOT sufficient by
|   itself when any precondition fails.
|
| Fixture verification methodology (important for Gate 4 review):
|   Every fixture below (raw closes arrays, expected rsi/macd/macd_signal/
|   bb_lower/week52_high/week52_low/volume_ma20/relative_strength_vs_market/
|   ma20-deviation values, and the precondition A/B YES/no determination for
|   each) was independently computed by running the real, already-Green
|   TechnicalIndicatorCalculator (app/Services/Analysis/
|   TechnicalIndicatorCalculator.php) inside the project's Sail/Docker PHP
|   container via a standalone verification script
|   (`docker compose exec laravel.test php /tmp/verify_buy_signals.php`,
|   loading TechnicalIndicatorCalculator directly with `require` — no
|   framework boot needed since that class has no DB/HTTP dependency), NOT
|   derived by hand. The script printed, for every candidate fixture: current/
|   previous rsi/macd/macd_signal, bb_upper/bb_lower, week52_high/week52_low,
|   volume/volume_ma20, relative_strength_vs_market, the MA20-deviation
|   percentage, and an explicit precondition-A/B YES-or-no evaluation against
|   the same rule described above. Several fixture attempts were iterated
|   in-script (e.g. macd_golden_cross needed 3 attempts to find a decline
|   depth + rally size that actually flips the EMA-based MACD/signal
|   ordering; the bollinger_oversold "no-fire" fixture needed a mild
|   continuous-decline shape instead of a flat-then-single-dip shape, because
|   a flat 19-of-20-week window mathematically forces ANY single-week dip to
|   breach the -2σ lower band — verified algebraically inside the script)
|   before landing on the values hard-coded below.
|
| Assertion style (same convention as SignalDeterminationServiceTest):
|   - "FIRE" tests use bsdFindSignal() ("does the expected signal_type
|     appear, with plausible reason_summary content") rather than strict
|     array equality, since several fixtures below incidentally also satisfy
|     another signal's individual condition (documented per-fixture) — this
|     is expected and not a fixture design flaw; only the two "PEG-only"
|     fixtures (built from a plain 52-week monotonic gentle rise, which
|     independently verified to satisfy neither RSI/MACD/BB/week52_low/
|     MA20-deviation/volume individually) assert exact array shape.
|   - reason_summary assertions only check for a relevant Japanese keyword
|     (e.g. "反発", "ボリンジャーバンド" — the two signal types UC-010's own
|     example wording "RSIが28から34へ反発しました、終値がボリンジャーバンド
|     下限を下回りました" gives a concrete pattern for) or loose keyword/number
|     containment for the other 5 signal types, whose exact wording UC-010
|     leaves unspecified, per the same convention as
|     SignalDeterminationServiceTest's macd_dead_cross/bollinger_overheat
|     assertions.
|
*/

/**
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function bsdPriceHistory(array $closes, int|array $volumes = 1000, string $startDate = '2024-01-01'): array
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
 * @param  array<int, array{signal_type: string, reason_summary: string}>  $signals
 * @return array{signal_type: string, reason_summary: string}|null
 */
function bsdFindSignal(array $signals, string $signalType): ?array
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
function bsdSignalTypes(array $signals): array
{
    return array_column($signals, 'signal_type');
}

function bsdService(): BuySignalDeterminationService
{
    return new BuySignalDeterminationService(new TechnicalIndicatorCalculator, new FundamentalHealthEvaluator);
}

/**
 * 「RSI反発条件自体は満たすが、直近13週以内に52週高値付近へ到達した実績がない
 * 場合（前提条件A不成立）」テスト（下記「全シグナル共通の前提条件による抑制」
 * セクション）内でインラインに定義されている、価格面で前提条件Aが不成立になる
 * 長期低迷プレリュード（300から-4/週で下落し、末尾3週だけ136/137/138に戻す、
 * 39要素）を、CHG-0018のOR緩和セクションでも再利用するための複製ヘルパー。
 * 既存テストのインライン定義は無改修のまま残し、値のみ完全一致させている。
 *
 * @return array<int, float>
 */
function bsdLongDeclinePrelude(): array
{
    $prelude = [];

    for ($i = 0; $i <= 35; $i++) {
        $prelude[] = 300 - 4 * $i;
    }

    $prelude[] = 136;
    $prelude[] = 137;
    $prelude[] = 138;

    return array_map(fn (int $v) => (float) $v, $prelude);
}

/**
 * Common 39-week build-up (100..138, +1/week) shared by most fixtures below,
 * so the week52_high peak (138) sits just before the last-13-week window and
 * precondition A is satisfiable by the early part of each signal-specific
 * 13-week tail (verified per-fixture below).
 *
 * @return array<int, float>
 */
function bsdPrelude(): array
{
    return array_map(fn (int $v) => (float) $v, range(100, 138)); // 39 points, index 0..38
}

// -----------------------------------------------------------------------
// 1. rsi_oversold_rebound（RSI低水準からの反発）
// -----------------------------------------------------------------------

test('RSIが30以下から反発し、全シグナル共通の前提条件も満たす場合、rsi_oversold_reboundシグナルが発生する', function () {
    // Arrange: 39週の上昇プレリュード(100->138) + 13週のテイル
    // [134,130,126,122,118,114,110,106,102,98,94,90,95]。
    // previous rsi=4.0, current rsi≈11.111 (previous<=30 かつ current>previous, verified)。
    // week52_high=138（プレリュード内index38）、直近13週中にindex39=134等が
    // week52_high*0.85=117.3以上のため前提条件A成立。marketReturn13w=-35.0を渡すと
    // relative_strength_vs_market≈+3.84(>=-5.0)で前提条件Bも成立（verified）。
    $closes = array_merge(bsdPrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -35.0);

    // Assert
    $signal = bsdFindSignal($result, 'rsi_oversold_rebound');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('反発');
    expect($signal['reason_summary'])->toContain('4')->toContain('11');
});

test('RSIが反発せず下落を継続している場合、前提条件を満たしていてもrsi_oversold_reboundシグナルは発生しない', function () {
    // Arrange: 同じプレリュード + テイル最終週も反発せず下落継続
    // [134,130,126,122,118,114,110,106,102,98,94,90,86]。
    // previous rsi=4.0, current rsi≈1.887 (current<previousのため反発条件を満たさない、verified)。
    // 前提条件A・Bは上と同じ理由で成立する（verified: rel_mkt≈-2.68はしきい値-5.0以上）。
    $closes = array_merge(bsdPrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 86]);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -35.0);

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('rsi_oversold_rebound');
});

// -----------------------------------------------------------------------
// 2. macd_golden_cross（MACDゴールデンクロス）
// -----------------------------------------------------------------------

test('MACDがシグナル線を上抜けし、全シグナル共通の前提条件も満たす場合、macd_golden_crossシグナルが発生する', function () {
    // Arrange: 同じプレリュード(100->138) + 13週の下落トレンド(-3/週、138->102) +
    // 最終週に+80の急反発(102->182)。
    // previous (macd-macd_signal)≈-3.856(<=0)、current (macd-macd_signal)≈+1.400(>0)、verified。
    // 最終週182が全期間の最高値となり week52_high=182 で直近13週内（当該週自身）が
    // 閾値(182*0.85=154.7)を満たすため前提条件A成立。marketReturn13w=-30.0で
    // relative_strength_vs_market≈+61.9(>=-5.0)のため前提条件Bも成立（verified）。
    $tail = [135, 132, 129, 126, 123, 120, 117, 114, 111, 108, 105, 102, 182];
    $closes = array_merge(bsdPrelude(), $tail);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -30.0);

    // Assert
    $signal = bsdFindSignal($result, 'macd_golden_cross');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('MACD');
});

test('下落トレンドが継続している場合（ゴールデンクロスしていない）、前提条件を満たしていてもmacd_golden_crossシグナルは発生しない', function () {
    // Arrange: 同じプレリュード + 12週の下落(-6/週、138->66) + 最終週+40の反発(66->106)。
    // previous (macd-macd_signal)≈-6.747、current≈-3.883、いずれも0超えないため
    // ゴールデンクロス不成立（previousは<=0を満たすがcurrentが0超えない、verified）。
    // week52_high=138のまま、直近13週内に132等が閾値117.3以上のため前提条件A成立。
    // marketReturn13w=-30.0でrelative_strength_vs_market≈+6.8(>=-5.0)のため前提条件Bも成立
    // （verified）。
    $tail = [132, 126, 120, 114, 108, 102, 96, 90, 84, 78, 72, 66, 106];
    $closes = array_merge(bsdPrelude(), $tail);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -30.0);

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('macd_golden_cross');
});

// -----------------------------------------------------------------------
// 3. bollinger_oversold（ボリンジャーバンド下限付近での売られすぎ）
// -----------------------------------------------------------------------

test('終値がボリンジャーバンド下限を下回り、全シグナル共通の前提条件も満たす場合、bollinger_oversoldシグナルが発生する', function () {
    // Arrange: 同じプレリュード + 12週横ばい(138) + 最終週だけ68まで急落。
    // bb_lower≈102.41 > 68（lastClose）、verified。直近13週の大半が138（閾値117.3以上）
    // のため前提条件A成立。marketReturn13w=-55.0でrelative_strength_vs_market≈+4.28
    // (>=-5.0)のため前提条件Bも成立（verified）。
    $tail = array_merge(array_fill(0, 12, 138), [68]);
    $closes = array_merge(bsdPrelude(), $tail);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -55.0);

    // Assert
    $signal = bsdFindSignal($result, 'bollinger_oversold');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('ボリンジャーバンド');
});

test('終値がボリンジャーバンド下限を上回っている場合、前提条件を満たしていてもbollinger_oversoldシグナルは発生しない', function () {
    // Arrange: 同じプレリュード + 13週の緩やかな下落(-1/週、138->125)。
    // bb_lower≈124.68 < 125（lastClose）、verified（下回っていない）。
    // 注記: 横ばい19週+最終週1週だけ下落させる形（フィクスチャ設計上の直感的な「軽い下落」）
    // では、母集団標準偏差(n-1除算)が小さすぎるため代数的にどんな小さい下落でも必ず下限を
    // 割ってしまうことを検証スクリプトで確認した。そのため緩やかな連続下落形状を採用した。
    // 直近13週内に137等が閾値117.3以上のため前提条件A成立。marketReturn13w=-10.0で
    // relative_strength_vs_market≈+0.58(>=-5.0)のため前提条件Bも成立（verified）。
    $tail = [137, 136, 135, 134, 133, 132, 131, 130, 129, 128, 127, 126, 125];
    $closes = array_merge(bsdPrelude(), $tail);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -10.0);

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('bollinger_oversold');
});

// -----------------------------------------------------------------------
// 4. week52_low_proximity（52週安値圏）
// -----------------------------------------------------------------------

test('終値が52週安値の+10%以内にあり、全シグナル共通の前提条件も満たす場合、week52_low_proximityシグナルが発生する', function () {
    // Arrange: 同じプレリュード + 13週の下落(134->65、-6/週ペース)。
    // week52_low=65（lastClose自身が最安値）。閾値(65*1.10=71.5) >= 65、verified。
    // 直近13週内に134等が閾値117.3以上のため前提条件A成立。marketReturn13w=-60.0で
    // relative_strength_vs_market≈+7.10(>=-5.0)のため前提条件Bも成立（verified）。
    $tail = [134, 128, 122, 116, 110, 104, 98, 92, 86, 80, 74, 68, 65];
    $closes = array_merge(bsdPrelude(), $tail);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -60.0);

    // Assert
    $signal = bsdFindSignal($result, 'week52_low_proximity');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('52週安値');
});

test('終値が52週安値の+10%を超えて上にある場合、前提条件を満たしていてもweek52_low_proximityシグナルは発生しない（境界値）', function () {
    // Arrange: 同じ下落テイルだが最終週だけ65ではなく80へ反発させる
    // [134,...,68,80]。week52_low=68（最終週80より前の68が最安値のまま）、
    // 閾値(68*1.10=74.8) < 80（lastClose）、verified（範囲外）。
    // 直近13週内に134等が閾値117.3以上のため前提条件A成立。marketReturn13w=-50.0で
    // relative_strength_vs_market≈+7.97(>=-5.0)のため前提条件Bも成立（verified）。
    $tail = [134, 128, 122, 116, 110, 104, 98, 92, 86, 80, 74, 68, 80];
    $closes = array_merge(bsdPrelude(), $tail);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -50.0);

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('week52_low_proximity');
});

// -----------------------------------------------------------------------
// 5. ma_deviation_oversold（移動平均線からの下方乖離）
// -----------------------------------------------------------------------
// data-model.md「分析ロジックの計算仕様」: (終値-ma20)/ma20*100 を
// BuySignalDeterminationService内で都度算出する（technical_indicatorsには
// 永続化しない）。

test('MA20からの下方乖離が-10%以上あり、全シグナル共通の前提条件も満たす場合、ma_deviation_oversoldシグナルが発生する', function () {
    // Arrange: 同じプレリュード + 13週の下落(-3/週、138->99)。
    // ma20=123.3, lastClose=99, 乖離率=(99-123.3)/123.3*100≈-19.71%（<=-10%、verified）。
    // 直近13週内に135等が閾値117.3以上のため前提条件A成立。marketReturn13w=-30.0で
    // relative_strength_vs_market≈+1.74(>=-5.0)のため前提条件Bも成立（verified）。
    $tail = [135, 132, 129, 126, 123, 120, 117, 114, 111, 108, 105, 102, 99];
    $closes = array_merge(bsdPrelude(), $tail);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -30.0);

    // Assert
    $signal = bsdFindSignal($result, 'ma_deviation_oversold');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('MA20');
});

test('MA20からの下方乖離が-10%未満（浅い下落）の場合、前提条件を満たしていてもma_deviation_oversoldシグナルは発生しない（境界値）', function () {
    // Arrange: 同じプレリュード + 13週の緩やかな下落(-1/週、138->125)。
    // ma20=132.4, lastClose=125, 乖離率=(125-132.4)/132.4*100≈-5.59%（-10%未満、verified）。
    // （このフィクスチャはbollinger_oversoldの境界値テストと同一の価格系列を再利用している。
    // どちらの個別条件も満たさないことを検証済み。）
    $tail = [137, 136, 135, 134, 133, 132, 131, 130, 129, 128, 127, 126, 125];
    $closes = array_merge(bsdPrelude(), $tail);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -10.0);

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('ma_deviation_oversold');
});

// -----------------------------------------------------------------------
// 6. volume_spike_rebound（出来高急増を伴う反発）
// -----------------------------------------------------------------------

test('出来高が20週平均の1.5倍以上、かつ株価が前週比上昇した場合、全シグナル共通の前提条件も満たせばvolume_spike_reboundシグナルが発生する', function () {
    // Arrange: rsi_oversold_reboundと同じ価格テイル(134,...,90,95、最終週90->95で上昇)に、
    // 出来高だけ最終週1900・他51週1150に設定。volume_ma20=(19*1150+1900)/20=1187.5、
    // ratio=1900/1187.5=1.6(>=1.5)、かつ終値95>前週終値90（verified）。
    $closes = array_merge(bsdPrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $volumes = array_merge(array_fill(0, 51, 1150), [1900]);
    $priceHistory = bsdPriceHistory($closes, $volumes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -35.0);

    // Assert
    $signal = bsdFindSignal($result, 'volume_spike_rebound');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('出来高');
    expect($signal['reason_summary'])->toContain('1.6');
});

test('株価は前週比上昇しているが出来高の急増が1.5倍未満の場合、volume_spike_reboundシグナルは発生しない（境界値）', function () {
    // Arrange: 同じ価格テイルだが最終週の出来高を1900ではなく1500に変更。
    // volume_ma20=(19*1150+1500)/20=1167.5、ratio=1500/1167.5≈1.285（1.5倍未満、verified）。
    $closes = array_merge(bsdPrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $volumes = array_merge(array_fill(0, 51, 1150), [1500]);
    $priceHistory = bsdPriceHistory($closes, $volumes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -35.0);

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('volume_spike_rebound');
});

// -----------------------------------------------------------------------
// 7. peg_undervalued（PEGレシオによる割安化）
// -----------------------------------------------------------------------
// PEGレシオは価格系列ではなく引数で直接渡すため、他のいかなる個別シグナルの条件も
// 満たさないことを検証済みの「穏やかな52週上昇」フィクスチャ（52週連続で+1/週、
// 100->151）を使う。これは前提条件A（最終週自身が全期間の最高値のため自明に成立）・
// B（marketReturn13w=0.0でrelative_strength_vs_market≈+9.42、verified）は満たしつつ、
// RSI(previous=current=100)・MACD(previous/current共にmacd-signal=0)・BB(lastClose=151
// < bb_upper 153.33 かつ > bb_lower 129.67)・52週安値(lastClose=151 >> week52_low*1.10=110)・
// MA20乖離(+6.71%、マイナスでない)・出来高(一定のためratio=1.0)のいずれも発生しないことを
// 検証済み（このため、この2件のテストのみ厳密な配列一致で単独発生を確認する）。

test('PEGレシオが1.0以下で、全シグナル共通の前提条件も満たす場合、peg_undervaluedシグナルが発生する（境界値）', function () {
    $closes = range(100, 151); // 52週連続 +1/週
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, pegRatio: 1.0);

    // Assert: このフィクスチャは他のいかなるシグナルも発生しないため、peg_undervaluedのみが
    // 単独で含まれることまで厳密に確認する
    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('peg_undervalued');
    expect($result[0]['reason_summary'])->toContain('PEG');
});

test('PEGレシオが1.0を超える場合、peg_undervaluedシグナルは発生しない（境界値）', function () {
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, pegRatio: 1.01);

    // Assert
    expect($result)->toBe([]);
});

// ADR-0012 D4 (バグB): PEGレシオに下限が無く、マイナスPEG（＝減益・赤字成長。
// US株のFinnhub pegTTM がそのまま負値を返す。DB実データに WIT -34.7 等）を
// 「1.0以下だから割安」と誤判定して peg_undervalued を出していた。
// 0 < peg <= 1.0 のみを割安とする。

test('PEGレシオがマイナス（減益・赤字成長）の場合、peg_undervaluedシグナルは発生しない（ADR-0012 バグB）', function () {
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, pegRatio: -34.7);

    expect(bsdSignalTypes($result))->not->toContain('peg_undervalued');
    expect($result)->toBe([]);
});

test('PEGレシオが0ちょうどの場合、peg_undervaluedシグナルは発生しない（下限は0を含まない）', function () {
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, pegRatio: 0.0);

    expect(bsdSignalTypes($result))->not->toContain('peg_undervalued');
});

// -----------------------------------------------------------------------
// 8. per_undervalued（PER単体シグナル、CHG-0018／ADR-0016 D2）
// -----------------------------------------------------------------------
// PBRはAND条件に含めない（ADR-0016 D2）ため、per_undervaluedはPERのみで
// 判定する。peg_undervaluedと同じ「穏やかな52週上昇」フィクスチャ
// （range(100, 151)）を再利用する。このフィクスチャは前提条件A・Bを満たし
// つつ、他のいかなる個別シグナルの条件も満たさないことを検証済み
// （上記peg_undervaluedセクションの説明コメント参照）。

test('PERが15.0以下で、全シグナル共通の前提条件も満たす場合、per_undervaluedシグナルが発生する（境界値）', function () {
    $closes = range(100, 151); // 52週連続 +1/週
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, per: 15.0);

    // Assert: このフィクスチャは他のいかなるシグナルも発生しないため、
    // per_undervaluedのみが単独で含まれることまで厳密に確認する
    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('per_undervalued');
    expect($result[0]['reason_summary'])->toContain('PER');
});

test('PERが15.0を超える場合、per_undervaluedシグナルは発生しない（境界値）', function () {
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, per: 15.01);

    // Assert
    expect($result)->toBe([]);
});

test('PERが渡されない（null）場合、per_undervaluedシグナルは発生しない', function () {
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act（per省略）
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0);

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('per_undervalued');
    expect($result)->toBe([]);
});

test('PERが10.0のとき、reason_summaryにPERの値が含まれる', function () {
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, per: 10.0);

    // Assert
    $signal = bsdFindSignal($result, 'per_undervalued');
    expect($signal)->not->toBeNull();
    expect($signal['reason_summary'])->toContain('PER');
    expect($signal['reason_summary'])->toContain('10');
});

test('PERが負値（赤字企業、US株Finnhub peTTMがそのまま渡るケース）の場合、per_undervaluedシグナルは発生しない（`/review`回帰テスト、ADR-0012 D4のPEG下限ガードと同種のバグ防止）', function () {
    // Arrange: JP側FundamentalIndicatorMapper::calculatePer()はeps<=0でnull化
    // するが、US側UsFundamentalIndicatorMapperはFinnhubのpeTTMをそのまま採用
    // しており赤字企業では負値になりうる（pegTTMの負値と同じ構図）。下限
    // ガードがないと「PERが低い（負値）＝割安」と誤判定してしまう。
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, per: -20.0);

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('per_undervalued');
    expect($result)->toBe([]);
});

test('PERがちょうど0.0の場合、per_undervaluedシグナルは発生しない（下限ガードの境界値）', function () {
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, per: 0.0);

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('per_undervalued');
    expect($result)->toBe([]);
});

// -----------------------------------------------------------------------
// 全シグナル共通の前提条件による抑制
// -----------------------------------------------------------------------
// use-cases.md UC-010業務ルール「全シグナル共通の前提条件（2026-08-23追加）」・
// ADR-0007 Addendum: 個別シグナルの閾値条件を満たしていても、以下の2条件の
// いずれかを満たさない限りシグナルは一切発生しない。

test('RSI反発条件自体は満たすが、直近13週以内に52週高値付近へ到達した実績がない場合（前提条件A不成立）、rsi_oversold_reboundシグナルは発生しない', function () {
    // Arrange: 「1. rsi_oversold_rebound」のFIREテストと全く同じ直近16週分の価格差分
    // （RSIは直近15週分の終値の差分のみで決まり、それより前の価格水準には一切依存しない
    // ため、末尾13週+プレリュード末尾3週を完全に一致させれば同一のRSI値になる、verified）
    // を使いつつ、それより前（index0-35）を「300から-4/週で下落し続ける長期低迷」の
    // 価格推移に差し替える。全期間の最高値は300（index0）のまま変わらず、直近13週
    // （index39-51、最大134）はその85%(255)に遠く及ばないため、前提条件Aが不成立になる
    // （verified）。marketReturn13w=-35.0はFIREテストと同一のため
    // relative_strength_vs_market≈+3.84(>=-5.0)で前提条件Bは成立したままである
    // （verified）。したがって、RSI自体の反発条件（previous rsi=4.0<=30 かつ
    // current rsi≈11.111>previous）は満たされているにもかかわらず、前提条件Aの不成立に
    // よりシグナルは一切発生しない（52週安値+10%以内という個別条件も同時に満たしている
    // week52_low_proximityも含め、7種すべてが抑制されるため空配列になることまで確認する）。
    $longDeclinePrelude = [];
    for ($i = 0; $i <= 35; $i++) {
        $longDeclinePrelude[] = 300 - 4 * $i;
    }
    $longDeclinePrelude[] = 136; // index36
    $longDeclinePrelude[] = 137; // index37
    $longDeclinePrelude[] = 138; // index38
    $closes = array_merge($longDeclinePrelude, [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -35.0);

    // Assert: 前提条件Aが全シグナル共通のゲートであることを示すため、
    // rsi_oversold_reboundだけでなく結果全体が空になることまで確認する
    expect(bsdSignalTypes($result))->not->toContain('rsi_oversold_rebound');
    expect($result)->toBe([]);
});

test('RSI反発条件自体は満たすが、marketReturn13wが渡されない場合（前提条件B不成立、relative_strength_vs_marketがnull）、rsi_oversold_reboundシグナルは発生しない', function () {
    // Arrange: 「1. rsi_oversold_rebound」のFIREテストと全く同じ価格推移だが、
    // marketReturn13wを渡さない（デフォルトnull）。TechnicalIndicatorCalculatorの
    // 仕様（data-model.md「分析ロジックの計算仕様」）によりbenchmarkReturn13wがnullの場合
    // relative_strength_vs_marketは常にnullになるため、前提条件B（nullでなくかつ
    // -5.0以上）は不成立となる。
    $closes = array_merge(bsdPrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $priceHistory = bsdPriceHistory($closes);

    // Act（marketReturn13w省略）
    $result = bsdService()->determine($priceHistory);

    // Assert: 前提条件Bが全シグナル共通のゲートであることを示すため、
    // rsi_oversold_reboundだけでなく結果全体が空になることまで確認する
    expect(bsdSignalTypes($result))->not->toContain('rsi_oversold_rebound');
    expect($result)->toBe([]);
});

// -----------------------------------------------------------------------
// 前提条件Aの財務健全性によるOR緩和（CHG-0018／ADR-0016 D1）
// -----------------------------------------------------------------------
// ADR-0016 D1: 前提条件Aを「価格面（直近13週以内に52週高値-15%以内へ到達）」
// または「FundamentalHealthEvaluator::evaluate()がpassed」のOR条件に緩和する。
// 前提条件B（相対力>=-5pt）は変更しない。
//
// 上記「RSI反発条件自体は満たすが…（前提条件A不成立）」テストと同一の
// bsdLongDeclinePrelude()（価格面では前提条件Aが不成立、verified: week52_high=300、
// 直近13週最大134 < 300*0.85=255）+同一のRSI反発テイルを再利用し、財務健全性の
// 引数だけを変えて検証する。

test('前提条件Aが価格面では不成立でも、財務健全性がpassedの場合はOR条件により成立し、rsi_oversold_reboundシグナルが発生する', function () {
    // Arrange: bsdLongDeclinePrelude()（価格面で前提条件A不成立）+「1.
    // rsi_oversold_rebound」のFIREテストと同一のRSI反発テイル。
    // marketReturn13w=-35.0は前提条件Aのテストと同一（前提条件Bは独立して成立、
    // relative_strength_vs_market≈+3.84、verified）。
    // equityRatio=50.0・roe=15.0・revenueGrowth=5.0・operatingIncomeGrowth=5.0・
    // operatingMargin=15.0はFundamentalHealthEvaluator::evaluate()の基準
    // （自己資本比率40%以上・ROE10%以上・成長率>0%・営業利益率10%以上）を
    // すべて満たすため'passed'を返す。
    $closes = array_merge(bsdLongDeclinePrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: -35.0,
        equityRatio: 50.0,
        roe: 15.0,
        revenueGrowth: 5.0,
        operatingIncomeGrowth: 5.0,
        operatingMargin: 15.0,
    );

    // Assert: 前提条件Aの価格面が不成立でも、財務健全性passedによるOR成立で
    // rsi_oversold_reboundシグナルが発生する
    $signal = bsdFindSignal($result, 'rsi_oversold_rebound');
    expect($signal)->not->toBeNull();
});

test('前提条件Aが価格面で不成立、かつ財務健全性もpassedにならない場合、依然としてrsi_oversold_reboundシグナルは発生しない', function () {
    // Arrange: 上記と全く同じ価格系列・marketReturn13wだが、成長率をマイナス
    // （revenueGrowth: -5.0, operatingIncomeGrowth: -5.0）にし、
    // FundamentalHealthEvaluator::evaluate()が'failed'を返すようにする。
    // roe/equityRatioは通常の4条件判定（ROE≥10%・自己資本比率≥40%）だけでなく
    // CHG-0017／ADR-0015 D1の救済経路（ROE≥15%かつ自己資本比率≥50%、
    // 成長率不問）も満たさない値にする（8.0/30.0）。救済経路を満たす値
    // （15.0/50.0）のままだと成長率がマイナスでもD1救済でpassed扱いになり、
    // 本テストの意図（財務健全性が真に不成立）が壊れる。
    $closes = array_merge(bsdLongDeclinePrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: -35.0,
        equityRatio: 30.0,
        roe: 8.0,
        revenueGrowth: -5.0,
        operatingIncomeGrowth: -5.0,
        operatingMargin: 15.0,
    );

    // Assert: 前提条件Aは価格面・財務健全性面のどちらでも成立しないため、
    // OR緩和後も依然としてrsi_oversold_reboundシグナルは発生しない
    expect(bsdSignalTypes($result))->not->toContain('rsi_oversold_rebound');
    expect($result)->toBe([]);
});

test('財務健全性がpassedでも、前提条件B（相対力>=-5pt）が不成立の場合はシグナルが発生しない', function () {
    // Arrange: 上記のOR成立テストと全く同じ価格系列・財務健全性引数
    // （passed）だが、marketReturn13wを0.0に変更する。
    // stockReturn13w=(95-138)/138*100≈-31.16%（bsdLongDeclinePrelude()の
    // index38=138を起点とするため、価格面が変わっても直近13週の値は同一）
    // に対し、relative_strength_vs_market=-31.16-0.0≈-31.16(<-5.0)となり
    // 前提条件Bが不成立になる（verified）。
    $closes = array_merge(bsdLongDeclinePrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: 0.0,
        equityRatio: 50.0,
        roe: 15.0,
        revenueGrowth: 5.0,
        operatingIncomeGrowth: 5.0,
        operatingMargin: 15.0,
    );

    // Assert: 前提条件Aが財務健全性passedによりOR成立しても、前提条件Bは
    // 独立して必須であるため、不成立ならシグナルは発生しない
    expect(bsdSignalTypes($result))->not->toContain('rsi_oversold_rebound');
    expect($result)->toBe([]);
});

// -----------------------------------------------------------------------
// 前提条件B: 対セクター相対力優先・対市場フォールバック
// （CHG-0017 / ADR-0015 D4）
// -----------------------------------------------------------------------
// range(100, 151) の13週騰落率は (151 - 138) / 138 * 100。PEGシグナル以外の
// 個別条件を満たさない既存fixtureのため、結果の有無が共通前提条件Bのみによることを
// 切り分けられる。ベンチマーク騰落率は「銘柄騰落率 - 期待する相対力」で逆算する。

test('対セクター相対力が算出済みの場合、対市場が基準未満でも対セクターが基準以上なら買い増しシグナルが発生する', function () {
    $priceHistory = bsdPriceHistory(range(100, 151));
    $stockReturn13w = ((151 - 138) / 138) * 100;

    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: $stockReturn13w + 10.0, // 対市場 -10.0（基準未満）
        sectorReturn13w: $stockReturn13w, // 対セクター 0.0（基準以上）
        pegRatio: 0.8,
    );

    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('peg_undervalued');
});

test('対セクター相対力が算出済みの場合、対市場が基準以上でも対セクターが-5.0未満なら買い増しシグナルは発生しない', function () {
    $priceHistory = bsdPriceHistory(range(100, 151));
    $stockReturn13w = ((151 - 138) / 138) * 100;

    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: $stockReturn13w, // 対市場 0.0（基準以上）
        sectorReturn13w: $stockReturn13w + 5.01, // 対セクター -5.01（基準未満）
        pegRatio: 0.8,
    );

    expect($result)->toBe([]);
});

test('対セクター相対力がnullの場合、対市場相対力にフォールバックして買い増しシグナルが発生する', function () {
    $priceHistory = bsdPriceHistory(range(100, 151));
    $stockReturn13w = ((151 - 138) / 138) * 100;

    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: $stockReturn13w, // 対市場 0.0（基準以上）
        sectorReturn13w: null,
        pegRatio: 0.8,
    );

    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('peg_undervalued');
});

test('対セクターと対市場の相対力がともにnullの場合、買い増しシグナルは発生しない', function () {
    $priceHistory = bsdPriceHistory(range(100, 151));

    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: null,
        sectorReturn13w: null,
        pegRatio: 0.8,
    );

    expect($result)->toBe([]);
});

test('優先される対セクター相対力がちょうど-5.0の場合、境界値を含み買い増しシグナルが発生する', function () {
    $priceHistory = bsdPriceHistory(range(100, 151));
    $stockReturn13w = ((151 - 138) / 138) * 100;

    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: $stockReturn13w + 10.0, // 対市場 -10.0（基準未満）
        sectorReturn13w: $stockReturn13w + 5.0, // 対セクター -5.0（包含境界）
        pegRatio: 0.8,
    );

    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('peg_undervalued');
});

// -----------------------------------------------------------------------
// 前提条件C: 週足MA75の中期トレンド確認（CHG-0017 / ADR-0015 D5）
// -----------------------------------------------------------------------
// TechnicalIndicatorCalculator::calculate()のma75_trend_risingは直近のMA75が
// 13週前時点のMA75より厳密に上向きかを表す（88週分の週足データが必要、
// TechnicalIndicatorCalculatorTest参照）。前提条件Cは「ma75_trend_rising===false
// （データが十分にあり、かつ明確に下向きと判定された）の場合のみブロックする」
// 設計とする。ma75_trend_risingがnull（88週未満でデータ不足）の場合はブロック
// しない（本人確認済み、2026-09-21: 既存の事前条件A/Bのようにnullで即ブロック
// すると、88週未満の履歴しかない銘柄〔直近上場・データ未整備等〕で押し目買い
// シグナルが一律に出なくなる回帰リスクがあるため）。
//
// フィクスチャ設計: 「1. rsi_oversold_rebound」のFIREテスト
// （bsdPrelude()39週+テイル13週=52週、marketReturn13w=-35.0）の直近52週は
// 一切変更せず、その手前に定数値（200.0 or 50.0）の36週を追加して合計88週
// にする。週52高値・RSI・相対力（いずれも直近52週以内のデータのみ参照）は
// 追加した36週の影響を受けないため、既存FIREテストの前提条件A/B・個別RSI
// 条件の成立状態を完全に保ったまま、前提条件Cのみを切り替えられる
// （tinkerで実行し実測値を確認済み: 定数200.0を追加するとma75_trend_rising=
// false、定数50.0を追加するとtrue。week52_high=138・
// relative_strength_vs_market≈3.84・rsi≈11.111はどちらの場合も不変）。

test('MA75中期トレンドが下向き（ma75_trend_rising=false）の場合、他の前提条件・個別シグナル条件を満たしていてもシグナルは発生しない', function () {
    // Arrange: 88週前から36週分、定数200.0（既存FIREテストの直近52週の
    // 終値水準100〜138より十分高い）を追加。直近52週の終値・
    // marketReturn13wはFIREテストと完全に同一。
    $closes = array_merge(
        array_fill(0, 36, 200.0),
        bsdPrelude(),
        [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95],
    );
    $priceHistory = bsdPriceHistory($closes);

    $result = bsdService()->determine($priceHistory, marketReturn13w: -35.0);

    // Assert: 前提条件Cが全シグナル共通のゲートであることを示すため、
    // rsi_oversold_reboundだけでなく結果全体が空になることまで確認する
    expect(bsdSignalTypes($result))->not->toContain('rsi_oversold_rebound');
    expect($result)->toBe([]);
});

test('MA75中期トレンドが上向き（ma75_trend_rising=true）の場合、シグナルは通常通り発生する', function () {
    // Arrange: 上と同じ構成だが、追加する36週を定数50.0（直近52週の水準より
    // 十分低い）にすることでma75_trend_rising=trueにする。
    $closes = array_merge(
        array_fill(0, 36, 50.0),
        bsdPrelude(),
        [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95],
    );
    $priceHistory = bsdPriceHistory($closes);

    $result = bsdService()->determine($priceHistory, marketReturn13w: -35.0);

    $signal = bsdFindSignal($result, 'rsi_oversold_rebound');
    expect($signal)->not->toBeNull();
});

test('88週未満でma75_trend_risingがnull（データ不足）の場合、前提条件Cではブロックされずシグナルが発生する（既存フィクスチャとの後方互換性）', function () {
    // Arrange: 「1. rsi_oversold_rebound」のFIREテストと全く同じ52週のみ
    // （88週に満たないためma75_trend_rising=null）。
    $closes = array_merge(bsdPrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $priceHistory = bsdPriceHistory($closes);

    $result = bsdService()->determine($priceHistory, marketReturn13w: -35.0);

    $signal = bsdFindSignal($result, 'rsi_oversold_rebound');
    expect($signal)->not->toBeNull();
});

// -----------------------------------------------------------------------
// 8. peg_undervalued の低成長銘柄向け分岐（CHG-0017 / ADR-0015 D3, Cycle 3b — 買い側）
// -----------------------------------------------------------------------
// Source of truth追加: docs/adr/ADR-0015-value-cyclical-stock-judgment-branching.md
//   (Decision D3)、docs/architecture/data-model.md「低成長銘柄のPEG除外条件と
//   PER/配当利回り絶対閾値」行、app/Services/Analysis/SignalDeterminationService.php
//   の Cycle 3a 実装（isLowGrowth()、LOW_GROWTH_THRESHOLD=5.0）— 売り側と全く同じ
//   成長率判定ロジックを買い側にも実装する想定であるため、そのミラー。
//
// Implemented (Cycle 3b). determine()'s actual merged signature (2026-09-21,
// after combining with CHG-0018's independent per_undervalued/OR-relaxation
// work) additionally carries $per/$equityRatio/$roe/$operatingMargin ahead
// of $dividendYield — see the class docblock and constructor for the
// current parameter list. LOW_GROWTH_THRESHOLD = 5.0 lives on
// LowGrowthDeterminer (mirrors SignalDeterminationService's use of the same
// class), PER_UNDERVALUED_THRESHOLD = 15.0 and
// DIVIDEND_YIELD_UNDERVALUED_THRESHOLD = 3.0 are on
// BuySignalDeterminationService itself.
//
// Shared baseline fixture: the same 52-week gentle monotonic rise
// (100..151, marketReturn13w=0.0) used by the existing peg_undervalued tests
// above (「7. peg_undervalued」section) — already verified there to satisfy
// both common preconditions (A/B) while triggering none of the other 6
// individual signal conditions, and to yield the same pegRatio-driven
// peg_undervalued behavior when growth is not low. This isolates the new
// low-growth/PER/dividend-yield branch from interference by other signals.

test('成長率が高い（8.0%）場合、PEGレシオが0.8（0<peg<=1.0）なら従来通りpeg_undervaluedシグナルが発生する（回帰確認）', function () {
    // Arrange: revenueGrowth=8.0（>5.0、高成長） -> 低成長と判定されず、従来通り
    // PEGレシオベースの判定が使われる
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: 0.0,
        pegRatio: 0.8,
        revenueGrowth: 8.0,
        operatingIncomeGrowth: null,
    );

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('peg_undervalued');
});

test('成長率が5.0%以下（低成長）の場合、PEGレシオが0.8（PEG基準では割安）でもPER/配当利回りの基準を満たさなければpeg_undervaluedシグナルは発生しない', function () {
    // Arrange: revenueGrowth=3.0（<=5.0、低成長） -> PEGベースの判定は評価されず
    // PER/配当利回りの絶対閾値判定に置き換わる。per=20.0(>15.0)・dividendYield=1.0(<3.0%)
    // のためAND条件を満たさず、pegRatio=0.8が本来PEG基準では割安水準であっても発生しない
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: 0.0,
        pegRatio: 0.8,
        revenueGrowth: 3.0,
        operatingIncomeGrowth: null,
        per: 20.0,
        dividendYield: 1.0,
    );

    // Assert: このフィクスチャは他のいかなる個別シグナル条件も満たさないため、
    // 結果全体が空になることまで確認する
    expect(bsdSignalTypes($result))->not->toContain('peg_undervalued');
    expect($result)->toBe([]);
});

test('成長率が低く、PER≦15.0かつ配当利回り≧3.0%を満たす場合、PEGレシオがnullでもpeg_undervaluedシグナルが発生する', function () {
    // Arrange: revenueGrowth=3.0（低成長）、pegRatio=null（PEGは一切参照されない想定）、
    // per=10.0(<=15.0)・dividendYield=5.0(>=3.0%)でPER/配当利回りの絶対閾値を満たす。
    // per=10.0はCHG-0018／ADR-0016の独立シグナルper_undervalued（PER≤15.0の
    // 単体条件、低成長判定・配当利回りとは無関係）の閾値も満たすため、
    // 本テストのper_undervaluedとは独立にpeg_undervaluedと合わせて2件発生する
    // （マージ時に判明した既知の重複、`.claude/rules/06-branch-coordination.md`参照）。
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: 0.0,
        pegRatio: null,
        revenueGrowth: 3.0,
        operatingIncomeGrowth: null,
        per: 10.0,
        dividendYield: 5.0,
    );

    // Assert
    expect($result)->toHaveCount(2);
    expect(bsdSignalTypes($result))->toContain('peg_undervalued');
    expect(bsdSignalTypes($result))->toContain('per_undervalued');
});

test('成長率が低く、PERがマイナス（赤字企業のFinnhub peTTM等）で配当利回りが基準を満たす場合でも、peg_undervaluedシグナルは発生しない（PERの下限ガード、/review指摘）', function () {
    // Arrange: revenueGrowth=3.0（低成長）、per=-8.0（赤字企業。Finnhubの
    // peTTMは負値をそのまま返すため発生しうる、ADR-0012 D4がpegRatioに
    // 課しているのと同種のガードがPERにも必要）、dividendYield=4.0（基準3.0%以上）。
    // per<=15.0 という素朴な比較だけだと -8.0 <= 15.0 で満たしてしまうため、
    // per > 0.0 の下限ガードで防ぐ。
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: 0.0,
        pegRatio: null,
        revenueGrowth: 3.0,
        operatingIncomeGrowth: null,
        per: -8.0,
        dividendYield: 4.0,
    );

    // Assert
    expect($result)->toHaveCount(0);
});

test('成長率が低く、PERは基準を満たすが配当利回りが基準未満の場合、peg_undervaluedシグナルは発生しない（AND条件の確認）', function () {
    // Arrange: revenueGrowth=3.0（低成長）、per=15.0（満たす、境界値）、
    // dividendYield=2.9（3.0%未満、満たさない）。
    // per=15.0はCHG-0018／ADR-0016の独立シグナルper_undervalued（配当利回りを
    // 見ないPER単体条件）の閾値も満たすため、peg_undervaluedのAND条件不成立
    // （本テストの本来の確認事項）とは独立にper_undervaluedのみ発生する。
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: 0.0,
        pegRatio: null,
        revenueGrowth: 3.0,
        operatingIncomeGrowth: null,
        per: 15.0,
        dividendYield: 2.9,
    );

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('peg_undervalued');
    expect(bsdSignalTypes($result))->toContain('per_undervalued');
    expect($result)->toHaveCount(1);
});

test('成長率が低く、配当利回りは基準を満たすがPERが僅かに基準を超える場合、peg_undervaluedシグナルは発生しない（境界値・AND条件の確認）', function () {
    // Arrange: revenueGrowth=3.0（低成長）、per=15.01（15.0を僅かに超え、満たさない）、
    // dividendYield=3.0（満たす、境界値）
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: 0.0,
        pegRatio: null,
        revenueGrowth: 3.0,
        operatingIncomeGrowth: null,
        per: 15.01,
        dividendYield: 3.0,
    );

    // Assert
    expect(bsdSignalTypes($result))->not->toContain('peg_undervalued');
    expect($result)->toBe([]);
});

test('成長率が低く、PERがちょうど15.0・配当利回りがちょうど3.0%の場合、peg_undervaluedシグナルが発生する（境界値）', function () {
    // Arrange: PER/配当利回りの両方が閾値ちょうど（境界を含む側）。
    // per=15.0はper_undervalued単体シグナル（CHG-0018／ADR-0016）の閾値も
    // 境界含みで満たすため、peg_undervaluedと合わせて2件発生する。
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: 0.0,
        pegRatio: null,
        revenueGrowth: 3.0,
        operatingIncomeGrowth: null,
        per: 15.0,
        dividendYield: 3.0,
    );

    // Assert
    expect($result)->toHaveCount(2);
    expect(bsdSignalTypes($result))->toContain('peg_undervalued');
    expect(bsdSignalTypes($result))->toContain('per_undervalued');
});

test('revenueGrowthとoperatingIncomeGrowthが両方nullの場合、成長率は低いと判定されず従来通りPEGレシオベースの判定が使われる（回帰確認）', function () {
    // Arrange: 両方明示的にnull -> isLowGrowth()は「低いと判定しない」ため除外されず、
    // PEGレシオ0.8（0<peg<=1.0）なら従来通りpeg_undervaluedシグナルが発生する
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: 0.0,
        pegRatio: 0.8,
        revenueGrowth: null,
        operatingIncomeGrowth: null,
    );

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('peg_undervalued');
});

test('新規引数（revenueGrowth/operatingIncomeGrowth/per/dividendYield）をすべて省略した既存呼び出しは影響を受けない（後方互換）', function () {
    // Arrange: 新規4引数を一切渡さない既存互換の呼び出し
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0, pegRatio: 0.5);

    // Assert
    expect($result)->toHaveCount(1);
    expect($result[0]['signal_type'])->toBe('peg_undervalued');
});

test('低成長でPEG除外・PER/配当利回りでも救済されない場合でも、他のシグナル（RSIオーバーソールド反発）は引き続き検出される', function () {
    // Arrange: 「1. rsi_oversold_rebound」のFIREテストと同じフィクスチャに、
    // 低成長（revenueGrowth=3.0）・PEGレシオ0.8（本来PEG基準では割安）・
    // PER=20.0/配当利回り1.0%（基準未達）を同時に渡す。rsi_oversold_reboundは
    // PEG除外分岐の影響を受けず引き続き検出され、peg_undervaluedのみが除外される想定
    $closes = array_merge(bsdPrelude(), [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95]);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine(
        $priceHistory,
        marketReturn13w: -35.0,
        pegRatio: 0.8,
        revenueGrowth: 3.0,
        operatingIncomeGrowth: 3.0,
        per: 20.0,
        dividendYield: 1.0,
    );

    // Assert
    expect(bsdSignalTypes($result))->toContain('rsi_oversold_rebound');
    expect(bsdSignalTypes($result))->not->toContain('peg_undervalued');
});

// -----------------------------------------------------------------------
// シグナルなし・データ不足
// -----------------------------------------------------------------------

test('前提条件を満たしていてもいずれの個別シグナル条件も満たさない場合、空配列が返る', function () {
    // Arrange: peg_undervaluedのFIREテストと同じ「穏やかな52週上昇」フィクスチャだが、
    // pegRatioを渡さない。前提条件A・Bはいずれも成立するが（同上、verified）、
    // 個別のシグナル条件をどれも満たさない（同上、verified）ため空配列になる。
    $closes = range(100, 151);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: 0.0);

    // Assert
    expect($result)->toBe([]);
});

test('データ不足（15週未満）の場合、例外を投げずいずれのシグナルも発生しない', function () {
    // Arrange: 10週分のみ（RSI(15週必要)・MACD(26週必要)・BB(20週必要)・52週高安値(52週必要)・
    // 出来高20週平均(20週必要)・相対力(14週必要)のいずれも算出不可のはず）。
    // 相対力が算出さえされればマイナスとなるような極端なベンチマーク騰落率をあえて渡し、
    // 「データ不足によりnullとなるためガードで弾かれる」ことを明示的に確認する
    // （SignalDeterminationServiceTestの同名テストと同じ設計意図）。
    $closes = range(100, 109);
    $priceHistory = bsdPriceHistory($closes);

    // Act
    $result = bsdService()->determine($priceHistory, marketReturn13w: -50.0, pegRatio: 0.5);

    // Assert
    expect($result)->toBe([]);
});
