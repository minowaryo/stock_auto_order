<?php

namespace Tests\Feature;

use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WatchedTheme;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-008: 新規投資候補レコメンド（軽量版）候補一覧本体 — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-008 基本フロー3〜5・出力表・業務ルール・
|     エラーケース)
|   - docs/architecture/data-model.md (holdings / holding_snapshots /
|     snapshots / sector_classifications / fundamental_indicators /
|     watched_themes、「保留・確定が必要な初期パラメータ値」表の
|     財務健全性フィルタ〔自己資本比率40%以上・ROE10%以上〕/
|     NISA推奨追加基準〔未確定〕)
|   - C:\Users\minow\.claude\plans\stock_auto_order-uc008-implementation-phase.md
|     (Cycle 2: NewCandidateFinder サービス + GET /new-candidates)
|   - app/Actions/ImportSummaryReport/ShowImportSummaryReportAction.php
|     ::buildNewCandidateItems()（UC-009向け軽量版の前例。抽出条件は同一だが
|     matched_theme/fundamental_summary/suggested_amount/nisa_recommended
|     等のUC-008固有フィールドは実装されていない）
|
| There is currently no Controller, Route, or Action for `GET
| /new-candidates` at all, so every test below is expected to fail with a
| 404 (route not found) rather than an assertion failure — same convention
| as UC004SignalListTest.php / UC008WatchedThemeTest.php (Red state caused
| purely by "no route yet", not by missing models/tables).
|
| Assumptions made while writing these tests (NOT yet confirmed by an
| implementation — flag during Gate 4 review):
|   - Endpoint: `GET /new-candidates`, `auth` middleware, same single-user
|     "web" session guard convention as UC-001〜UC-004/UC-008(テーマ登録).
|   - Success response body shape: `{"data": [ {symbol_code, symbol_name,
|     matched_theme, fundamental_summary, suggested_amount,
|     nisa_recommended, nisa_recommended_reason}, ... ] }` (same wrapper
|     convention as UC-002〜UC-004).
|   - **`suggested_amount`はGate 4でユーザーに確認済み: 保有評価額合計の
|     1%**（use-cases.mdの「1〜2%」の下限側を採用。上限の2%はUC-010側の
|     参照記述であり、UC-008本体としては1%を正式値とする）。
|   - **`nisa_recommended`の閾値もGate 4で確認済み: 自己資本比率50%以上・
|     ROE15%以上**（UC-010の買い増し側NISA推奨基準と同一値を採用し、
|     将来的な一貫性を保つ）。
|   - 保有評価額合計（`suggested_amount`算出の基礎）は、最新スナップショッ
|     トの全`holding_snapshots`（投資信託を含むポートフォリオ全体）を対象
|     とする。投資信託は`current_price`が10,000口あたりの基準価額のため、
|     評価額は`quantity × current_price ÷ 10000`で補正する（個別株・ETFは
|     `quantity × current_price`のまま）。この補正の有無で結果が数千倍
|     ずれるため、本ファイルのテストケースの少なくとも1本は個別株1件＋
|     投資信託1件を含むポートフォリオで`suggested_amount`を検証する。
|   - `fundamental_summary`は「自己資本比率◯◯%・ROE◯◯%」のような一言サマ
|     リ文字列（具体的な文言はGreen実装に委ねるため、テストでは各数値が
|     文字列内に含まれることだけを緩く検証する）。
|   - `nisa_recommended=false`の場合、`nisa_recommended_reason`は空文字列
|     になる（use-cases.md出力表の記述通り）。
|   - 候補抽出条件（テーマ名とセクター名の完全一致・未保有・
|     `instrument_type=stock`・財務健全性フィルタ）は
|     `ShowImportSummaryReportAction::buildNewCandidateItems()`と同一とする。
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom008CandidateTestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom008CandidateTestHolding(array $attributes = []): Holding
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
function ucFrom008CandidateTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 1000,
        'current_price' => 1300,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 30000,
        'unrealized_gain_rate' => 30.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom008CandidateTestFundamental(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'equity_ratio' => 45.0,
        'roe' => 12.0,
        'fetched_at' => now(),
    ], $attributes));
}

function ucFrom008CandidateTestSector(string $name): SectorClassification
{
    return SectorClassification::query()->firstOrCreate(['name' => $name]);
}

/**
 * Fetch the new-candidate list as an authenticated user.
 */
function ucFrom008CandidateTestFetch(TestCase $test, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->getJson('/new-candidates');
}

/**
 * @return array<string, mixed>|null
 */
function ucFrom008CandidateTestFindRow(TestResponse $response, string $symbolCode): ?array
{
    $rows = $response->json('data') ?? [];

    foreach ($rows as $row) {
        if (($row['symbol_code'] ?? null) === $symbolCode) {
            return $row;
        }
    }

    return null;
}

describe('UC-008: 新規投資候補レコメンド（軽量版）候補一覧', function () {
    describe('正常系', function () {
        test('登録済みテーマに合致し財務健全性フィルタを満たす未保有銘柄が候補として一覧に含まれ、matched_theme/fundamental_summary/suggested_amount/nisa_recommendedが返る', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            [, $snapshot] = ucFrom008CandidateTestImportBatch();

            // Existing portfolio: 1 held stock + 1 held mutual fund, used to
            // compute the portfolio total for suggested_amount. The mutual
            // fund's current_price is a base price per 10,000 units, so its
            // evaluation value must be quantity * current_price / 10000, NOT
            // quantity * current_price as-is (see file header note).
            $heldStock = ucFrom008CandidateTestHolding([
                'symbol_code' => '9999',
                'market' => 'jp',
                'instrument_type' => 'stock',
                'symbol_name' => '既存保有株',
            ]);
            ucFrom008CandidateTestHoldingSnapshot($snapshot, $heldStock, [
                'quantity' => 100,
                'current_price' => 2000.00, // stock evaluation value = 100 * 2000 = 200,000
            ]);

            $heldFund = ucFrom008CandidateTestHolding([
                'symbol_code' => 'eMAXIS Slim 全世界株式',
                'market' => 'mutual_fund',
                'instrument_type' => 'mutual_fund',
                'symbol_name' => 'eMAXIS Slim 全世界株式',
            ]);
            ucFrom008CandidateTestHoldingSnapshot($snapshot, $heldFund, [
                'quantity' => 10000,
                'current_price' => 15000.00, // fund evaluation value = 10000 * 15000 / 10000 = 15,000
            ]);
            // Total portfolio evaluation value = 200,000 + 15,000 = 215,000
            // suggested_amount (1%, confirmed at Gate4) = 2,150

            $sector = ucFrom008CandidateTestSector('AI半導体');
            $candidate = ucFrom008CandidateTestHolding([
                'symbol_code' => '6920',
                'market' => 'jp',
                'instrument_type' => 'stock',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom008CandidateTestFundamental($candidate, [
                'equity_ratio' => 45.0,
                'roe' => 12.0,
            ]);

            $response = ucFrom008CandidateTestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom008CandidateTestFindRow($response, '6920');
            expect($row)->not->toBeNull();
            expect($row['symbol_name'])->toBe('レーザーテック');
            expect($row['matched_theme'])->toBe('AI半導体');

            expect($row['fundamental_summary'])->toBeString();
            expect($row['fundamental_summary'])->toContain('45');
            expect($row['fundamental_summary'])->toContain('12');

            // suggested_amount = 215,000 * 1% = 2,150 (mutual fund unit
            // correction applied to the portfolio total; confirmed at Gate4).
            expect((float) $row['suggested_amount'])->toEqualWithDelta(2150.0, 1.0);

            // 45% equity ratio / 12% ROE satisfy the base 40%/10% filter but
            // not the higher NISA threshold (50%/15%, confirmed at Gate4).
            expect($row['nisa_recommended'])->toBeFalse();
            expect((string) $row['nisa_recommended_reason'])->toBe('');
        });

        test('自己資本比率50%以上・ROE15%以上の候補はnisa_recommendedがtrueになり、nisa_recommended_reasonに理由が入る', function () {
            WatchedTheme::create(['name' => '国策関連']);

            [, $snapshot] = ucFrom008CandidateTestImportBatch();
            $heldStock = ucFrom008CandidateTestHolding(['symbol_code' => '9999', 'symbol_name' => '既存保有株']);
            ucFrom008CandidateTestHoldingSnapshot($snapshot, $heldStock, ['quantity' => 100, 'current_price' => 1000.00]);

            $sector = ucFrom008CandidateTestSector('国策関連');
            $candidate = ucFrom008CandidateTestHolding([
                'symbol_code' => '8035',
                'market' => 'jp',
                'instrument_type' => 'stock',
                'symbol_name' => '東京エレクトロン',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom008CandidateTestFundamental($candidate, [
                'equity_ratio' => 55.0,
                'roe' => 16.0,
            ]);

            $response = ucFrom008CandidateTestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom008CandidateTestFindRow($response, '8035');
            expect($row)->not->toBeNull();
            expect($row['nisa_recommended'])->toBeTrue();
            expect(trim((string) $row['nisa_recommended_reason']))->not->toBe('');
        });

        test('複数snapshotが存在する場合、suggested_amountの算出には最新snapshotの保有評価額合計のみを用いる', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            // Same Holding (master record) held across two snapshots in
            // time (holdings are unique per symbol_code+market; only
            // holding_snapshots accumulate history — see
            // docs/architecture/data-model.md).
            $held = ucFrom008CandidateTestHolding(['symbol_code' => '9999', 'symbol_name' => '既存保有株']);

            [, $oldSnapshot] = ucFrom008CandidateTestImportBatch(now()->subWeeks(2));
            // Old snapshot has a much larger evaluation value; must NOT be used.
            ucFrom008CandidateTestHoldingSnapshot($oldSnapshot, $held, ['quantity' => 100, 'current_price' => 100000.00]);

            [, $newSnapshot] = ucFrom008CandidateTestImportBatch(now());
            ucFrom008CandidateTestHoldingSnapshot($newSnapshot, $held, ['quantity' => 100, 'current_price' => 1000.00]);
            // New (latest) portfolio total = 100 * 1000 = 100,000 -> suggested_amount = 1,000

            $sector = ucFrom008CandidateTestSector('AI半導体');
            $candidate = ucFrom008CandidateTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom008CandidateTestFundamental($candidate, ['equity_ratio' => 45.0, 'roe' => 12.0]);

            $response = ucFrom008CandidateTestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom008CandidateTestFindRow($response, '6920');
            expect($row)->not->toBeNull();
            expect((float) $row['suggested_amount'])->toEqualWithDelta(1000.0, 1.0);
        });
    });

    describe('除外条件', function () {
        test('既に保有している銘柄は、テーマ合致・財務健全性フィルタを満たしていても候補一覧から除外される', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            [, $snapshot] = ucFrom008CandidateTestImportBatch();
            $sector = ucFrom008CandidateTestSector('AI半導体');

            $held = ucFrom008CandidateTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom008CandidateTestHoldingSnapshot($snapshot, $held, ['quantity' => 100, 'current_price' => 1000.00]);
            ucFrom008CandidateTestFundamental($held, ['equity_ratio' => 45.0, 'roe' => 12.0]);

            $response = ucFrom008CandidateTestFetch($this);

            $response->assertSuccessful();
            expect(ucFrom008CandidateTestFindRow($response, '6920'))->toBeNull();
        });

        test('ETF・投資信託は、テーマ合致・財務健全性フィルタを満たしていても候補一覧から除外される', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            [, $snapshot] = ucFrom008CandidateTestImportBatch();
            $heldStock = ucFrom008CandidateTestHolding(['symbol_code' => '9999', 'symbol_name' => '既存保有株']);
            ucFrom008CandidateTestHoldingSnapshot($snapshot, $heldStock, ['quantity' => 100, 'current_price' => 1000.00]);

            $sector = ucFrom008CandidateTestSector('AI半導体');

            $etf = ucFrom008CandidateTestHolding([
                'symbol_code' => 'SEMI',
                'market' => 'us',
                'instrument_type' => 'etf',
                'symbol_name' => 'Semiconductor ETF',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom008CandidateTestFundamental($etf, ['equity_ratio' => 60.0, 'roe' => 20.0]);

            $fund = ucFrom008CandidateTestHolding([
                'symbol_code' => 'AI半導体ファンド',
                'market' => 'mutual_fund',
                'instrument_type' => 'mutual_fund',
                'symbol_name' => 'AI半導体ファンド',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom008CandidateTestFundamental($fund, ['equity_ratio' => 60.0, 'roe' => 20.0]);

            $response = ucFrom008CandidateTestFetch($this);

            $response->assertSuccessful();
            expect(ucFrom008CandidateTestFindRow($response, 'SEMI'))->toBeNull();
            expect(ucFrom008CandidateTestFindRow($response, 'AI半導体ファンド'))->toBeNull();
        });

        test('財務健全性フィルタ（自己資本比率40%以上・ROE10%以上）を満たさない銘柄は候補一覧から除外される', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            [, $snapshot] = ucFrom008CandidateTestImportBatch();
            $heldStock = ucFrom008CandidateTestHolding(['symbol_code' => '9999', 'symbol_name' => '既存保有株']);
            ucFrom008CandidateTestHoldingSnapshot($snapshot, $heldStock, ['quantity' => 100, 'current_price' => 1000.00]);

            $sector = ucFrom008CandidateTestSector('AI半導体');
            $weak = ucFrom008CandidateTestHolding([
                'symbol_code' => '1234',
                'symbol_name' => '財務基準未達株',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom008CandidateTestFundamental($weak, ['equity_ratio' => 30.0, 'roe' => 5.0]);

            $response = ucFrom008CandidateTestFetch($this);

            $response->assertSuccessful();
            expect(ucFrom008CandidateTestFindRow($response, '1234'))->toBeNull();
        });

        test('セクターが登録済みテーマ名と一致しない銘柄は候補一覧から除外される', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            [, $snapshot] = ucFrom008CandidateTestImportBatch();
            $heldStock = ucFrom008CandidateTestHolding(['symbol_code' => '9999', 'symbol_name' => '既存保有株']);
            ucFrom008CandidateTestHoldingSnapshot($snapshot, $heldStock, ['quantity' => 100, 'current_price' => 1000.00]);

            $otherSector = ucFrom008CandidateTestSector('自動車');
            $unmatched = ucFrom008CandidateTestHolding([
                'symbol_code' => '7201',
                'symbol_name' => '日産自動車',
                'sector_classification_id' => $otherSector->id,
            ]);
            ucFrom008CandidateTestFundamental($unmatched, ['equity_ratio' => 45.0, 'roe' => 12.0]);

            $response = ucFrom008CandidateTestFetch($this);

            $response->assertSuccessful();
            expect(ucFrom008CandidateTestFindRow($response, '7201'))->toBeNull();
        });
    });

    describe('空状態', function () {
        test('注目テーマが1件も登録されていない場合はdataが空配列になる', function () {
            [, $snapshot] = ucFrom008CandidateTestImportBatch();
            $heldStock = ucFrom008CandidateTestHolding(['symbol_code' => '9999', 'symbol_name' => '既存保有株']);
            ucFrom008CandidateTestHoldingSnapshot($snapshot, $heldStock, ['quantity' => 100, 'current_price' => 1000.00]);

            // Even though a qualifying unheld holding exists, no theme is
            // registered at all, so no matching can occur.
            $sector = ucFrom008CandidateTestSector('AI半導体');
            $wouldQualify = ucFrom008CandidateTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $sector->id,
            ]);
            ucFrom008CandidateTestFundamental($wouldQualify, ['equity_ratio' => 45.0, 'roe' => 12.0]);

            $response = ucFrom008CandidateTestFetch($this);

            $response->assertSuccessful();
            expect($response->json('data'))->toBe([]);
        });

        test('テーマは登録されているが条件を満たす候補が1件もない場合はdataが空配列になる', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            [, $snapshot] = ucFrom008CandidateTestImportBatch();
            $heldStock = ucFrom008CandidateTestHolding(['symbol_code' => '9999', 'symbol_name' => '既存保有株']);
            ucFrom008CandidateTestHoldingSnapshot($snapshot, $heldStock, ['quantity' => 100, 'current_price' => 1000.00]);

            // No holding matches the registered theme's sector at all.
            $otherSector = ucFrom008CandidateTestSector('自動車');
            $unmatched = ucFrom008CandidateTestHolding([
                'symbol_code' => '7201',
                'symbol_name' => '日産自動車',
                'sector_classification_id' => $otherSector->id,
            ]);
            ucFrom008CandidateTestFundamental($unmatched, ['equity_ratio' => 45.0, 'roe' => 12.0]);

            $response = ucFrom008CandidateTestFetch($this);

            $response->assertSuccessful();
            expect($response->json('data'))->toBe([]);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは新規投資候補一覧を取得できない', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            $response = $this->getJson('/new-candidates');

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web
            // guard) or a 401/403 (API-style guard). Exact status is an
            // implementation choice left to the Green phase (same convention
            // as UC-001〜UC-004/UC-008テーマ登録).
            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
