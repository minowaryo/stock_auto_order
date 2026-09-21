<?php

namespace Tests\Unit\Actions\Portfolio;

use App\Actions\Signal\ShowBuySignalListAction;
use App\Actions\Signal\ShowLossReviewListAction;
use App\Actions\Signal\ShowSignalListAction;
use App\Actions\Watchlist\ShowWatchlistAction;
use App\Models\BuySignal;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\WatchlistBuySignal;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| UC-013: ポートフォリオ分類ダッシュボード（App\Actions\Portfolio\ClassifyHoldingsAction）
| — Red phase Unit Test (Cycle 1)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-013（出力表・業務ルール全項目・エラーケース）
|   - docs/adr/ADR-0014-portfolio-bucket-classification.md D2〜D6・D9〜D10（特に
|     D10-1: take_profit の並び順ロジックは ShowSignalListAction 本体に実装する
|     ため、本ファイルのスコープ外＝ClassifyHoldingsAction は take_profit の
|     並び順を「ShowSignalListAction が返す順序をそのまま維持する」ことだけを
|     検証する）
|   - docs/architecture/data-model.md「保留・確定が必要な初期パラメータ値」表
|     `hold`バケツの並び順／`hold_watch`判定基準（いずれも叩き台のまま）
|   - 参照実装（読み取りのみ・無改修）: ShowSignalListAction（UC-004）/
|     ShowBuySignalListAction（UC-010）/ ShowLossReviewListAction（UC-011）/
|     SectorAllocationCalculator（UC-005）/ ShowWatchlistAction（UC-012）/
|     FundamentalHealthEvaluator / LossReviewThresholds
|
| -------------------------------------------------------------------------
| スコープ・実行対象
| -------------------------------------------------------------------------
| App\Actions\Portfolio\ClassifyHoldingsAction は未実装（app/Actions/Portfolio/
| ディレクトリ自体が存在しない）。ucFrom013TestExecute() を呼ぶすべてのテストは
| コンテナ解決時に "Class ... not found" 相当の fatal error で失敗する想定
| （アサーション不一致ではない）。これが意図した Red 状態である。
|
| このテストは DB を使う（RefreshDatabase）ため tests/Unit 配下だが明示的に
| uses(TestCase::class, RefreshDatabase::class) を宣言している（tests/Pest.php
| の RefreshDatabase 自動適用は Feature ディレクトリのみのため）。
|
| -------------------------------------------------------------------------
| このRedフェーズが提案する契約（Gate 4 で異なる形が良ければ指摘してください）
| -------------------------------------------------------------------------
|   - App\Actions\Portfolio\ClassifyHoldingsAction::execute(): array<string, mixed>
|     直近スナップショットが無ければ classified_at=null・全項目ゼロ/空配列。
|   - 返り値のキー: classified_at, group_summary, hold_breakdown, buckets,
|     sector_overweight_summary, new_entry_reference（use-cases.md UC-013出力表）
|   - buckets は6種類（core_accumulation/loss_review/take_profit/add_on/hold/
|     new_entry）すべてを常に含む（該当0件でも holdings: [] で出す）。各要素は
|     bucket/group/holdings。
|   - holdings[] の各行: symbol_code, symbol_name, market, instrument_type,
|     market_value, unrealized_gain_rate, bucket_reason, also_matched,
|     overweight_sector, hold_watch（holdバケツのみ意味を持つ）,
|     health_line（holdバケツのみ意味を持つ）
|   - market_value は既存流用（保有数量×現在値。投資信託は
|     PortfolioEvaluationCalculator/SectorAllocationCalculator と同じ ÷10000 の
|     基準価額補正を適用。US株の円換算は holding_snapshots.current_price が
|     取込時に済ませている前提を踏襲）。
|   - 優先順位（ADR-0014 D3）: core_accumulation → loss_review → take_profit →
|     add_on → hold の順で最初に一致したバケツを採用。落ちたバケツは
|     also_matched に記録する。
|   - hold_watch 判定（ADR-0014 D5、data-model.md「未確定」）: 以下いずれかで true
|       (a) fundamental_status === 'failed'
|       (b) 相対力〔対市場〕が継続マイナス — technical_indicators は
|           holding_id 1:1 キャッシュで履歴を持たないため、このRedフェーズでは
|           直近スナップショット時点の relative_strength_vs_market < 0 を
|           「継続マイナス」の近似とみなしてテストする（履歴を使わない解釈。
|           異なる解釈が必要であれば Gate 4 で指摘してください）
|       (c) 含み益率が整理検討ライン（-20%）の手前まで悪化 — data-model.md の
|           叩き台レンジ「-15%〜-20%」のうち、このRedフェーズではレンジの
|           上限である -15.0%（以下）をバッファ境界として採用してテストする
|           （境界値の最終確定は Gate 4）
|   - バケツ内ソート順（ADR-0014 D10）:
|       - add_on / loss_review / new_entry: 供給元Action
|         （ShowBuySignalListAction / ShowLossReviewListAction /
|         ShowWatchlistAction）が返す順序をそのまま維持する（新規ソートしない）
|       - take_profit: ShowSignalListAction が返す順序をそのまま維持する
|         （D10-1により ShowSignalListAction 自体に並び順ロジックが追加される
|         想定だが、その追加自体は本ファイル・本サイクルのスコープ外）
|       - hold: ① hold_watch=true を先頭 ② 残りは含み損益率が低い順（新規設計）
|       - core_accumulation: market_value が大きい順（新規設計）
|   - 評価額構成比（ADR-0014 D6）: 各グループ（reduce/hold/increase）の
|     Σmarket_value ÷ 全保有（new_entry除く）Σmarket_value。hold_breakdown は
|     hold グループを core_accumulation/hold に内訳分解したもの。
|   - セクター偏りサマリ（ADR-0014 D4）: SectorAllocationCalculator::calculate()
|     をそのまま使い、allocation_rate−70 の降順で並べる。偏り警告
|     （allocation_status==='偏り警告'）セクターに属する銘柄行に
|     overweight_sector=true を立てる。
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom013TestBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom013TestHolding(array $attributes = []): Holding
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
 * Flat / neutral default (gain 0%): 100株 取得単価1000 現在値1000.
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom013TestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 1000,
        'current_price' => 1000,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 0,
        'unrealized_gain_rate' => 0.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * Healthy defaults: relative_strength_vs_market プラス（hold_watch (b) 非該当）.
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom013TestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 50.0,
        'macd' => 1.0,
        'macd_signal' => 0.5,
        'ma20' => 1000.0,
        'ma75' => 950.0,
        'bb_upper' => 1100.0,
        'bb_lower' => 900.0,
        'volume' => 1_000_000,
        'volume_ma20' => 1_000_000.0,
        'week52_high' => 1200.0,
        'week52_low' => 800.0,
        'relative_strength_vs_market' => 6.0,
        'relative_strength_vs_sector' => 6.0,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * Healthy defaults (fundamental_status='passed'). Override roe/equity_ratio
 * for the 'failed' cases (hold_watch (a)).
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom013TestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
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
            'operating_margin' => 18.3,
            'fetched_at' => now(),
        ], $attributes),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom013TestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): Signal
{
    return Signal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72まで上昇し利確ラインを超過しました',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom013TestBuySignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): BuySignal
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
function ucFrom013TestAccount(HoldingSnapshot $holdingSnapshot, array $attributes = []): HoldingSnapshotAccount
{
    return HoldingSnapshotAccount::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'account_type' => 'specific',
        'quantity' => 100,
        'average_cost' => 1000.00,
    ], $attributes));
}

function ucFrom013TestSector(string $name): SectorClassification
{
    return SectorClassification::firstOrCreate(['name' => $name], ['code' => null]);
}

/**
 * Unheld watchlist item (UC-012 / new_entry supply source). Mirrors
 * tests/Feature/UC012WatchlistScreenTest.php::uc012ScreenWatchlist() but
 * intentionally does NOT mark the holding as held on any snapshot.
 *
 * @param  array<string, mixed>  $opts
 */
function ucFrom013TestWatchlistItem(string $code, array $opts = []): WatchlistItem
{
    $holding = Holding::create([
        'symbol_code' => $code,
        'market' => $opts['market'] ?? 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => $opts['name'] ?? "銘柄{$code}",
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ]);

    TechnicalIndicator::create([
        'holding_id' => $holding->id,
        'rsi' => $opts['rsi'] ?? 50.0,
        'week52_high' => 2000.0,
        'week52_low' => 1000.0,
        'ma20' => 1500.0,
        'computed_at' => now(),
    ]);

    FundamentalIndicator::create([
        'holding_id' => $holding->id,
        'per' => 15.0,
        'pbr' => 1.5,
        'roe' => $opts['roe'] ?? 12.0,
        'equity_ratio' => $opts['equity_ratio'] ?? 45.0,
        'revenue_growth' => 5.0,
        'operating_income_growth' => 5.0,
        'operating_margin' => 12.0,
        'eps_growth' => 5.0,
        'peg_ratio' => 1.0,
        'fetched_at' => now(),
    ]);

    $item = WatchlistItem::create([
        'holding_id' => $holding->id,
        'folder_name' => $opts['folder'] ?? 'テーマA',
        'exchange_label' => '東Ｐ',
        'source' => 'rakuten_favorites_csv',
        'is_starred' => false,
        'last_close' => $opts['last_close'] ?? 1500.0,
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);

    // NOTE: range(1, 0) returns [1, 0] (2 elements) in PHP, not an empty
    // array — a for-loop is used instead to avoid accidentally inserting a
    // row (and violating the holding_id+signal_type unique key below) when
    // 'buy_signals' is omitted/0 (cf. tests/Feature/UC012WatchlistScreenTest.php
    // uc012ScreenWatchlist(), which has the same range() pattern but happens
    // not to collide because it varies signal_type per iteration).
    $types = ['rsi_oversold_rebound', 'macd_golden_cross', 'bollinger_oversold', 'week52_low_proximity'];
    for ($i = 0; $i < ($opts['buy_signals'] ?? 0); $i++) {
        WatchlistBuySignal::create([
            'holding_id' => $holding->id,
            'signal_type' => $types[$i] ?? 'ma_deviation_oversold',
            'reason_summary' => 'テスト',
            'determined_at' => now(),
        ]);
    }

    return $item;
}

/**
 * Resolve + run the (not-yet-implemented) Action. Primary Red trigger.
 *
 * @return array<string, mixed>
 */
function ucFrom013TestExecute(): array
{
    return app('App\\Actions\\Portfolio\\ClassifyHoldingsAction')->execute();
}

/**
 * @param  array<int, array<string, mixed>>  $buckets
 * @return array<string, mixed>|null
 */
function ucFrom013TestFindBucket(array $buckets, string $bucket): ?array
{
    foreach ($buckets as $row) {
        if (($row['bucket'] ?? null) === $bucket) {
            return $row;
        }
    }

    return null;
}

/**
 * @param  array<int, array<string, mixed>>  $holdings
 * @return array<string, mixed>|null
 */
function ucFrom013TestFindHolding(array $holdings, string $symbolCode): ?array
{
    foreach ($holdings as $row) {
        if (($row['symbol_code'] ?? null) === $symbolCode) {
            return $row;
        }
    }

    return null;
}

describe('UC-013: ポートフォリオ分類ダッシュボード（ClassifyHoldingsAction）', function () {
    describe('バケツ分類（正常系、ADR-0014 D2）', function () {
        test('ETFは core_accumulation に分類される', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding([
                'symbol_code' => 'VTI', 'market' => 'us', 'instrument_type' => 'etf',
                'symbol_name' => 'Vanguard Total Stock Market ETF',
            ]);
            ucFrom013TestHoldingSnapshot($snapshot, $holding, ['quantity' => 50, 'current_price' => 300]);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'core_accumulation');

            expect($bucket)->not->toBeNull();
            expect($bucket['group'])->toBe('hold');
            $row = ucFrom013TestFindHolding($bucket['holdings'], 'VTI');
            expect($row)->not->toBeNull();
            expect((float) $row['market_value'])->toEqualWithDelta(15000.0, 0.01);
        });

        test('投資信託は core_accumulation に分類され、market_value は基準価額の÷10000補正込みで算出される', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding([
                'symbol_code' => 'eMAXIS Slim 全世界株式', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund',
                'symbol_name' => 'eMAXIS Slim 全世界株式',
            ]);
            // 100,000口 × 基準価額15,000円(1万口あたり) ÷ 10000 = 150,000円
            ucFrom013TestHoldingSnapshot($snapshot, $holding, ['quantity' => 100000, 'current_price' => 15000]);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'core_accumulation');
            $row = ucFrom013TestFindHolding($bucket['holdings'], 'eMAXIS Slim 全世界株式');

            expect($row)->not->toBeNull();
            expect((float) $row['market_value'])->toEqualWithDelta(150000.0, 0.01);
        });

        test('保有がすべてNISAつみたて投資枠のみの個別株は core_accumulation に分類される', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '1234', 'symbol_name' => 'つみたて専用株']);
            $holdingSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $holding);
            ucFrom013TestAccount($holdingSnapshot, ['account_type' => 'nisa_tsumitate', 'quantity' => 100]);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'core_accumulation');

            expect(ucFrom013TestFindHolding($bucket['holdings'], '1234'))->not->toBeNull();
        });

        test('整理検討ラインを下回る個別株は loss_review に分類される', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '9001', 'symbol_name' => '整理検討テスト']);
            ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 750, 'unrealized_gain_amount' => -25000, 'unrealized_gain_rate' => -25.0,
            ]);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'loss_review');

            expect(ucFrom013TestFindHolding($bucket['holdings'], '9001'))->not->toBeNull();
        });

        test('利確検討条件を満たす個別株は take_profit に分類される', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '9002', 'symbol_name' => '利確検討テスト']);
            $holdingSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 1250, 'unrealized_gain_amount' => 25000, 'unrealized_gain_rate' => 25.0,
            ]);
            ucFrom013TestSignal($holdingSnapshot);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'take_profit');

            expect(ucFrom013TestFindHolding($bucket['holdings'], '9002'))->not->toBeNull();
        });

        test('押し目買いシグナルのある個別株は add_on に分類される', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '9003', 'symbol_name' => '買い増し検討テスト']);
            $holdingSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $holding);
            ucFrom013TestBuySignal($holdingSnapshot);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'add_on');

            expect(ucFrom013TestFindHolding($bucket['holdings'], '9003'))->not->toBeNull();
        });

        test('いずれにも該当しない健全な個別株は hold に分類される', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '9004', 'symbol_name' => 'キープテスト']);
            ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0,
            ]);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'hold');

            expect(ucFrom013TestFindHolding($bucket['holdings'], '9004'))->not->toBeNull();
        });

        test('take_profit の bucket_reason は SignalCriteriaEvaluator の達成度データから機械生成され、銘柄ごとに内容が異なる（ADR-0014 D9-4、/review指摘対応）', function () {
            [, $snapshot] = ucFrom013TestBatch();

            $holdingA = ucFrom013TestHolding(['symbol_code' => '9101', 'symbol_name' => '利確A']);
            $snapshotA = ucFrom013TestHoldingSnapshot($snapshot, $holdingA, [
                'current_price' => 1250, 'unrealized_gain_amount' => 25000, 'unrealized_gain_rate' => 25.0,
            ]);
            ucFrom013TestSignal($snapshotA);
            ucFrom013TestTechnicalIndicator($holdingA, ['rsi' => 82.0]);
            ucFrom013TestFundamentalIndicator($holdingA);

            $holdingB = ucFrom013TestHolding(['symbol_code' => '9102', 'symbol_name' => '利確B']);
            $snapshotB = ucFrom013TestHoldingSnapshot($snapshot, $holdingB, [
                'current_price' => 1300, 'unrealized_gain_amount' => 30000, 'unrealized_gain_rate' => 30.0,
            ]);
            ucFrom013TestSignal($snapshotB);
            ucFrom013TestTechnicalIndicator($holdingB, ['rsi' => 40.0]);
            ucFrom013TestFundamentalIndicator($holdingB);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'take_profit');
            $rowA = ucFrom013TestFindHolding($bucket['holdings'], '9101');
            $rowB = ucFrom013TestFindHolding($bucket['holdings'], '9102');

            // 固定文言（旧実装）ではないこと。
            expect($rowA['bucket_reason'])->not->toBe('利確検討条件を満たすシグナルあり');
            // SignalCriteriaEvaluator の達成度（技術◯/◯達成）を含む機械生成の文言であること。
            expect($rowA['bucket_reason'])->toMatch('/技術\d+\/\d+達成/');
            expect($rowB['bucket_reason'])->toMatch('/技術\d+\/\d+達成/');
            // 銘柄ごとに実測値が異なるため、bucket_reason の内容も異なること
            // （固定のバケツ種別文言に戻っていないことの確認）。
            expect($rowA['bucket_reason'])->not->toBe($rowB['bucket_reason']);
        });

        test('未保有のウォッチリスト銘柄は new_entry_reference に参考表示され、buckets 側の new_entry の内容・順序も ShowWatchlistAction と一致する', function () {
            ucFrom013TestWatchlistItem('5555', ['name' => 'アルファ工業', 'buy_signals' => 1]);
            ucFrom013TestWatchlistItem('6666', ['name' => 'ベータ商事']);

            $expected = app(ShowWatchlistAction::class)->execute();
            $result = ucFrom013TestExecute();

            expect(collect($result['new_entry_reference'])->pluck('symbol_code')->all())
                ->toBe(collect($expected)->pluck('symbol_code')->all());

            $bucket = ucFrom013TestFindBucket($result['buckets'], 'new_entry');
            expect(collect($bucket['holdings'])->pluck('symbol_code')->all())
                ->toBe(collect($expected)->pluck('symbol_code')->all());

            $reference = ucFrom013TestFindHolding($result['new_entry_reference'], '5555');
            expect($reference)->toHaveKeys(['symbol_code', 'symbol_name', 'folder_name', 'rebound_buy_signal_count', 'fundamental_status']);
        });
    });

    describe('優先順位・排他解決（ADR-0014 D3）', function () {
        test('core_accumulation の判定は loss_review より優先される（NISAつみたて専用株は含み損が深くても loss_review に落ちない）', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '2345', 'symbol_name' => 'つみたて含み損株']);
            $holdingSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 700, 'unrealized_gain_amount' => -30000, 'unrealized_gain_rate' => -30.0,
            ]);
            ucFrom013TestAccount($holdingSnapshot, ['account_type' => 'nisa_tsumitate', 'quantity' => 100]);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();

            expect(ucFrom013TestFindHolding(ucFrom013TestFindBucket($result['buckets'], 'core_accumulation')['holdings'], '2345'))->not->toBeNull();
            expect(ucFrom013TestFindHolding(ucFrom013TestFindBucket($result['buckets'], 'loss_review')['holdings'], '2345'))->toBeNull();
        });

        test('loss_review と add_on の両方に該当する銘柄は loss_review を採用し、also_matched に add_on を記録する', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '3456', 'symbol_name' => '競合テスト株']);
            $holdingSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 750, 'unrealized_gain_amount' => -25000, 'unrealized_gain_rate' => -25.0,
            ]);
            ucFrom013TestBuySignal($holdingSnapshot);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();

            $lossReviewBucket = ucFrom013TestFindBucket($result['buckets'], 'loss_review');
            $addOnBucket = ucFrom013TestFindBucket($result['buckets'], 'add_on');

            $row = ucFrom013TestFindHolding($lossReviewBucket['holdings'], '3456');
            expect($row)->not->toBeNull();
            expect($row['also_matched'])->toContain('add_on');
            expect(ucFrom013TestFindHolding($addOnBucket['holdings'], '3456'))->toBeNull();
        });
    });

    describe('hold_watch（要観察フラグ、ADR-0014 D5）', function () {
        test('財務健全性が failed の hold銘柄は hold_watch=true になる', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '4001', 'symbol_name' => '財務悪化株']);
            ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0,
            ]);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding, ['equity_ratio' => 20.0, 'roe' => 3.0]);

            $result = ucFrom013TestExecute();
            $row = ucFrom013TestFindHolding(ucFrom013TestFindBucket($result['buckets'], 'hold')['holdings'], '4001');

            expect($row)->not->toBeNull();
            expect($row['hold_watch'])->toBeTrue();
        });

        test('対セクター相対力が未算出（null）で対市場相対力がマイナスの hold銘柄は hold_watch=true になる（フォールバック、CHG-0017 D4）', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '4002', 'symbol_name' => '相対力劣後株']);
            ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0,
            ]);
            // relative_strength_vs_sectorを明示的にnullにし、対市場へのフォールバックを検証する
            // （デフォルトフィクスチャは両方6.0のため、対セクターを上書きしないと
            // 対セクター優先〔下のテスト〕と区別できない）。
            ucFrom013TestTechnicalIndicator($holding, ['relative_strength_vs_market' => -8.0, 'relative_strength_vs_sector' => null]);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();
            $row = ucFrom013TestFindHolding(ucFrom013TestFindBucket($result['buckets'], 'hold')['holdings'], '4002');

            expect($row)->not->toBeNull();
            expect($row['hold_watch'])->toBeTrue();
        });

        test('対セクター相対力が算出済みでマイナスの hold銘柄は、対市場相対力がプラスでも hold_watch=true になる（対セクター優先、CHG-0017 D4）', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '4002B', 'symbol_name' => '対セクター劣後株']);
            ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0,
            ]);
            ucFrom013TestTechnicalIndicator($holding, ['relative_strength_vs_market' => 6.0, 'relative_strength_vs_sector' => -3.0]);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();
            $row = ucFrom013TestFindHolding(ucFrom013TestFindBucket($result['buckets'], 'hold')['holdings'], '4002B');

            expect($row)->not->toBeNull();
            expect($row['hold_watch'])->toBeTrue();
        });

        test('含み益率が整理検討ラインの手前（叩き台-15%以下）まで悪化した hold銘柄は hold_watch=true になる', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '4003', 'symbol_name' => '含み損拡大株']);
            // -16.0%: 整理検討ライン(-20%未満)は下回らないため loss_review 対象外だが、
            // 叩き台バッファ境界 -15.0% 以下のため hold_watch=true を期待する。
            ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 840, 'unrealized_gain_amount' => -16000, 'unrealized_gain_rate' => -16.0,
            ]);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'hold');
            $row = ucFrom013TestFindHolding($bucket['holdings'], '4003');

            expect(ucFrom013TestFindHolding(ucFrom013TestFindBucket($result['buckets'], 'loss_review')['holdings'], '4003'))->toBeNull();
            expect($row)->not->toBeNull();
            expect($row['hold_watch'])->toBeTrue();
        });

        test('いずれの要観察条件にも該当しない健全な hold銘柄は hold_watch=false になる', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '4004', 'symbol_name' => '健全キープ株']);
            ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0,
            ]);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding);

            $result = ucFrom013TestExecute();
            $row = ucFrom013TestFindHolding(ucFrom013TestFindBucket($result['buckets'], 'hold')['holdings'], '4004');

            expect($row)->not->toBeNull();
            expect($row['hold_watch'])->toBeFalse();
            expect($row['health_line'])->not->toBeNull();
        });
    });

    // -----------------------------------------------------------------------
    // CHG-0017 / ADR-0015 D2（2回目の/review・Cycle6）: fundamentalStatus()が
    // healthEvaluatorArgs()の7要素中5要素しか使っていなかった配線漏れの修正。
    //
    // 他の全呼び出し元（ShowSignalListAction/ShowBuySignalListAction/
    // ShowLossReviewListAction/NewCandidateFinder/ShowWatchlistAction、
    // Cycle4cで7要素化済み）と異なり、ClassifyHoldingsActionだけが
    // avg_revenue_growth/avg_operating_income_growthを渡さずFundamentalHealthEvaluator
    // ::evaluate()を呼んでいたため、D2（3期平均成長率OR救済）で本来passedになる
    // はずの銘柄がポートフォリオ分類ダッシュボードでだけfailedと判定されていた
    // （他画面とのクロス画面不整合、/review 3回目の独立検出で発見）。
    // -----------------------------------------------------------------------

    describe('D2平均成長率レスキューの配線（CHG-0017 / ADR-0015 D2、Cycle6）', function () {
        test('単年度成長率は両方マイナスだが3期平均売上高成長率がプラスの銘柄は、D2救済によりfundamental_statusがpassedになる（D1のRESCUE閾値は満たさない）', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '4005', 'symbol_name' => 'D2救済株']);
            ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0,
            ]);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding, [
                // equity_ratio=45.0/roe=12.0はD1のRESCUE閾値（ROE≧15%かつ
                // 自己資本比率≧50%）を満たさない。単年度revenue_growth/
                // operating_income_growthも両方マイナスのため、旧5引数呼び出し
                // （avg_*を渡さない）ではfailedになる。avg_revenue_growth=+8.0
                // （3期平均、D2）のみがプラスのため、これが正しく渡っていれば
                // passedになるはず。
                'equity_ratio' => 45.0, 'roe' => 12.0,
                'revenue_growth' => -5.0, 'operating_income_growth' => -3.0,
                'avg_revenue_growth' => 8.0, 'avg_operating_income_growth' => -2.0,
            ]);

            $result = ucFrom013TestExecute();
            $row = ucFrom013TestFindHolding(ucFrom013TestFindBucket($result['buckets'], 'hold')['holdings'], '4005');

            expect($row)->not->toBeNull();
            // fundamental_status自体はholdingsの行に出ないため、D2救済が正しく
            // 機能していればhold_watch (a) fundamental_status==='failed' に
            // 該当せずfalseになることで間接確認する。
            expect($row['hold_watch'])->toBeFalse();
        });

        // -------------------------------------------------------------------
        // Feature Test拡充（2026-09-21、/review 3回目対応・Cycle7）
        // -------------------------------------------------------------------
        // D1（ROE≧15%かつ自己資本比率≧50%の財務指標救済）単独のケース
        // （単年度・3期平均とも成長率がプラスでない）が、UC-013画面
        // （ClassifyHoldingsAction）で正しく救済されることを確認するテストが
        // 存在しなかった（D2救済のテストはあるが、D1単独のケースが未検証）。
        test('ROE17.9%/自己資本比率55.0%（D1のRESCUE閾値を満たす）で単年度・3期平均とも成長率がプラスでない銘柄は、D1救済によりhold_watchがfalseになる', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $holding = ucFrom013TestHolding(['symbol_code' => '4006', 'symbol_name' => 'D1救済株']);
            ucFrom013TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0,
            ]);
            ucFrom013TestTechnicalIndicator($holding);
            ucFrom013TestFundamentalIndicator($holding, [
                'equity_ratio' => 55.0, 'roe' => 17.9,
                'revenue_growth' => -5.0, 'operating_income_growth' => -3.0,
                'avg_revenue_growth' => -2.0, 'avg_operating_income_growth' => -1.0,
            ]);

            $result = ucFrom013TestExecute();
            $row = ucFrom013TestFindHolding(ucFrom013TestFindBucket($result['buckets'], 'hold')['holdings'], '4006');

            expect($row)->not->toBeNull();
            expect($row['hold_watch'])->toBeFalse();
        });
    });

    describe('バケツ内ソート順（ADR-0014 D10）', function () {
        test('core_accumulation は評価額（market_value）が大きい順に並ぶ（新規設計）', function () {
            [, $snapshot] = ucFrom013TestBatch();
            $small = ucFrom013TestHolding(['symbol_code' => 'SMALL', 'market' => 'us', 'instrument_type' => 'etf', 'symbol_name' => '小口ETF']);
            ucFrom013TestHoldingSnapshot($snapshot, $small, ['quantity' => 10, 'current_price' => 1000]);
            $large = ucFrom013TestHolding(['symbol_code' => 'LARGE', 'market' => 'us', 'instrument_type' => 'etf', 'symbol_name' => '大口ETF']);
            ucFrom013TestHoldingSnapshot($snapshot, $large, ['quantity' => 100, 'current_price' => 1000]);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'core_accumulation');

            expect(collect($bucket['holdings'])->pluck('symbol_code')->all())->toBe(['LARGE', 'SMALL']);
        });

        test('hold は hold_watch が先頭、残りは含み損益率が低い順に並ぶ（新規設計）', function () {
            [, $snapshot] = ucFrom013TestBatch();

            // 要観察（財務failed）だが含み益率は最も高い +2% → それでも先頭に来る
            $watched = ucFrom013TestHolding(['symbol_code' => 'WATCH', 'symbol_name' => '要観察株']);
            ucFrom013TestHoldingSnapshot($snapshot, $watched, [
                'current_price' => 1020, 'unrealized_gain_amount' => 2000, 'unrealized_gain_rate' => 2.0,
            ]);
            ucFrom013TestTechnicalIndicator($watched);
            ucFrom013TestFundamentalIndicator($watched, ['equity_ratio' => 20.0, 'roe' => 3.0]);

            $low = ucFrom013TestHolding(['symbol_code' => 'LOWG', 'symbol_name' => '低含み損株']);
            ucFrom013TestHoldingSnapshot($snapshot, $low, [
                'current_price' => 950, 'unrealized_gain_amount' => -5000, 'unrealized_gain_rate' => -5.0,
            ]);
            ucFrom013TestTechnicalIndicator($low);
            ucFrom013TestFundamentalIndicator($low);

            $high = ucFrom013TestHolding(['symbol_code' => 'HIGG', 'symbol_name' => '高含み益株']);
            ucFrom013TestHoldingSnapshot($snapshot, $high, [
                'current_price' => 1100, 'unrealized_gain_amount' => 10000, 'unrealized_gain_rate' => 10.0,
            ]);
            ucFrom013TestTechnicalIndicator($high);
            ucFrom013TestFundamentalIndicator($high);

            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'hold');

            expect(collect($bucket['holdings'])->pluck('symbol_code')->all())->toBe(['WATCH', 'LOWG', 'HIGG']);
        });

        test('add_on は ShowBuySignalListAction が返す順序をそのまま維持する（既存流用、無改修）', function () {
            [, $snapshot] = ucFrom013TestBatch();

            $a = ucFrom013TestHolding(['symbol_code' => 'ADDA', 'symbol_name' => '買い増しA']);
            $aSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $a, ['current_price' => 1000, 'unrealized_gain_rate' => 0.0]);
            ucFrom013TestBuySignal($aSnapshot, ['signal_type' => 'rsi_oversold_rebound']);
            ucFrom013TestBuySignal($aSnapshot, ['signal_type' => 'macd_golden_cross']);
            ucFrom013TestTechnicalIndicator($a);
            ucFrom013TestFundamentalIndicator($a);

            $b = ucFrom013TestHolding(['symbol_code' => 'ADDB', 'symbol_name' => '買い増しB']);
            $bSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $b, ['current_price' => 1000, 'unrealized_gain_rate' => 0.0]);
            ucFrom013TestBuySignal($bSnapshot, ['signal_type' => 'rsi_oversold_rebound']);
            ucFrom013TestTechnicalIndicator($b);
            ucFrom013TestFundamentalIndicator($b);

            $expected = collect(app(ShowBuySignalListAction::class)->execute())->pluck('symbol_code')->all();
            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'add_on');

            expect($expected)->toContain('ADDA')->toContain('ADDB');
            expect(collect($bucket['holdings'])->pluck('symbol_code')->all())->toBe($expected);
        });

        test('loss_review は ShowLossReviewListAction が返す順序をそのまま維持する（既存流用、無改修）', function () {
            [, $snapshot] = ucFrom013TestBatch();

            $a = ucFrom013TestHolding(['symbol_code' => 'LRA', 'symbol_name' => '整理A']);
            ucFrom013TestHoldingSnapshot($snapshot, $a, ['current_price' => 600, 'unrealized_gain_amount' => -40000, 'unrealized_gain_rate' => -40.0]);
            ucFrom013TestTechnicalIndicator($a);
            ucFrom013TestFundamentalIndicator($a);

            $b = ucFrom013TestHolding(['symbol_code' => 'LRB', 'symbol_name' => '整理B']);
            ucFrom013TestHoldingSnapshot($snapshot, $b, ['current_price' => 750, 'unrealized_gain_amount' => -25000, 'unrealized_gain_rate' => -25.0]);
            ucFrom013TestTechnicalIndicator($b);
            ucFrom013TestFundamentalIndicator($b, ['equity_ratio' => 20.0, 'roe' => 3.0]);

            $expected = collect(app(ShowLossReviewListAction::class)->execute())->pluck('symbol_code')->all();
            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'loss_review');

            expect(collect($bucket['holdings'])->pluck('symbol_code')->all())->toBe($expected);
        });

        test('take_profit は ShowSignalListAction が返す順序をそのまま維持する（D10-1、ShowSignalListAction自体への並び順追加は本サイクルのスコープ外）', function () {
            [, $snapshot] = ucFrom013TestBatch();

            $a = ucFrom013TestHolding(['symbol_code' => 'TPA', 'symbol_name' => '利確A']);
            $aSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $a, ['current_price' => 1250, 'unrealized_gain_amount' => 25000, 'unrealized_gain_rate' => 25.0]);
            ucFrom013TestSignal($aSnapshot);
            ucFrom013TestTechnicalIndicator($a);
            ucFrom013TestFundamentalIndicator($a);

            $b = ucFrom013TestHolding(['symbol_code' => 'TPB', 'symbol_name' => '利確B']);
            $bSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $b, ['current_price' => 1300, 'unrealized_gain_amount' => 30000, 'unrealized_gain_rate' => 30.0]);
            ucFrom013TestSignal($bSnapshot);
            ucFrom013TestSignal($bSnapshot, ['signal_type' => 'macd_dead_cross']);
            ucFrom013TestTechnicalIndicator($b);
            ucFrom013TestFundamentalIndicator($b);

            $expected = collect(app(ShowSignalListAction::class)->execute())->pluck('symbol_code')->all();
            $result = ucFrom013TestExecute();
            $bucket = ucFrom013TestFindBucket($result['buckets'], 'take_profit');

            expect(collect($bucket['holdings'])->pluck('symbol_code')->all())->toBe($expected);
        });
    });

    describe('評価額構成比（ADR-0014 D6）', function () {
        test('group_summary・hold_breakdown は評価額ベースで算出され、new_entry は分母・分子から除外される', function () {
            [, $snapshot] = ucFrom013TestBatch();

            // core_accumulation: ETF 15,000 + 投資信託 150,000 = 165,000
            $etf = ucFrom013TestHolding(['symbol_code' => 'ETF1', 'market' => 'us', 'instrument_type' => 'etf', 'symbol_name' => 'ETF']);
            ucFrom013TestHoldingSnapshot($snapshot, $etf, ['quantity' => 50, 'current_price' => 300]);
            $mf = ucFrom013TestHolding(['symbol_code' => 'MF1', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund', 'symbol_name' => '投信']);
            ucFrom013TestHoldingSnapshot($snapshot, $mf, ['quantity' => 100000, 'current_price' => 15000]);

            // loss_review: 75,000
            $lr = ucFrom013TestHolding(['symbol_code' => 'LR1', 'symbol_name' => '整理検討']);
            ucFrom013TestHoldingSnapshot($snapshot, $lr, ['current_price' => 750, 'unrealized_gain_amount' => -25000, 'unrealized_gain_rate' => -25.0]);
            ucFrom013TestTechnicalIndicator($lr);
            ucFrom013TestFundamentalIndicator($lr);

            // take_profit: 125,000
            $tp = ucFrom013TestHolding(['symbol_code' => 'TP1', 'symbol_name' => '利確検討']);
            $tpSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $tp, ['current_price' => 1250, 'unrealized_gain_amount' => 25000, 'unrealized_gain_rate' => 25.0]);
            ucFrom013TestSignal($tpSnapshot);
            ucFrom013TestTechnicalIndicator($tp);
            ucFrom013TestFundamentalIndicator($tp);

            // add_on: 100,000
            $ao = ucFrom013TestHolding(['symbol_code' => 'AO1', 'symbol_name' => '買い増し検討']);
            $aoSnapshot = ucFrom013TestHoldingSnapshot($snapshot, $ao, ['current_price' => 1000, 'unrealized_gain_rate' => 0.0]);
            ucFrom013TestBuySignal($aoSnapshot);
            ucFrom013TestTechnicalIndicator($ao);
            ucFrom013TestFundamentalIndicator($ao);

            // hold: 105,000
            $hold = ucFrom013TestHolding(['symbol_code' => 'HD1', 'symbol_name' => 'キープ']);
            ucFrom013TestHoldingSnapshot($snapshot, $hold, ['current_price' => 1050, 'unrealized_gain_amount' => 5000, 'unrealized_gain_rate' => 5.0]);
            ucFrom013TestTechnicalIndicator($hold);
            ucFrom013TestFundamentalIndicator($hold);

            // new_entry: 保有していないため分母・分子どちらにも含めない
            ucFrom013TestWatchlistItem('NE1', ['name' => '新規候補']);

            $result = ucFrom013TestExecute();

            // 総評価額 = 165,000 + 75,000 + 125,000 + 100,000 + 105,000 = 570,000
            $reduce = collect($result['group_summary'])->firstWhere('group', 'reduce');
            $holdGroup = collect($result['group_summary'])->firstWhere('group', 'hold');
            $increase = collect($result['group_summary'])->firstWhere('group', 'increase');

            expect((float) $reduce['market_value_total'])->toEqualWithDelta(200000.0, 0.01);
            expect((float) $reduce['allocation_rate'])->toEqualWithDelta(200000 / 570000 * 100, 0.05);
            expect($reduce['holding_count'])->toBe(2);

            expect((float) $holdGroup['market_value_total'])->toEqualWithDelta(270000.0, 0.01);
            expect((float) $holdGroup['allocation_rate'])->toEqualWithDelta(270000 / 570000 * 100, 0.05);
            expect($holdGroup['holding_count'])->toBe(3);

            expect((float) $increase['market_value_total'])->toEqualWithDelta(100000.0, 0.01);
            expect((float) $increase['allocation_rate'])->toEqualWithDelta(100000 / 570000 * 100, 0.05);
            expect($increase['holding_count'])->toBe(1);

            expect((float) $result['hold_breakdown']['core_accumulation']['market_value_total'])->toEqualWithDelta(165000.0, 0.01);
            expect((float) $result['hold_breakdown']['core_accumulation']['allocation_rate'])->toEqualWithDelta(165000 / 570000 * 100, 0.05);
            expect($result['hold_breakdown']['core_accumulation']['holding_count'])->toBe(2);

            expect((float) $result['hold_breakdown']['hold']['market_value_total'])->toEqualWithDelta(105000.0, 0.01);
            expect((float) $result['hold_breakdown']['hold']['allocation_rate'])->toEqualWithDelta(105000 / 570000 * 100, 0.05);
            expect($result['hold_breakdown']['hold']['holding_count'])->toBe(1);
        });
    });

    describe('セクター偏りサマリ（ADR-0014 D4）', function () {
        test('偏り警告セクターの銘柄行に overweight_sector が立ち、サマリは超過幅（allocation_rate−70）が大きい順に並ぶ', function () {
            [, $snapshot] = ucFrom013TestBatch();

            $semiconductor = ucFrom013TestSector('半導体');
            $automobile = ucFrom013TestSector('自動車');

            // 半導体 80,000（80% → 偏り警告、超過幅+10）
            $overweight = ucFrom013TestHolding(['symbol_code' => 'SEC1', 'symbol_name' => '半導体株', 'sector_classification_id' => $semiconductor->id]);
            ucFrom013TestHoldingSnapshot($snapshot, $overweight, ['current_price' => 800, 'unrealized_gain_rate' => 0.0]);
            ucFrom013TestTechnicalIndicator($overweight);
            ucFrom013TestFundamentalIndicator($overweight);

            // 自動車 20,000（20% → 健全）
            $healthy = ucFrom013TestHolding(['symbol_code' => 'AUTO1', 'symbol_name' => '自動車株', 'sector_classification_id' => $automobile->id]);
            ucFrom013TestHoldingSnapshot($snapshot, $healthy, ['current_price' => 200, 'unrealized_gain_rate' => 0.0]);
            ucFrom013TestTechnicalIndicator($healthy);
            ucFrom013TestFundamentalIndicator($healthy);

            $result = ucFrom013TestExecute();

            $holdBucket = ucFrom013TestFindBucket($result['buckets'], 'hold');
            $overweightRow = ucFrom013TestFindHolding($holdBucket['holdings'], 'SEC1');
            $healthyRow = ucFrom013TestFindHolding($holdBucket['holdings'], 'AUTO1');

            expect($overweightRow['overweight_sector'])->toBeTrue();
            expect($healthyRow['overweight_sector'])->toBeFalse();

            expect($result['sector_overweight_summary'][0])->toHaveKeys(['sector_name', 'allocation_rate', 'allocation_status']);
            expect($result['sector_overweight_summary'][0]['sector_name'])->toBe('半導体');
            expect(collect($result['sector_overweight_summary'])->pluck('sector_name')->all()[0])->toBe('半導体');

            // 超過幅の降順: 半導体(+10) → 自動車(-50)
            $names = collect($result['sector_overweight_summary'])->pluck('sector_name')->all();
            expect(array_search('半導体', $names, true))->toBeLessThan(array_search('自動車', $names, true));
        });
    });

    describe('空状態（エラーケース）', function () {
        test('スナップショットを持つ取込バッチが1件も存在しない場合、分類対象なしの空状態を返す', function () {
            $result = ucFrom013TestExecute();

            expect($result['classified_at'])->toBeNull();
            foreach ($result['group_summary'] as $group) {
                expect((float) $group['market_value_total'])->toEqualWithDelta(0.0, 0.01);
                expect($group['holding_count'])->toBe(0);
            }
            foreach (['core_accumulation', 'loss_review', 'take_profit', 'add_on', 'hold', 'new_entry'] as $bucketName) {
                $bucket = ucFrom013TestFindBucket($result['buckets'], $bucketName);
                expect($bucket)->not->toBeNull();
                expect($bucket['holdings'])->toBe([]);
            }
        });

        test('直近スナップショットに保有銘柄が存在しない場合、buckets は全て空になる（ウォッチリストのみ new_entry_reference に残る）', function () {
            ucFrom013TestBatch();
            ucFrom013TestWatchlistItem('7777', ['name' => 'ウォッチのみ']);

            $result = ucFrom013TestExecute();

            foreach (['core_accumulation', 'loss_review', 'take_profit', 'add_on', 'hold'] as $bucketName) {
                $bucket = ucFrom013TestFindBucket($result['buckets'], $bucketName);
                expect($bucket['holdings'])->toBe([]);
            }
            expect(ucFrom013TestFindHolding($result['new_entry_reference'], '7777'))->not->toBeNull();
        });
    });
});
