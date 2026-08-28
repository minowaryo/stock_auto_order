<?php

namespace Tests\Feature;

use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-004: Signal list (利確シグナル一覧) — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-004)
|   - docs/architecture/data-model.md (holdings / holding_snapshots /
|     snapshots / signals)
|
| SignalDeterminationService and signal persistence (via
| FetchExternalMarketDataAction) are already implemented and Green. This
| file targets the *screen* (list API) only: no route, no Controller, no
| Action exist yet for GET /signals, so every test below is expected to
| fail with a 404 (route not found) rather than an assertion failure. This
| is the intended Red state — same convention as UC002HoldingListTest.php /
| UC003HoldingDetailTest.php (which failed on missing models); here the
| models already exist, so the Red cause is purely "no route yet".
|
| Scope note (updated for the NISA-account follow-up cycle — see
| stock_auto_order-nisa-account-implementation-phase.md 実装方針4 and
| PLAN.md): the CSV-parser writer side for holding_snapshot_accounts
| (ADR-0002) was completed in a prior cycle (Cycle A), so THIS cycle
| (Cycle B) addresses the previously-deferred consumer side in
| ShowSignalListAction. See the "NISA区分除外・課税口座数量ベース化"
| describe block below, which asserts:
|   - holdings whose holding_snapshot_accounts breakdown has zero taxable
|     (specific+general) quantity (i.e. entirely NISA) are excluded from
|     the `/signals` response entirely;
|   - split_limit_suggestion's quantity total is computed against the
|     taxable (specific+general) quantity, not the whole combined
|     quantity, whenever a holding_snapshot_accounts breakdown exists
|     (including when taxable quantity spans multiple rows, e.g.
|     specific + general);
|   - price bands remain based on the whole position's average_cost
|     (unchanged — only the quantity basis changes, not price);
|   - holdings with no holding_snapshot_accounts rows at all (legacy /
|     not-yet-backfilled snapshots) keep the prior behavior of treating
|     the whole quantity as taxable (backward-compatible fallback). This
|     is exercised by the pre-existing tests above (which deliberately
|     create no HoldingSnapshotAccount rows) and MUST keep passing
|     unmodified as a regression check for this fallback.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Endpoint: GET /signals, `auth` middleware, same "web" session guard
|     convention as UC-001/002/003.
|   - Success response body shape: `{"data": [ {symbol_code, symbol_name,
|     unrealized_gain_rate, signal_types, signal_reason_summary,
|     split_limit_suggestion}, ... ] }` (same wrapper convention as
|     UC-002/003).
|   - `signal_types` is an array of the raw `signals.signal_type` enum
|     values (e.g. `['rsi_reversal']`), not a translated/localized label.
|   - When a holding has zero `signals` rows, `signal_types` is `[]` and
|     `signal_reason_summary` is a non-empty Japanese string indicating no
|     signal was detected (exact wording left open — the "シグナルなし" /
|     ShowHoldingDetailAction "no signal" pattern per UC-003 is suggested
|     but only checked loosely here: contains "シグナル" and does not
|     assert exact wording, to avoid over-constraining the Green
|     implementation).
|   - When a holding has one or more `signals` rows,
|     `signal_reason_summary` is a non-empty string containing each
|     signal's `reason_summary` value (order/joiner left to the
|     implementation, per task instructions).
|   - `split_limit_suggestion` is a list of `{price, quantity}` entries
|     (`price` is `null` for the trailing trend-following tier, which has
|     no price band). Three tiers are expected per the initial parameter
|     values confirmed in data-model.md's "保留・確定が必要な初期パラメータ
|     値" table (+20% for 1/3, +35% for a further 1/3, remainder trend
|     -following). Exact rounding is left to the implementation; tests only
|     assert the total quantity across tiers equals the holding's quantity,
|     and that the +20% tier's quantity is close to quantity/3.
|   - decimal-cast numeric fields are compared as floats regardless of
|     whether JSON encodes them as string or number (same convention as
|     UC002/UC003).
|
| -------------------------------------------------------------------------
| CR (2026-08-28, CHG-0006): 利確検討ラインの動的分岐
| -------------------------------------------------------------------------
| docs/product/use-cases.md UC-004業務ルール「利確検討ラインの動的分岐」/
| docs/architecture/data-model.md「利確検討ラインの動的分岐（高水準モード）」
| 行により、ShowSignalListAction はホールディングごとに「シグナル0件 かつ
| App\Services\Analysis\FundamentalHealthEvaluator が'passed'」の場合のみ
| 「高水準モード」（対象抽出+150%超、分割指値+100%/+150%）を適用し、それ以外は
| 従来通り「通常モード」（対象抽出+20%超、分割指値+20%/+35%）のまま扱う想定
| （設計判断・具体的な閾値の妥当性検証は
| tests/Unit/Services/Analysis/TakeProfitThresholdEvaluatorTest.php 側で
| 行う。本ファイルはAction層〔クエリ条件・splitLimitSuggestion()の呼び出し〕
| 経由の統合的な振る舞いのみを検証する）。
|
| 既存テストへの影響確認（全件を目視確認済み・実行して確認済み）: 本ファイルの
| 既存フィクスチャ（ucFrom004TestHolding/ucFrom004TestHoldingSnapshot呼び出し
| 全箇所）は、いずれもFundamentalIndicatorレコードを作成していない
| （ucFrom004Test*ヘルパー群にFundamentalIndicator関連の呼び出しは元々存在
| しない）。$holding->fundamentalIndicator が常にnullとなるため、
| FundamentalHealthEvaluator::evaluate(null, null, null, null) は必ず
| 'unavailable' を返し、どのホールディングも高水準モードの条件
| （シグナル0件 かつ 財務健全性'passed'）を満たし得ない。したがって既存の
| 全12テストケースは全て「通常モードのまま」判定され、この変更による意図しない
| 結果変化は無い（実行して確認済み — 既存12件はいずれもPASSのまま）。
|
| 本CR追加分5件のRed実行結果（実行して確認済み。詳細はエージェント完了報告の
| 実行結果ログを参照）:
|   - 「含み益+120%...一覧から除外される」「...split_limit_suggestionが
|     +100%/+150%地点になる」の2件は、現状の固定+20%/+35%閾値のままでは
|     成立し得ないアサーションのため、意図通りFAIL（アサーション不一致）する。
|   - 「シグナル1件以上」「財務健全性failed」「財務健全性unavailable」の3件は
|     いずれも「通常モードのまま据え置かれる」ことを検証するテストであり、
|     現状の実装（動的分岐が未実装＝常に通常モード相当の固定閾値）でも偶然
|     PASSする。これは意図した設計（動的分岐ロジック導入後もこれらのケースは
|     通常モードのまま変わらない）の回帰防止テストとして機能するものであり、
|     テストが誤っているわけではない（FundamentalHealthEvaluatorTest.phpの
|     "3件のみ本当にRedになる"パターンと同種の状況）。
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom004TestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom004TestHolding(array $attributes = []): Holding
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
 * @param  array<string, mixed>  $attributes
 */
function ucFrom004TestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 300,
        'average_cost' => 1000,
        'current_price' => 1300,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 90000,
        'unrealized_gain_rate' => 30.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom004TestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): Signal
{
    return Signal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72から65に反落',
    ], $attributes));
}

/**
 * Create a `holding_snapshot_accounts` breakdown row for a given
 * HoldingSnapshot (ADR-0002 NISA account-type breakdown).
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom004TestAccount(HoldingSnapshot $holdingSnapshot, array $attributes = []): HoldingSnapshotAccount
{
    return HoldingSnapshotAccount::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'account_type' => 'specific',
        'quantity' => 100,
        'average_cost' => 1000.00,
    ], $attributes));
}

/**
 * Create a `fundamental_indicators` row that
 * App\Services\Analysis\FundamentalHealthEvaluator judges as 'passed'
 * (same values as FundamentalHealthEvaluatorTest's "大きく上回る" case /
 * TakeProfitThresholdEvaluatorTest's `tpteHealthyFundamentals()`:
 * equity_ratio=58.0, roe=15.2, both growth figures comfortably positive).
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom004TestHealthyFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 15.0,
        'pbr' => 1.5,
        'roe' => 15.2,
        'revenue_growth' => 8.0,
        'operating_income_growth' => 12.3,
        'equity_ratio' => 58.0,
        'dividend_yield' => 2.0,
        'dividend_payout_ratio' => 30.0,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * Fetch the signal list as an authenticated user.
 */
function ucFrom004TestFetch(TestCase $test, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->getJson('/api/signals');
}

/**
 * Find a row in the assumed `{"data": [...]}` response body by symbol_code
 * (UC-004 has only one holding per symbol in these tests; symbol_code alone
 * is sufficient to disambiguate).
 *
 * @return array<string, mixed>|null
 */
function ucFrom004TestFindRow(TestResponse $response, string $symbolCode): ?array
{
    $rows = $response->json('data') ?? [];

    foreach ($rows as $row) {
        if (($row['symbol_code'] ?? null) === $symbolCode) {
            return $row;
        }
    }

    return null;
}

describe('UC-004: 利確シグナル一覧', function () {
    describe('正常系', function () {
        test('含み益+20%超・シグナルありの銘柄が一覧に含まれ、signal_types/signal_reason_summary/split_limit_suggestionが反映される', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding([
                'symbol_code' => '7203',
                'market' => 'jp',
                'symbol_name' => 'トヨタ自動車',
            ]);
            $holdingSnapshot = ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 1000.00,
                'current_price' => 1300.00,
                'unrealized_gain_rate' => 30.0,
            ]);
            ucFrom004TestSignal($holdingSnapshot, [
                'signal_type' => 'rsi_reversal',
                'reason_summary' => 'RSIが72から65に反落',
            ]);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '7203');
            expect($row)->not->toBeNull();
            expect($row['symbol_name'])->toBe('トヨタ自動車');
            expect((float) $row['unrealized_gain_rate'])->toEqualWithDelta(30.0, 0.01);
            expect($row['signal_types'])->toBe(['rsi_reversal']);
            expect($row['signal_reason_summary'])->toBeString();
            expect($row['signal_reason_summary'])->toContain('RSIが72から65に反落');

            $suggestion = $row['split_limit_suggestion'];
            expect($suggestion)->toBeArray();
            expect(count($suggestion))->toBeGreaterThanOrEqual(2);

            $totalQuantity = array_sum(array_map(fn ($tier) => (float) $tier['quantity'], $suggestion));
            expect($totalQuantity)->toEqualWithDelta(300.0, 0.5);

            // +20% tier: price ~= average_cost * 1.20, quantity ~= quantity / 3
            $firstTier = $suggestion[0];
            expect((float) $firstTier['price'])->toEqualWithDelta(1200.0, 0.01);
            expect((float) $firstTier['quantity'])->toEqualWithDelta(100.0, 2.0);
        });

        test('複数シグナルが発生している場合、signal_typesに全種別が含まれる', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '9984', 'market' => 'jp', 'symbol_name' => 'ソフトバンクグループ']);
            $holdingSnapshot = ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 100,
                'average_cost' => 5000.00,
                'current_price' => 7000.00,
                'unrealized_gain_rate' => 40.0,
            ]);
            ucFrom004TestSignal($holdingSnapshot, ['signal_type' => 'rsi_reversal', 'reason_summary' => 'RSIが72から65に反落']);
            ucFrom004TestSignal($holdingSnapshot, ['signal_type' => 'macd_dead_cross', 'reason_summary' => 'MACDがデッドクロス']);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '9984');
            expect($row)->not->toBeNull();
            expect($row['signal_types'])->toContain('rsi_reversal');
            expect($row['signal_types'])->toContain('macd_dead_cross');
            expect($row['signal_reason_summary'])->toContain('RSIが72から65に反落');
            expect($row['signal_reason_summary'])->toContain('MACDがデッドクロス');
        });

        test('含み益+20%超・シグナルなしの銘柄も一覧に含まれ、signal_typesは空配列になる', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '6758', 'market' => 'jp', 'symbol_name' => 'ソニーグループ']);
            ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 50,
                'average_cost' => 10000.00,
                'current_price' => 13000.00,
                'unrealized_gain_rate' => 30.0,
            ]);
            // Deliberately no Signal row created.

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '6758');
            expect($row)->not->toBeNull();
            expect($row['signal_types'])->toBe([]);
            expect($row['signal_reason_summary'])->toBeString();
            expect(trim((string) $row['signal_reason_summary']))->not->toBe('');
            expect($row['signal_reason_summary'])->toContain('シグナル');
        });
    });

    describe('境界値・除外条件', function () {
        test('含み益率がちょうど20%（20%超えていない）の銘柄は一覧から除外される', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '8306', 'market' => 'jp', 'symbol_name' => '三菱UFJフィナンシャル・グループ']);
            ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 100,
                'average_cost' => 1000.00,
                'current_price' => 1200.00,
                'unrealized_gain_rate' => 20.0,
            ]);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '8306');
            expect($row)->toBeNull();
        });

        test('ETFは含み益+20%超でも一覧から除外される', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding([
                'symbol_code' => 'VTI', 'market' => 'us', 'instrument_type' => 'etf', 'symbol_name' => 'Vanguard Total Stock Market ETF',
            ]);
            ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 20,
                'average_cost' => 200.00,
                'current_price' => 280.00,
                'unrealized_gain_rate' => 40.0,
            ]);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, 'VTI');
            expect($row)->toBeNull();
        });

        test('投資信託は含み益+20%超でも一覧から除外される', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding([
                'symbol_code' => 'eMAXIS Slim 全世界株式', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund',
                'symbol_name' => 'eMAXIS Slim 全世界株式',
            ]);
            ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 1000,
                'average_cost' => 10000.00,
                'current_price' => 14000.00,
                'unrealized_gain_rate' => 40.0,
            ]);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, 'eMAXIS Slim 全世界株式');
            expect($row)->toBeNull();
        });

        test('対象銘柄が1件も存在しない場合は空配列が返る', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 100,
                'average_cost' => 2000.00,
                'current_price' => 2200.00,
                'unrealized_gain_rate' => 10.0,
            ]);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();
            expect($response->json('data'))->toBe([]);
        });

        test('取込データが1件も存在しない場合も空配列が返る', function () {
            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();
            expect($response->json('data'))->toBe([]);
        });
    });

    describe('NISA区分除外・課税口座数量ベース化', function () {
        test('特定口座とNISA成長投資枠が混在する銘柄は、split_limit_suggestionの数量合計が課税口座分のみになる', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            $holdingSnapshot = ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 1000.00,
                'current_price' => 1300.00,
                'unrealized_gain_rate' => 30.0,
            ]);
            ucFrom004TestAccount($holdingSnapshot, ['account_type' => 'specific', 'quantity' => 200, 'average_cost' => 1000.00]);
            ucFrom004TestAccount($holdingSnapshot, ['account_type' => 'nisa_growth', 'quantity' => 100, 'average_cost' => 1000.00]);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '7203');
            expect($row)->not->toBeNull();

            $suggestion = $row['split_limit_suggestion'];
            expect($suggestion)->toBeArray();

            // Quantity basis must be the taxable (specific+general) portion
            // only (200), not the whole combined quantity (300).
            $totalQuantity = array_sum(array_map(fn ($tier) => (float) $tier['quantity'], $suggestion));
            expect($totalQuantity)->toEqualWithDelta(200.0, 0.5);

            // Price bands are unaffected: still based on the whole
            // position's average_cost (1000 -> +20%/+35%).
            $firstTier = $suggestion[0];
            expect((float) $firstTier['price'])->toEqualWithDelta(1200.0, 0.01);
            expect((float) $firstTier['quantity'])->toEqualWithDelta(200.0 / 3, 2.0);
        });

        test('課税口座が特定口座・一般口座の複数行にまたがる場合、taxable数量はその合計になる', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => 'AAPL', 'market' => 'us', 'symbol_name' => 'Apple Inc.']);
            $holdingSnapshot = ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 1000.00,
                'current_price' => 1300.00,
                'unrealized_gain_rate' => 30.0,
            ]);
            ucFrom004TestAccount($holdingSnapshot, ['account_type' => 'specific', 'quantity' => 150, 'average_cost' => 1000.00]);
            ucFrom004TestAccount($holdingSnapshot, ['account_type' => 'general', 'quantity' => 50, 'average_cost' => 1000.00]);
            ucFrom004TestAccount($holdingSnapshot, ['account_type' => 'nisa_tsumitate', 'quantity' => 100, 'average_cost' => 1000.00]);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, 'AAPL');
            expect($row)->not->toBeNull();

            $suggestion = $row['split_limit_suggestion'];
            $totalQuantity = array_sum(array_map(fn ($tier) => (float) $tier['quantity'], $suggestion));
            // specific(150) + general(50) = 200 taxable, excluding
            // nisa_tsumitate(100).
            expect($totalQuantity)->toEqualWithDelta(200.0, 0.5);
        });

        test('内訳が全てNISA区分（課税口座分が0）の銘柄は含み益+20%超でも一覧から除外される', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '9432', 'market' => 'jp', 'symbol_name' => '日本電信電話']);
            $holdingSnapshot = ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 1000.00,
                'current_price' => 1300.00,
                'unrealized_gain_rate' => 30.0,
            ]);
            ucFrom004TestAccount($holdingSnapshot, ['account_type' => 'nisa_growth', 'quantity' => 200, 'average_cost' => 1000.00]);
            ucFrom004TestAccount($holdingSnapshot, ['account_type' => 'nisa_tsumitate', 'quantity' => 100, 'average_cost' => 1000.00]);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '9432');
            expect($row)->toBeNull();
        });
    });

    describe('利確検討ラインの動的分岐（CHG-0006）', function () {
        test('含み益+120%・シグナル0件・財務健全性passedの銘柄は、高水準モード適用（+150%未満）のため一覧から除外される', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '4901', 'market' => 'jp', 'symbol_name' => '富士フイルム']);
            ucFrom004TestHealthyFundamentalIndicator($holding);
            ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 1000.00,
                'current_price' => 2200.00,
                'unrealized_gain_rate' => 120.0,
            ]);
            // Deliberately no Signal row created (シグナル0件).

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '4901');
            expect($row)->toBeNull();
        });

        test('含み益+160%・シグナル0件・財務健全性passedの銘柄は一覧に含まれ、split_limit_suggestionが+100%/+150%地点になる', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '4902', 'market' => 'jp', 'symbol_name' => '高水準銘柄']);
            ucFrom004TestHealthyFundamentalIndicator($holding);
            ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 1000.00,
                'current_price' => 2600.00,
                'unrealized_gain_rate' => 160.0,
            ]);
            // Deliberately no Signal row created (シグナル0件).

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '4902');
            expect($row)->not->toBeNull();

            $suggestion = $row['split_limit_suggestion'];
            expect($suggestion)->toBeArray();

            // +100% tier: price == average_cost * 2.00 (data-model.md
            // 高水準モード: +100%地点で1/3).
            $firstTier = $suggestion[0];
            expect((float) $firstTier['price'])->toEqualWithDelta(2000.0, 0.01);

            // +150% tier: price == average_cost * 2.50 (data-model.md
            // 高水準モード: +150%地点で1/3).
            $secondTier = $suggestion[1];
            expect((float) $secondTier['price'])->toEqualWithDelta(2500.0, 0.01);

            // 高水準モードである旨がsignal_reason_summaryに含まれる
            // （「+150%」「引き上げ」等のキーワードを緩く検証。正確な文言は
            // Gate4で確認する）。
            $reasonSummary = (string) $row['signal_reason_summary'];
            expect(
                str_contains($reasonSummary, '+150%')
                || str_contains($reasonSummary, '引き上げ')
            )->toBeTrue();
        });

        test('含み益+120%・シグナル1件以上・財務健全性passedの銘柄は、通常モードのまま一覧に含まれる（シグナルありなら財務健全性に関わらず通常モード）', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '4903', 'market' => 'jp', 'symbol_name' => 'シグナルあり銘柄']);
            ucFrom004TestHealthyFundamentalIndicator($holding);
            $holdingSnapshot = ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 1000.00,
                'current_price' => 2200.00,
                'unrealized_gain_rate' => 120.0,
            ]);
            ucFrom004TestSignal($holdingSnapshot, [
                'signal_type' => 'rsi_reversal',
                'reason_summary' => 'RSIが72から65に反落',
            ]);

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '4903');
            expect($row)->not->toBeNull();

            // 通常モードのままなので分割指値は+20%/+35%地点のまま。
            $suggestion = $row['split_limit_suggestion'];
            $firstTier = $suggestion[0];
            expect((float) $firstTier['price'])->toEqualWithDelta(1200.0, 0.01);
        });

        test('含み益+120%・シグナル0件・財務健全性failedの銘柄は、通常モードのまま一覧に含まれる', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '4904', 'market' => 'jp', 'symbol_name' => '財務不健全銘柄']);
            // FundamentalHealthEvaluatorTest「自己資本比率・ROEともに閾値を
            // 下回る場合、failedを返す」と同一値。
            ucFrom004TestHealthyFundamentalIndicator($holding, [
                'equity_ratio' => 20.0,
                'roe' => 3.0,
                'revenue_growth' => -5.0,
                'operating_income_growth' => -2.0,
            ]);
            ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 1000.00,
                'current_price' => 2200.00,
                'unrealized_gain_rate' => 120.0,
            ]);
            // Deliberately no Signal row created (シグナル0件).

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '4904');
            expect($row)->not->toBeNull();

            $suggestion = $row['split_limit_suggestion'];
            $firstTier = $suggestion[0];
            expect((float) $firstTier['price'])->toEqualWithDelta(1200.0, 0.01);
        });

        test('含み益+120%・シグナル0件・ファンダメンタルズ指標未設定（unavailable）の銘柄は、通常モードのまま一覧に含まれる', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding(['symbol_code' => '4905', 'market' => 'jp', 'symbol_name' => '指標未取得銘柄']);
            // Deliberately no FundamentalIndicator row created (unavailable).
            ucFrom004TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 1000.00,
                'current_price' => 2200.00,
                'unrealized_gain_rate' => 120.0,
            ]);
            // Deliberately no Signal row created (シグナル0件).

            $response = ucFrom004TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom004TestFindRow($response, '4905');
            expect($row)->not->toBeNull();

            $suggestion = $row['split_limit_suggestion'];
            $firstTier = $suggestion[0];
            expect((float) $firstTier['price'])->toEqualWithDelta(1200.0, 0.01);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは利確シグナル一覧を取得できない', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding();
            ucFrom004TestHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 30.0]);

            $response = $this->getJson('/api/signals');

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web guard)
            // or a 401/403 (API-style guard). Exact status is an implementation
            // choice left to the Green phase (same convention as UC-001/002/003).
            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
