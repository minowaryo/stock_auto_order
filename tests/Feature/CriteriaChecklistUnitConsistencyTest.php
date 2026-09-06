<?php

namespace Tests\Feature;

use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Signal\ShowLossReviewListAction;
use App\Actions\Signal\ShowSignalListAction;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Services\Analysis\SignalCriteriaEvaluator;

/*
|--------------------------------------------------------------------------
| 判定チェックリストの通貨単位整合（UC-004 / UC-010 / UC-011、CHG-0010 で
| F-011 検証中に発見した既存バグの横断修正）— Red phase
|--------------------------------------------------------------------------
|
| 発見の経緯:
|   F-011（UC-011 整理検討）の実データ実ブラウザ確認で、米国株の判定
|   チェックリストの「52週高値からの下落率」「52週安値からの距離」
|   「MA75乖離率」等の価格乖離チップが +7000% 級の異常値を表示していた。
|
| 原因:
|   - holding_snapshots.current_price … 米国株は CSV 取込時に参考為替
|     レートで「円換算済み」（例: 1,050円 = $7.0 × 150）
|   - technical_indicators.week52_high / week52_low / ma20 / ma75 / bb_* …
|     米国株は価格系列（Yahoo Finance）由来で「USD のまま」（例: 10.0）
|   - SignalCriteriaEvaluator は percentDeviation(current_price,
|     week52_high) 等を計算 → 円 vs USD で桁が壊れる
|   - これは CHG-0007 で ShowSignalListAction / ShowBuySignalListAction の
|     buildCriteriaMetrics() が `$holdingSnapshot->current_price`（円）を
|     渡す実装になっていたことに起因し、CHG-0009（Finnhub、米国株データ
|     投入）で顕在化した。UC-004 / UC-010 / UC-011 の3チェックリスト共通の
|     バグ。シグナル判定ロジック（SignalDeterminationService 等）は USD
|     価格系列内で完結しているため影響を受けない
|
| -------------------------------------------------------------------------
| Contract this Red phase proposes (flag at Gate 4 if a different shape is
| preferred):
| -------------------------------------------------------------------------
|   - 新規 public static メソッド
|       SignalCriteriaEvaluator::indicatorComparablePrice(
|           ?float $currentPrice, ?float $fxRateUsed
|       ): ?float
|     を追加。fx_rate_used が非 null かつ正なら `current_price / fx_rate_used`
|     を返し、それ以外（JP株は fx_rate_used = null）は current_price を
|     そのまま返す。current_price が null なら null。
|   - ShowSignalListAction / ShowBuySignalListAction / ShowLossReviewListAction
|     の buildCriteriaMetrics 相当箇所で、criteria に渡す `current_price` を
|     この変換後の値にする。分割指値（split_limit_suggestion /
|     split_buy_down_suggestion）や評価額（market_value, CHG-0011）は
|     円建てのまま変更しない（それらは buildCriteriaMetrics の外）。
|   - criteria の各項目が返す value_label は「%」（percentDeviation の結果）
|     なので、変換により表示単位の意味は変わらず、値が正しくなるだけ。
|
| 未実装のため、以下のテストは
|   - indicatorComparablePrice の直接テスト → "Call to undefined method" fatal
|   - 3 Action 経由のテスト → criteria チップの value_label / status が
|     現状の壊れた値（円 ÷ USD）のままで、期待値（USD 同士の乖離）と
|     不一致になり assertion 失敗
| で Red になる想定。JP株（fx_rate_used = null）の対照テストは現状でも
| Green（回帰防止用）。
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function criteriaUnitTestBatch(?\DateTimeInterface $snapshottedAt = null): array
{
    $snapshottedAt ??= now();

    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => $snapshottedAt,
    ]);

    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => $snapshottedAt,
    ]);

    return [$batch, $snapshot];
}

/**
 * @param  array<string, mixed>  $holdingAttributes
 * @param  array<string, mixed>  $snapshotAttributes
 * @param  array<string, mixed>  $technicalAttributes
 */
function criteriaUnitTestHolding(
    Snapshot $snapshot,
    array $holdingAttributes = [],
    array $snapshotAttributes = [],
    array $technicalAttributes = [],
): array {
    $holding = Holding::create(array_merge([
        'symbol_code' => 'JOBY',
        'market' => 'us',
        'instrument_type' => 'stock',
        'symbol_name' => 'ジョビー・アビエーション',
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ], $holdingAttributes));

    $holdingSnapshot = HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 900.00,
        // 円換算済み現在値 1,050円（= $7.0 × 150）
        'current_price' => 1050.00,
        'fx_rate_used' => 150.00,
        'unrealized_gain_amount' => 15000,
        'unrealized_gain_rate' => 16.6,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $snapshotAttributes));

    // technical_indicators は USD のまま（$7.0 の株）
    TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 50.0,
        'macd' => -0.5,
        'macd_signal' => 0.2,
        'ma20' => 8.0,
        'ma75' => 10.0,          // USD。円換算現在値7.0 との乖離 = -30%
        'bb_upper' => 11.0,      // USD
        'bb_lower' => 6.0,       // USD
        'volume' => 1_000_000,
        'volume_ma20' => 1_000_000.0,
        'week52_high' => 10.0,   // USD。乖離 = (7-10)/10 = -30%
        'week52_low' => 6.8,     // USD。距離 = (7-6.8)/6.8 = +2.9%
        'relative_strength_vs_market' => -12.0,
        'relative_strength_vs_sector' => -12.0,
        'computed_at' => now(),
    ], $technicalAttributes));

    return [$holding, $holdingSnapshot];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function criteriaUnitTestFundamental(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::updateOrCreate(
        ['holding_id' => $holding->id],
        array_merge([
            'per' => 15.0,
            'pbr' => 1.5,
            'roe' => 15.2,
            'revenue_growth' => 8.0,
            'operating_income_growth' => 12.3,
            'equity_ratio' => 58.0,
            'dividend_yield' => 2.0,
            'dividend_payout_ratio' => 30.0,
            'eps_growth' => 10.0,
            'peg_ratio' => 1.2,
            'fetched_at' => now(),
        ], $attributes),
    );
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 */
function criteriaUnitTestFindTechnical(array $rows, string $symbolCode, string $label): array
{
    foreach ($rows as $row) {
        if (($row['symbol_code'] ?? null) !== $symbolCode) {
            continue;
        }
        foreach ($row['criteria']['technical'] as $item) {
            if ($item['label'] === $label) {
                return $item;
            }
        }
    }

    return [];
}

describe('SignalCriteriaEvaluator::indicatorComparablePrice（新規 public static）', function () {
    test('fx_rate_used が指定されていれば current_price を割り戻して USD 建てにする', function () {
        expect(SignalCriteriaEvaluator::indicatorComparablePrice(1050.0, 150.0))
            ->toEqualWithDelta(7.0, 0.0001);
    });

    test('fx_rate_used が null（JP株）なら current_price をそのまま返す', function () {
        expect(SignalCriteriaEvaluator::indicatorComparablePrice(1300.0, null))
            ->toEqualWithDelta(1300.0, 0.0001);
    });

    test('current_price が null なら null', function () {
        expect(SignalCriteriaEvaluator::indicatorComparablePrice(null, 150.0))
            ->toBeNull();
    });

    test('fx_rate_used が 0 なら 0 除算を避けて current_price をそのまま返す', function () {
        expect(SignalCriteriaEvaluator::indicatorComparablePrice(1050.0, 0.0))
            ->toEqualWithDelta(1050.0, 0.0001);
    });
});

describe('UC-011（ShowLossReviewListAction）: 米国株の価格乖離チップが USD 同士で計算される', function () {
    test('52週高値からの下落率が -30%（円÷USD の +10000% 級ではない）で met になる', function () {
        [, $snapshot] = criteriaUnitTestBatch();
        [$holding] = criteriaUnitTestHolding($snapshot, [], [
            'unrealized_gain_amount' => -40000, 'unrealized_gain_rate' => -40.0,
        ]);
        criteriaUnitTestFundamental($holding);

        $rows = app(ShowLossReviewListAction::class)->execute();
        $item = criteriaUnitTestFindTechnical($rows, 'JOBY', '52週高値からの下落率');

        expect($item)->not->toBe([]);
        expect($item['value_label'])->toBe('-30.0%');
        expect($item['status'])->toBe('met');
    });

    test('MA75乖離率が -30%（USD 同士）で met になる', function () {
        [, $snapshot] = criteriaUnitTestBatch();
        [$holding] = criteriaUnitTestHolding($snapshot, [], [
            'unrealized_gain_amount' => -40000, 'unrealized_gain_rate' => -40.0,
        ]);
        criteriaUnitTestFundamental($holding);

        $item = criteriaUnitTestFindTechnical(
            app(ShowLossReviewListAction::class)->execute(), 'JOBY', 'MA75乖離率',
        );

        expect($item['value_label'])->toBe('-30.0%');
        expect($item['status'])->toBe('met');
    });
});

describe('UC-004（ShowSignalListAction）: 米国株の価格乖離チップが USD 同士で計算される', function () {
    test('52週高値からの下落率が -30% で met（利確側の基準 ≤-10%）', function () {
        [, $snapshot] = criteriaUnitTestBatch();
        [, $holdingSnapshot] = criteriaUnitTestHolding($snapshot, [], [
            'unrealized_gain_amount' => 200000, 'unrealized_gain_rate' => 30.0,
        ]);
        Signal::create([
            'holding_snapshot_id' => $holdingSnapshot->id,
            'signal_type' => 'rsi_reversal',
            'reason_summary' => 'RSIが72から65に反落',
        ]);

        $item = criteriaUnitTestFindTechnical(
            app(ShowSignalListAction::class)->execute(), 'JOBY', '52週高値からの下落率',
        );

        expect($item)->not->toBe([]);
        expect($item['value_label'])->toBe('-30.0%');
        expect($item['status'])->toBe('met');
    });
});

describe('UC-010（ShowBuySignalListAction）: 米国株の価格乖離チップが USD 同士で計算される', function () {
    test('52週安値からの距離が +2.9% で met（買い側の基準 ≤+10%）', function () {
        [, $snapshot] = criteriaUnitTestBatch();
        [$holding, $holdingSnapshot] = criteriaUnitTestHolding($snapshot, [], [
            'unrealized_gain_amount' => -8000, 'unrealized_gain_rate' => -8.5,
        ]);
        BuySignal::create([
            'holding_snapshot_id' => $holdingSnapshot->id,
            'signal_type' => 'rsi_oversold_rebound',
            'reason_summary' => 'RSIが28から34へ反発しました',
        ]);
        criteriaUnitTestFundamental($holding);

        $item = criteriaUnitTestFindTechnical(
            app(ShowBuySignalListAction::class)->execute(), 'JOBY', '52週安値からの距離',
        );

        expect($item)->not->toBe([]);
        expect($item['value_label'])->toBe('+2.9%');
        expect($item['status'])->toBe('met');
    });
});

describe('回帰防止: JP株（fx_rate_used = null）は従来どおり', function () {
    test('UC-011 の JP株は current_price をそのまま指標と比較する', function () {
        [, $snapshot] = criteriaUnitTestBatch();
        [$holding] = criteriaUnitTestHolding($snapshot, [
            'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
        ], [
            'current_price' => 700.00, 'fx_rate_used' => null,
            'unrealized_gain_amount' => -40000, 'unrealized_gain_rate' => -40.0,
        ], [
            'week52_high' => 1000.00, 'ma75' => 750.00, 'week52_low' => 680.00,
        ]);
        criteriaUnitTestFundamental($holding);

        $item = criteriaUnitTestFindTechnical(
            app(ShowLossReviewListAction::class)->execute(), '7203', '52週高値からの下落率',
        );

        // (700 - 1000) / 1000 = -30.0%
        expect($item['value_label'])->toBe('-30.0%');
        expect($item['status'])->toBe('met');
    });
});
