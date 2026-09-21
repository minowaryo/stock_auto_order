<?php

namespace Tests\Feature;

use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;

/*
|--------------------------------------------------------------------------
| UC-011: 整理検討（含み損）候補一覧 — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-011（出力表・業務ルール全項目・エラーケース）
|   - docs/architecture/data-model.md
|       「保留・確定が必要な初期パラメータ値」表 整理検討の6行
|       「分析ロジックの計算仕様」節「整理検討候補一覧の算出項目」表
|         recovery_required_rate = (1 / (1 + r/100) - 1) * 100
|         portfolio_loss_share  = abs(unrealized_gain_amount) / 評価総額 * 100
|         MA75乖離率 = (現在値 - ma75) / ma75 * 100
|         continuous_holding_weeks（ContinuousHoldingWeeksCalculator に分離）
|   - docs/adr/ADR-0010-loss-review-candidate-list.md D1〜D11
|   - tests/Feature/UC010BuySignalListTest.php（ucFrom010Test* ヘルパー命名・
|     describe 構造の先例）／tests/Feature/SignalListTest.php（Action を直接
|     呼ぶ方式の先例）
|
| -------------------------------------------------------------------------
| このファイルのスコープ（F-011 には JSON API を作らない）
| -------------------------------------------------------------------------
| F-011 は Livewire 期の新機能のため、UC-004/UC-010 のような GET /api/* の
| JSON エンドポイントは新設しない（PLAN / ADR-0010 D10）。このファイルは
| App\Actions\Signal\ShowLossReviewListAction::execute() を直接呼び、対象抽出・
| 行組み立て・ソートを検証する（SignalListTest.php の「銘柄詳細へのリンク」
| describe が ShowSignalListAction::execute() を直接呼ぶのと同じ方式）。画面
| （Livewire）側の描画は tests/Feature/SignalListTest.php の UC-011 describe
| で検証する。
|
| -------------------------------------------------------------------------
| Contract this Red phase proposes (flag at Gate 4 if a different shape is
| preferred):
| -------------------------------------------------------------------------
|   - App\Actions\Signal\ShowLossReviewListAction は
|       FundamentalHealthEvaluator / PortfolioEvaluationCalculator /
|       SignalCriteriaEvaluator / ContinuousHoldingWeeksCalculator を DI
|       （ShowBuySignalListAction の構造を模倣）。
|   - execute(): array<int, array<string,mixed>> — 直近スナップショットが
|     無ければ [] を返す。
|   - 各行のキー: id (holdings.id), symbol_code, symbol_name,
|     unrealized_gain_rate, unrealized_gain_amount, recovery_required_rate,
|     portfolio_loss_share, continuous_holding_weeks,
|     continuous_holding_weeks_is_truncated, fundamental_status,
|     fundamental_summary, rebound_buy_signal_count, rebound_buy_signal_present,
|     loss_review_reason_summary, criteria
|   - 対象抽出: holding.instrument_type === 'stock' かつ
|     unrealized_gain_rate < -20.0（整理検討ライン。use-cases「下回る」・
|     data-model「-20%未満」＝ strict less than。-20.0 ちょうどは対象外と
|     解釈してテストを書いている。plan の表記は `≤` なので、この境界の
|     最終解釈は Gate 4 で確認する）。
|   - 並び順（透明マルチキー、usort）:
|       ① rebound_buy_signal_present false → true
|       ② fundamental_status failed → unavailable → passed
|       ③ criteria テクニカル met 数の多い順
|       ④ unrealized_gain_rate 昇順（含み損が深い順）
|   - recovery_required_rate = (1 / (1 + r/100) - 1) * 100（r は負の含み益率）
|   - portfolio_loss_share = abs(unrealized_gain_amount)
|       / PortfolioEvaluationCalculator::total(...) * 100、分母0で null
|   - fundamental_status='failed' / 'unavailable' の銘柄も一覧から除外しない
|     （UC-010 とは逆、ADR-0010 D4）。押し目買いシグナル発生銘柄も除外しない
|     （ADR-0010 D7）。NISA 区分による絞り込みもしない（UC-011 業務ルール）。
|
| App\Actions\Signal\ShowLossReviewListAction /
| App\Services\Portfolio\ContinuousHoldingWeeksCalculator /
| App\Services\Analysis\LossReviewThresholds はいずれも未実装。ucFrom011TestExecute()
| を呼ぶすべてのテストは、コンテナ解決時に "Class ... not found" / 未実装
| メソッド呼び出しの fatal error で失敗する想定（アサーション不一致ではない）。
| これが意図した Red 状態である。ucFrom011Test* のフィクスチャが触る
| ImportBatch / Snapshot / Holding / HoldingSnapshot / TechnicalIndicator /
| FundamentalIndicator / BuySignal / HoldingSnapshotAccount はすべて既存
| モデルのため、フィクスチャ構築時点では失敗しない。
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom011TestBatch(?\DateTimeInterface $snapshottedAt = null): array
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
 * @param  array<string, mixed>  $attributes
 */
function ucFrom011TestHolding(array $attributes = []): Holding
{
    return Holding::create(array_merge([
        'symbol_code' => '7203',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'トヨタ自動車',
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ], $attributes));
}

/**
 * Deep-loss default: 100株 取得単価1000 現在値600 → 含み損 -40,000円 / -40.0%.
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom011TestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 1000,
        'current_price' => 600,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => -40000,
        'unrealized_gain_rate' => -40.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * Bearish defaults tuned so all 7 loss-review technical criteria are "met"
 * for a holding priced at current_price=600 (the ucFrom011TestHoldingSnapshot
 * default): 52週高値下落率 (600-1000)/1000=-40%, 52週安値距離
 * (600-580)/580=+3.4%, MA75乖離 (600-750)/750=-20%, MACD-シグナル線 -3<0,
 * 相対力(対市場) -12 ≤ -5.
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom011TestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 40.0,
        'macd' => -5.0,
        'macd_signal' => -2.0,
        'ma20' => 800.0,
        'ma75' => 750.0,
        'bb_upper' => 900.0,
        'bb_lower' => 550.0,
        'volume' => 1_000_000,
        'volume_ma20' => 1_000_000.0,
        'week52_high' => 1000.0,
        'week52_low' => 580.0,
        'relative_strength_vs_market' => -12.0,
        'relative_strength_vs_sector' => -12.0,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * Healthy defaults (fundamental_status='passed'). Override roe/equity_ratio
 * for the 'failed' cases.
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom011TestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
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
            // CHG-0012 / ADR-0011: 営業利益率10%以上（健全）をデフォルトに。
            // 'failed' を意図するテストは override で operating_margin を渡す。
            'operating_margin' => 18.3,
            'fetched_at' => now(),
        ], $attributes),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom011TestBuySignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): BuySignal
{
    return BuySignal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_oversold_rebound',
        'reason_summary' => 'RSIが28から34へ反発しました',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom011TestAccount(HoldingSnapshot $holdingSnapshot, array $attributes = []): HoldingSnapshotAccount
{
    return HoldingSnapshotAccount::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'account_type' => 'specific',
        'quantity' => 100,
        'average_cost' => 1000.00,
    ], $attributes));
}

/**
 * Resolve + run the (not-yet-implemented) Action. Primary Red trigger.
 *
 * @return array<int, array<string, mixed>>
 */
function ucFrom011TestExecute(): array
{
    return app('App\\Actions\\Signal\\ShowLossReviewListAction')->execute();
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, mixed>|null
 */
function ucFrom011TestFindRow(array $rows, string $symbolCode): ?array
{
    foreach ($rows as $row) {
        if (($row['symbol_code'] ?? null) === $symbolCode) {
            return $row;
        }
    }

    return null;
}

describe('UC-011: 整理検討（含み損）候補一覧', function () {
    describe('正常系', function () {
        test('含み損が整理検討ラインを下回る個別株が抽出され、全出力キーが揃い、算出項目が正しい', function () {
            [, $snapshot] = ucFrom011TestBatch();

            // 対象: 100株 取得単価1000 現在値600 → 含み損 -40,000円 / -40.0%
            $target = ucFrom011TestHolding(['symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車']);
            $targetSnapshot = ucFrom011TestHoldingSnapshot($snapshot, $target, [
                'quantity' => 100, 'average_cost' => 1000.00, 'current_price' => 600.00,
                'unrealized_gain_amount' => -40000, 'unrealized_gain_rate' => -40.0,
            ]);
            ucFrom011TestTechnicalIndicator($target);
            ucFrom011TestFundamentalIndicator($target);

            // 含み益銘柄（対象外）を1件足してポートフォリオ評価総額を 200,000 に
            // する（60,000 + 140,000）。→ portfolio_loss_share = 40,000 / 200,000 = 20.0%
            $gainHolding = ucFrom011TestHolding(['symbol_code' => '6758', 'symbol_name' => 'ソニーグループ']);
            ucFrom011TestHoldingSnapshot($snapshot, $gainHolding, [
                'quantity' => 100, 'average_cost' => 1000.00, 'current_price' => 1400.00,
                'unrealized_gain_amount' => 40000, 'unrealized_gain_rate' => 40.0,
            ]);

            $rows = ucFrom011TestExecute();
            $row = ucFrom011TestFindRow($rows, '7203');

            expect($row)->not->toBeNull();
            expect($row)->toHaveKeys([
                'id', 'symbol_code', 'symbol_name', 'unrealized_gain_rate', 'unrealized_gain_amount',
                'recovery_required_rate', 'portfolio_loss_share', 'continuous_holding_weeks',
                'continuous_holding_weeks_is_truncated', 'fundamental_status', 'fundamental_summary',
                'rebound_buy_signal_count', 'rebound_buy_signal_present', 'loss_review_reason_summary', 'criteria',
            ]);

            expect($row['id'])->toBe($target->id);
            expect($row['symbol_name'])->toBe('トヨタ自動車');
            expect((float) $row['unrealized_gain_rate'])->toEqualWithDelta(-40.0, 0.01);
            expect((float) $row['unrealized_gain_amount'])->toEqualWithDelta(-40000.0, 0.01);
            // recovery_required_rate: (1 / (1 - 0.40) - 1) * 100 = 66.666...
            expect((float) $row['recovery_required_rate'])->toEqualWithDelta(66.67, 0.1);
            // portfolio_loss_share: 40,000 / 200,000 * 100 = 20.0
            expect((float) $row['portfolio_loss_share'])->toEqualWithDelta(20.0, 0.2);
            expect($row['rebound_buy_signal_count'])->toBe(0);
            expect($row['rebound_buy_signal_present'])->toBeFalse();
            expect(trim((string) $row['loss_review_reason_summary']))->not->toBe('');

            // 含み益銘柄は対象外
            expect(ucFrom011TestFindRow($rows, '6758'))->toBeNull();
        });

        test('recovery_required_rate は含み損率ごとに (1 / (1 + r/100) - 1) * 100 で算出される', function () {
            [, $snapshot] = ucFrom011TestBatch();

            $holding = ucFrom011TestHolding(['symbol_code' => '9984', 'symbol_name' => 'ソフトバンクグループ']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 100, 'average_cost' => 1000.00, 'current_price' => 620.00,
                'unrealized_gain_amount' => -38000, 'unrealized_gain_rate' => -38.0,
            ]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '9984');

            expect($row)->not->toBeNull();
            // (1 / (1 - 0.38) - 1) * 100 = 61.290...
            expect((float) $row['recovery_required_rate'])->toEqualWithDelta(61.29, 0.1);
        });
    });

    describe('対象抽出（業務ルール）', function () {
        test('含み損 -15%（整理検討ライン -20% 以内）の銘柄は対象外', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '4502', 'symbol_name' => '武田薬品工業']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding, [
                'unrealized_gain_amount' => -15000, 'unrealized_gain_rate' => -15.0,
            ]);

            expect(ucFrom011TestFindRow(ucFrom011TestExecute(), '4502'))->toBeNull();
        });

        test('ちょうど -20.0% の銘柄は対象外（strict less than。境界の最終解釈は Gate 4 で確認）', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '8306', 'symbol_name' => '三菱UFJ']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding, [
                'unrealized_gain_amount' => -20000, 'unrealized_gain_rate' => -20.0,
            ]);

            expect(ucFrom011TestFindRow(ucFrom011TestExecute(), '8306'))->toBeNull();
        });

        test('含み損 -20.5% の銘柄は対象になる', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '8035', 'symbol_name' => '東京エレクトロン']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding, [
                'unrealized_gain_amount' => -20500, 'unrealized_gain_rate' => -20.5,
            ]);

            expect(ucFrom011TestFindRow(ucFrom011TestExecute(), '8035'))->not->toBeNull();
        });

        test('ETF は含み損が深くても対象外', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding([
                'symbol_code' => 'VTI', 'market' => 'us', 'instrument_type' => 'etf',
                'symbol_name' => 'Vanguard Total Stock Market ETF',
            ]);
            ucFrom011TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 100.00, 'unrealized_gain_amount' => -50000, 'unrealized_gain_rate' => -45.0,
            ]);

            expect(ucFrom011TestFindRow(ucFrom011TestExecute(), 'VTI'))->toBeNull();
        });

        test('投資信託は含み損が深くても対象外', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding([
                'symbol_code' => 'eMAXIS Slim 全世界株式', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund',
                'symbol_name' => 'eMAXIS Slim 全世界株式',
            ]);
            ucFrom011TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 14000.00, 'unrealized_gain_amount' => -50000, 'unrealized_gain_rate' => -45.0,
            ]);

            expect(ucFrom011TestFindRow(ucFrom011TestExecute(), 'eMAXIS Slim 全世界株式'))->toBeNull();
        });

        test('保有がすべて NISA 区分の銘柄も一覧から除外しない（UC-004 と異なり NISA フィルタなし）', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '9432', 'symbol_name' => '日本電信電話']);
            $holdingSnapshot = ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestAccount($holdingSnapshot, ['account_type' => 'nisa_growth', 'quantity' => 100]);

            expect(ucFrom011TestFindRow(ucFrom011TestExecute(), '9432'))->not->toBeNull();
        });
    });

    describe('ファンダ / 押し目シグナルの扱い（ADR-0010 D4 / D7）', function () {
        test('fundamental_status=failed（ROE・自己資本比率が基準割れ）の銘柄も一覧に含まれる（UC-010 と逆）', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '6501', 'symbol_name' => '日立製作所']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestFundamentalIndicator($holding, ['roe' => 4.2, 'equity_ratio' => 28.0]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '6501');
            expect($row)->not->toBeNull();
            expect($row['fundamental_status'])->toBe('failed');
        });

        test('fundamental_status=unavailable（ROE・自己資本比率 未取得）の銘柄も一覧に含まれる', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => 'AAPL', 'market' => 'us', 'symbol_name' => 'Apple Inc.']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding, ['current_price' => 100.00, 'fx_rate_used' => 150.0]);
            // Deliberately no FundamentalIndicator row.

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), 'AAPL');
            expect($row)->not->toBeNull();
            expect($row['fundamental_status'])->toBe('unavailable');
        });

        test('押し目買いシグナルが1件以上ある含み損銘柄も除外されず、rebound_buy_signal_present=true になる', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '4063', 'symbol_name' => '信越化学工業']);
            $holdingSnapshot = ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestBuySignal($holdingSnapshot, ['signal_type' => 'rsi_oversold_rebound']);
            ucFrom011TestBuySignal($holdingSnapshot, ['signal_type' => 'macd_golden_cross', 'reason_summary' => 'MACDがゴールデンクロスしました']);
            ucFrom011TestFundamentalIndicator($holding);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '4063');
            expect($row)->not->toBeNull();
            expect($row['rebound_buy_signal_present'])->toBeTrue();
            expect($row['rebound_buy_signal_count'])->toBe(2);
        });
    });

    describe('並び順（透明マルチキー、ADR-0010 D8）', function () {
        test('①押し目なし→あり ②failed→unavailable→passed ③テクニカル met 数の多い順 ④含み損率の深い順', function () {
            [, $snapshot] = ucFrom011TestBatch();

            // A: 押し目なし・failed・テクニカル全met(7)・-50%
            $a = ucFrom011TestHolding(['symbol_code' => '1111', 'symbol_name' => 'A社']);
            ucFrom011TestHoldingSnapshot($snapshot, $a, ['unrealized_gain_amount' => -50000, 'unrealized_gain_rate' => -50.0]);
            ucFrom011TestTechnicalIndicator($a);
            ucFrom011TestFundamentalIndicator($a, ['roe' => 4.0, 'equity_ratio' => 25.0]);

            // B: 押し目なし・failed・テクニカルmet少(TIなし)・-60%
            $b = ucFrom011TestHolding(['symbol_code' => '2222', 'symbol_name' => 'B社']);
            ucFrom011TestHoldingSnapshot($snapshot, $b, ['unrealized_gain_amount' => -60000, 'unrealized_gain_rate' => -60.0]);
            ucFrom011TestFundamentalIndicator($b, ['roe' => 4.0, 'equity_ratio' => 25.0]);

            // C: 押し目なし・passed・-70%
            $c = ucFrom011TestHolding(['symbol_code' => '3333', 'symbol_name' => 'C社']);
            ucFrom011TestHoldingSnapshot($snapshot, $c, ['unrealized_gain_amount' => -70000, 'unrealized_gain_rate' => -70.0]);
            ucFrom011TestTechnicalIndicator($c);
            ucFrom011TestFundamentalIndicator($c);

            // D: 押し目あり・failed・-80%（押し目ありのため最後尾）
            $d = ucFrom011TestHolding(['symbol_code' => '4444', 'symbol_name' => 'D社']);
            $dSnapshot = ucFrom011TestHoldingSnapshot($snapshot, $d, ['unrealized_gain_amount' => -80000, 'unrealized_gain_rate' => -80.0]);
            ucFrom011TestBuySignal($dSnapshot);
            ucFrom011TestTechnicalIndicator($d);
            ucFrom011TestFundamentalIndicator($d, ['roe' => 4.0, 'equity_ratio' => 25.0]);

            $order = array_column(ucFrom011TestExecute(), 'symbol_code');

            expect($order)->toBe(['1111', '2222', '3333', '4444']);
        });

        test('押し目買いシグナルありの銘柄は、押し目なしの銘柄より後ろに並ぶ', function () {
            [, $snapshot] = ucFrom011TestBatch();

            $withSignal = ucFrom011TestHolding(['symbol_code' => '5555', 'symbol_name' => '押し目あり社']);
            $withSignalSnapshot = ucFrom011TestHoldingSnapshot($snapshot, $withSignal, ['unrealized_gain_rate' => -80.0, 'unrealized_gain_amount' => -80000]);
            ucFrom011TestBuySignal($withSignalSnapshot);
            ucFrom011TestFundamentalIndicator($withSignal);

            $noSignal = ucFrom011TestHolding(['symbol_code' => '6666', 'symbol_name' => '押し目なし社']);
            ucFrom011TestHoldingSnapshot($snapshot, $noSignal, ['unrealized_gain_rate' => -25.0, 'unrealized_gain_amount' => -25000]);
            ucFrom011TestFundamentalIndicator($noSignal);

            $order = array_column(ucFrom011TestExecute(), 'symbol_code');

            expect(array_search('6666', $order, true))->toBeLessThan(array_search('5555', $order, true));
        });
    });

    describe('推定連続保有週数（continuous_holding_weeks）', function () {
        test('3スナップショットすべてに連続出現する含み損銘柄は weeks=3・is_truncated=true', function () {
            [, $oldSnapshot] = ucFrom011TestBatch(now()->subWeeks(2));
            [, $midSnapshot] = ucFrom011TestBatch(now()->subWeek());
            [, $latestSnapshot] = ucFrom011TestBatch(now());

            $holding = ucFrom011TestHolding(['symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車']);
            ucFrom011TestHoldingSnapshot($oldSnapshot, $holding, ['unrealized_gain_rate' => -30.0, 'unrealized_gain_amount' => -30000]);
            ucFrom011TestHoldingSnapshot($midSnapshot, $holding, ['unrealized_gain_rate' => -35.0, 'unrealized_gain_amount' => -35000]);
            ucFrom011TestHoldingSnapshot($latestSnapshot, $holding, ['unrealized_gain_rate' => -40.0, 'unrealized_gain_amount' => -40000]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '7203');

            expect($row)->not->toBeNull();
            expect($row['continuous_holding_weeks'])->toBe(3);
            expect($row['continuous_holding_weeks_is_truncated'])->toBeTrue();
        });

        test('直近2スナップショットのみ出現の含み損銘柄は weeks=2・is_truncated=false', function () {
            ucFrom011TestBatch(now()->subWeeks(2)); // 最古スナップショット（この銘柄は不在）
            [, $midSnapshot] = ucFrom011TestBatch(now()->subWeek());
            [, $latestSnapshot] = ucFrom011TestBatch(now());

            $holding = ucFrom011TestHolding(['symbol_code' => '6758', 'symbol_name' => 'ソニーグループ']);
            ucFrom011TestHoldingSnapshot($midSnapshot, $holding, ['unrealized_gain_rate' => -35.0, 'unrealized_gain_amount' => -35000]);
            ucFrom011TestHoldingSnapshot($latestSnapshot, $holding, ['unrealized_gain_rate' => -40.0, 'unrealized_gain_amount' => -40000]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '6758');

            expect($row)->not->toBeNull();
            expect($row['continuous_holding_weeks'])->toBe(2);
            expect($row['continuous_holding_weeks_is_truncated'])->toBeFalse();
        });
    });

    describe('判定チェックリスト（criteria）', function () {
        // CHG-0012 / ADR-0011: 整理検討テーブルの財務健全性チェックリストも
        // 3→4 項目（営業利益率を追加）。ADR-0010 D6 の反転フラグに乗るため、
        // 営業利益率も「10%未満＝投資根拠の毀損＝met」となる。
        // Red の出方: 件数アサーションが 3 vs 4 で不一致。営業利益率を明示
        // セットするケースも、mass-assignment で捨てられるため現行の
        // fundamental_status / criteria が旧仕様のままとなりアサーション不一致で
        // 失敗する（fatal ではない）。
        test('criteria は UC-004 と同一構造（technical 7 / fundamental 4 / summary の met/near/total）で、財務に「営業利益率」列がある', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestTechnicalIndicator($holding);
            ucFrom011TestFundamentalIndicator($holding);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '7203');

            expect($row['criteria'])->toHaveKeys(['technical', 'fundamental', 'summary']);
            expect($row['criteria']['technical'])->toHaveCount(7);
            expect($row['criteria']['fundamental'])->toHaveCount(4);
            expect($row['criteria']['summary']['technical'])->toHaveKeys(['met', 'near', 'total']);
            expect($row['criteria']['summary']['fundamental'])->toHaveKeys(['met', 'near', 'total']);
            expect($row['criteria']['summary']['technical']['total'])->toBe(7);
            expect($row['criteria']['summary']['fundamental']['total'])->toBe(4);

            $fundamentalLabels = array_column($row['criteria']['fundamental'], 'label');
            expect($fundamentalLabels)->toContain('営業利益率');

            foreach ($row['criteria']['technical'] as $item) {
                expect($item)->toHaveKeys(['label', 'threshold_label', 'value_label', 'status']);
                expect($item['status'])->toBeIn(['met', 'near', 'unmet', 'unavailable']);
            }
        });

        test('全テクニカル基準を満たす含み損銘柄は summary.technical.met が 7 になる', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '6526', 'symbol_name' => 'ソシオネクスト']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding, ['current_price' => 600.00]);
            ucFrom011TestTechnicalIndicator($holding);
            ucFrom011TestFundamentalIndicator($holding);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '6526');

            expect($row['criteria']['summary']['technical']['met'])->toBe(7);
        });

        // ---------------------------------------------------------------
        // CHG-0017 / ADR-0015 D4（別セッションでbe0f00c/418172bとして実装・
        // マージ済み）のレビュー指摘: BuySignalDeterminationService::
        // preconditionsSatisfied()の事前条件Bは対セクター相対力を優先し
        // 対市場相対力へフォールバックするよう変更されたが、この判定チェック
        // リスト（相対力(対市場)行）は改訂前のまま`relative_strength_vs_market`
        // のみを見ており、対セクター相対力を一切考慮していなかった。
        //
        // Expected Red cause: 対市場では基準未満（-12.0 <= -5.0）だが対セクター
        // では基準以上（0.0 > -5.0）の銘柄で、実際の押し目買い事前条件Bは
        // セクター優先により満たされる（弱くない）はずなのに、本チェックリスト
        // 行は対市場のみを見て「met」（整理検討テーブルの反転極性で「基準割れ
        // ＝投資根拠毀損」を意味する赤チップ）を返してしまう。配線後は対セクター
        // が優先され「unmet」になる想定。
        // ---------------------------------------------------------------
        test('対セクター相対力が対市場より優れる銘柄は、判定チェックリストの相対力行が対セクターの値で判定される', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '9001', 'symbol_name' => '相対力優先テスト']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestTechnicalIndicator($holding, [
                'relative_strength_vs_market' => -12.0, // 基準(-5.0)未満、対市場のみ見ると met（弱い）
                'relative_strength_vs_sector' => 0.0, // 基準以上、対セクター優先なら unmet（弱くない）
            ]);
            ucFrom011TestFundamentalIndicator($holding);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '9001');

            // 相対力行は technical 配列の6番目（0-indexed 5）に固定位置で存在する
            // （含み損率／52週高値下落率／52週安値距離／MA75乖離率／MACD-シグナル線／
            // 相対力／押し目買いシグナル件数の順、本ファイル上部のヘルパーdocblock参照）。
            $relativeStrengthItem = $row['criteria']['technical'][5];
            expect($relativeStrengthItem['label'])->toContain('相対力');
            expect($relativeStrengthItem['status'])->toBe('unmet');
            expect($relativeStrengthItem['value_label'])->toBe('+0.0');
        });

        // ---------------------------------------------------------------
        // CR (2026-09-06, CHG-0012 / ADR-0011): 営業利益率の反転チップ
        // ---------------------------------------------------------------
        test('含み損-40%かつ営業利益率5.0%（基準割れ）の銘柄は、判定チェックリストの営業利益率チップが met（反転）・fundamental_status=failed・サマリの投資根拠の毀損に数えられる', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '3088', 'symbol_name' => 'マツキヨココカラ&カンパニー']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestTechnicalIndicator($holding);
            // ROE・自己資本比率・成長率は健全、営業利益率のみ基準割れ
            ucFrom011TestFundamentalIndicator($holding, [
                'roe' => 15.2, 'equity_ratio' => 58.0, 'revenue_growth' => 8.0,
                'operating_income_growth' => 12.3, 'operating_margin' => 5.0,
            ]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '3088');

            expect($row)->not->toBeNull();
            expect($row['fundamental_status'])->toBe('failed');

            $marginChip = null;
            foreach ($row['criteria']['fundamental'] as $item) {
                if ($item['label'] === '営業利益率') {
                    $marginChip = $item;
                }
            }
            expect($marginChip)->not->toBeNull();
            expect($marginChip['status'] ?? null)->toBe('met'); // 反転契約: 10%未満で met
            expect($marginChip['threshold_label'] ?? '')->toContain('<10');

            // fundamental_summary に営業利益率が「（基準10%未満）」付きで含まれる
            // （use-cases.md UC-011 出力例「…・営業利益率7.8%（基準10%未満）」相当）
            expect($row['fundamental_summary'])->toContain('営業利益率');
            expect($row['fundamental_summary'])->toContain('基準10%未満');
        });

        test('含み損銘柄でも営業利益率が20.0%（健全）なら、判定チェックリストの営業利益率チップは unmet（＝毀損していない）', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '6758', 'symbol_name' => 'ソニーグループ']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestTechnicalIndicator($holding);
            ucFrom011TestFundamentalIndicator($holding, [
                'roe' => 15.2, 'equity_ratio' => 58.0, 'operating_margin' => 20.0,
            ]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '6758');

            $marginChip = null;
            foreach ($row['criteria']['fundamental'] as $item) {
                if ($item['label'] === '営業利益率') {
                    $marginChip = $item;
                }
            }
            expect($marginChip)->not->toBeNull();
            expect($marginChip['status'] ?? null)->toBe('unmet');
            expect($marginChip['value_label'] ?? null)->toBe('20.0%');
        });
    });

    describe('エラーケース', function () {
        test('対象銘柄が1件も存在しない場合は空配列を返す', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '7203', 'symbol_name' => 'トヨタ自動車']);
            // 含み益銘柄のみ（対象外）
            ucFrom011TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 1400.00, 'unrealized_gain_amount' => 40000, 'unrealized_gain_rate' => 40.0,
            ]);

            expect(ucFrom011TestExecute())->toBe([]);
        });

        test('スナップショットが1件も存在しない場合も空配列を返す', function () {
            expect(ucFrom011TestExecute())->toBe([]);
        });
    });

    describe('/review 指摘の回帰防止（CHG-0010）', function () {
        test('含み損率がちょうど -100%（上場廃止・売買停止等）でも 500 にならず recovery_required_rate は null', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '9999', 'symbol_name' => '上場廃止銘柄']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 0.00, 'unrealized_gain_amount' => -100000, 'unrealized_gain_rate' => -100.0,
            ]);
            ucFrom011TestFundamentalIndicator($holding);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '9999');

            expect($row)->not->toBeNull();
            expect($row['recovery_required_rate'])->toBeNull();
        });

        test('財務 failed の銘柄に押し目シグナルがあっても、買い増しリストには載らないため also_on_buy_list は false', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '6501', 'symbol_name' => '日立製作所']);
            $holdingSnapshot = ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestBuySignal($holdingSnapshot, ['signal_type' => 'rsi_oversold_rebound']);
            ucFrom011TestFundamentalIndicator($holding, ['roe' => 4.2, 'equity_ratio' => 28.0]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '6501');

            expect($row['fundamental_status'])->toBe('failed');
            // ShowBuySignalListAction は failed を除外するため、買い増し候補には載らない
            expect($row['also_on_buy_list'])->toBeFalse();
            // 生カウント（技術的な反発サインの有無）・並び順キーは保持する
            expect($row['rebound_buy_signal_count'])->toBe(1);
            expect($row['rebound_buy_signal_present'])->toBeTrue();
            // 理由サマリは「買い増し候補にも掲載」と誤って言わない
            expect($row['loss_review_reason_summary'])->not->toContain('買い増し候補にも掲載');
        });

        test('成長率がちょうど 0.0% の銘柄の fundamental_summary は「マイナス」ではなく「0%以下」と表記する', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '3407', 'symbol_name' => '旭化成']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding);
            // ROE・自己資本比率は健全、成長率のみちょうど 0.0（FundamentalHealthEvaluator は >0 で健全判定のため failed になる）。
            // 2026-09-19修正（CHG-0017／ADR-0015 D1）: roe/equity_ratio は元々
            // 15.2/58.0 だったが、これはD1のRESCUE閾値（roe>=15.0 &&
            // equityRatio>=50.0）と衝突し 'passed' に反転してしまうため、基本
            // 条件（10%/40%）は満たすがRESCUE閾値（15%/50%）は満たさない値
            // （12.0/45.0）に変更した。本テストの意図（成長率0%の文言表記）は
            // 変更していない。
            ucFrom011TestFundamentalIndicator($holding, [
                'roe' => 12.0, 'equity_ratio' => 45.0, 'revenue_growth' => 0.0, 'operating_income_growth' => -2.0,
            ]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '3407');

            expect($row['fundamental_status'])->toBe('failed');
            expect($row['fundamental_summary'])->not->toContain('成長率マイナス');
            expect($row['fundamental_summary'])->toContain('0%以下');
        });

        // ---------------------------------------------------------------
        // CR (2026-09-20, CHG-0017 / ADR-0015 D2 配線 Cycle 4c)
        // ---------------------------------------------------------------
        // FundamentalHealthEvaluator自体はD2救済（直近3期平均成長率>0%の
        // OR救済）を実装済みだが、ShowLossReviewListAction はまだ
        // avg_revenue_growth/avg_operating_income_growth を evaluate() に
        // 渡していない。
        // Red の出方（2026-09-20）: roe=12.0/equity_ratio=45.0（D1のRESCUE閾値
        // ROE≧15%/自己資本比率≧50%は満たさない）・単年度成長率は両方マイナス
        // のため、現行実装は fundamental_status='failed' のままとなり、
        // 'passed' を期待する以下のアサーションが不一致になる。
        test('自己資本比率45.0%/ROE12.0%（D1のRESCUE閾値は満たさない）で単年度成長率が両方マイナスだが3期平均営業利益成長率がプラスの銘柄は、D2救済によりfundamental_status=passedになる', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '1605', 'symbol_name' => 'INPEX']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestFundamentalIndicator($holding, [
                'roe' => 12.0, 'equity_ratio' => 45.0,
                'revenue_growth' => -3.0, 'operating_income_growth' => -1.0,
                'avg_operating_income_growth' => 1.5,
            ]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '1605');

            expect($row)->not->toBeNull();
            expect($row['fundamental_status'])->toBe('passed');
        });

        // ---------------------------------------------------------------
        // Feature Test拡充（2026-09-21、/review 3回目対応・Cycle7）
        // ---------------------------------------------------------------
        // D1（ROE≧15%かつ自己資本比率≧50%の財務指標救済）単独のケース
        // （単年度・3期平均とも成長率がプラスでない）が、UC-011画面
        // （ShowLossReviewListAction）で正しくfundamental_status=passedに
        // なることを確認するFeature Testが存在しなかった（D2救済のテストは
        // あるが、D1単独のケースが未検証）。
        test('ROE17.9%/自己資本比率55.0%（D1のRESCUE閾値を満たす）で単年度・3期平均とも成長率がプラスでない銘柄は、D1救済によりfundamental_status=passedになる', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '1606', 'symbol_name' => 'D1救済確認株']);
            ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestFundamentalIndicator($holding, [
                'roe' => 17.9, 'equity_ratio' => 55.0,
                'revenue_growth' => -5.0, 'operating_income_growth' => -3.0,
                'avg_revenue_growth' => -2.0, 'avg_operating_income_growth' => -1.0,
            ]);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '1606');

            expect($row)->not->toBeNull();
            expect($row['fundamental_status'])->toBe('passed');
        });

        test('財務 passed の銘柄に押し目シグナルがあれば also_on_buy_list は true（買い増しリストにも載る）', function () {
            [, $snapshot] = ucFrom011TestBatch();
            $holding = ucFrom011TestHolding(['symbol_code' => '4063', 'symbol_name' => '信越化学工業']);
            $holdingSnapshot = ucFrom011TestHoldingSnapshot($snapshot, $holding);
            ucFrom011TestBuySignal($holdingSnapshot, ['signal_type' => 'rsi_oversold_rebound']);
            ucFrom011TestFundamentalIndicator($holding);

            $row = ucFrom011TestFindRow(ucFrom011TestExecute(), '4063');

            expect($row['fundamental_status'])->toBe('passed');
            expect($row['also_on_buy_list'])->toBeTrue();
            expect($row['loss_review_reason_summary'])->toContain('買い増し候補にも掲載');
        });
    });
});
