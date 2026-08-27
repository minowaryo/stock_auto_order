<?php

namespace Tests\Feature;

use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WatchedTheme;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-005: セクター配分ダッシュボード — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-005 基本フロー・出力表・業務ルール・
|     エラーケース)
|   - docs/architecture/data-model.md (holdings / holding_snapshots /
|     holding_snapshot_accounts / snapshots / sector_classifications /
|     fundamental_indicators / watched_themes、「保留・確定が必要な初期パラ
|     メータ値」表のセクター配分判定閾値〔40%未満=健全、40〜70%=やや偏り、
|     70%以上=偏り警告〕・目標配分率〔70%〕)
|   - C:\Users\minow\.claude\plans\stock_auto_order-uc005-implementation-phase.md
|     (Cycle 3: SectorAllocationCalculator サービス + ShowSectorDashboardAction
|     + GET /sector-dashboard)
|   - app/Services/Candidate/NewCandidateFinder.php (UC-008, 既にGreen。
|     rebalance_candidatesの抽出・フィールドはこのサービスの出力
|     〔matched_theme/fundamental_summary/suggested_amount/nisa_recommended/
|     nisa_recommended_reason〕をリマップしたもの。portfolioEvaluationTotal()
|     の投資信託単位補正〔quantity×current_price÷10000〕と同じ計算方法を
|     セクター集計にも用いる)
|   - app/Actions/Signal/ShowSignalListAction.php (UC-004, 既にGreen。
|     holding_snapshot_accounts経由の課税口座〔specific/general〕判定・
|     内訳なし時の全量課税フォールバックパターン)
|
| There is currently no Controller, Route, or Action for
| `GET /sector-dashboard` at all, so every test below is expected to fail
| with a 404 (route not found) rather than an assertion failure — same
| convention as UC004SignalListTest.php / UC008NewCandidateListTest.php (Red
| state caused purely by "no route yet", not by missing models/tables).
|
| Assumptions made while writing these tests (NOT yet confirmed by an
| implementation — flag during Gate 4 review, per the implementation plan's
| own "Gate4で最終確認" markers):
|   - Endpoint: `GET /sector-dashboard`, `auth` middleware, same single-user
|     "web" session guard convention as UC-001〜UC-004/UC-008.
|   - Success response body shape: `{"data": {"sectors": [...],
|     "rebalance_candidates": [...]}}` (nested structure per the
|     implementation plan's Gate4 note — NOT the flat single-list shape used
|     by UC-002〜UC-004/UC-008. This nested shape itself is flagged for
|     Gate4 confirmation by the plan).
|   - `sectors` row shape: `{sector_name, allocation_rate, allocation_status,
|     is_overweight, suggested_sell_amount, suggested_sell_quantity}`.
|     `suggested_sell_amount`/`suggested_sell_quantity` are `null` for
|     non-`偏り警告` rows (only populated for the overweight row).
|   - `rebalance_candidates` row shape: `{symbol_code, symbol_name,
|     sector_name, reason, suggested_purchase_amount, nisa_recommended}`
|     (NewCandidateFinder::find()の`matched_theme`→`sector_name`,
|     `fundamental_summary`→`reason`, `suggested_amount`→
|     `suggested_purchase_amount`のリマップ。`nisa_recommended_reason`は
|     use-cases.md出力表に記載がないためレスポンスに含めるかは実装に委ね、
|     本ファイルでは検証しない)。
|   - **`suggested_sell_quantity`の按分方法はGate4で最終確認が必要な叩き台**:
|     計画書に基づき「セクター内の課税口座保有銘柄の加重平均現在値で
|     `suggested_sell_amount`を除算する」ことを仮定する。本ファイルの該当
|     テストは単一銘柄のみを対象セクターに含めることで、加重平均が単純に
|     その銘柄の`current_price`と一致するケースに限定し、複数銘柄への按分
|     方式の細部（丸め等）には踏み込まない。数量は`toEqualWithDelta`で
|     ±1程度の丸め誤差を許容する。
|   - `allocation_rate`はセクターごとの評価額合計を全保有評価額合計で除した
|     百分率（0〜100のdecimal）とする。投資信託は`quantity×current_price÷
|     10000`で単位補正する（NewCandidateFinder::portfolioEvaluationTotal()
|     と同じ計算方法）。
|   - 集計対象は全`instrument_type`（stock/etf/mutual_fund）であり、UC-008/
|     UC-009のような`instrument_type='stock'`限定は行わない。
|   - `sector_classification_id`が`null`の銘柄は`sector_name="未分類"`として
|     1つのセクター行に集約される。
|   - decimal-cast numeric fields are compared as floats regardless of
|     whether JSON encodes them as string or number (same convention as
|     UC002/UC003/UC004/UC008).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom005SectorTestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom005SectorTestHolding(array $attributes = []): Holding
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
function ucFrom005SectorTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
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
function ucFrom005SectorTestAccount(HoldingSnapshot $holdingSnapshot, array $attributes = []): HoldingSnapshotAccount
{
    return HoldingSnapshotAccount::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'account_type' => 'specific',
        'quantity' => 100,
        'average_cost' => 1000.00,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom005SectorTestFundamental(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'equity_ratio' => 45.0,
        'roe' => 12.0,
        // CR (2026-08-27, CHG-0005): NewCandidateFinder（UC-005の
        // rebalance_candidatesが流用するサービス）の財務健全性フィルタに
        // 成長率条件が追加されるため、既存の「候補として出る」テストが
        // 無改変でGreenのまま通るよう、デフォルトにプラスの売上高成長率を
        // 設定する（UC008NewCandidateListTest.phpの
        // ucFrom008CandidateTestFundamental()と同じフィクスチャ調整）。
        'revenue_growth' => 8.0,
        'fetched_at' => now(),
    ], $attributes));
}

function ucFrom005SectorTestSector(string $name): SectorClassification
{
    return SectorClassification::query()->firstOrCreate(['name' => $name]);
}

/**
 * Fetch the sector dashboard as an authenticated user.
 */
function ucFrom005SectorTestFetch(TestCase $test, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->getJson('/api/sector-dashboard');
}

/**
 * @return array<string, mixed>|null
 */
function ucFrom005SectorTestFindSector(TestResponse $response, string $sectorName): ?array
{
    $rows = $response->json('data.sectors') ?? [];

    foreach ($rows as $row) {
        if (($row['sector_name'] ?? null) === $sectorName) {
            return $row;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function ucFrom005SectorTestFindCandidate(TestResponse $response, string $symbolCode): ?array
{
    $rows = $response->json('data.rebalance_candidates') ?? [];

    foreach ($rows as $row) {
        if (($row['symbol_code'] ?? null) === $symbolCode) {
            return $row;
        }
    }

    return null;
}

describe('UC-005: セクター配分ダッシュボード', function () {
    describe('セクター配分の集計（allocation_rate / allocation_status）', function () {
        test('複数セクター（未分類・投資信託の単位補正込み）にまたがる保有からallocation_rate・allocation_statusが正しく算出される', function () {
            [, $snapshot] = ucFrom005SectorTestImportBatch();

            // 半導体セクター: 100株×4,500円 = 450,000円 (45% -> やや偏り)
            $semiconductorSector = ucFrom005SectorTestSector('半導体');
            $semiconductorHolding = ucFrom005SectorTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            ucFrom005SectorTestHoldingSnapshot($snapshot, $semiconductorHolding, [
                'quantity' => 100,
                'current_price' => 4500.00,
            ]);

            // 自動車セクター: 100株×3,000円 = 300,000円 (30% -> 健全)
            $autoSector = ucFrom005SectorTestSector('自動車');
            $autoHolding = ucFrom005SectorTestHolding([
                'symbol_code' => '7203',
                'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            ucFrom005SectorTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 100,
                'current_price' => 3000.00,
            ]);

            // 未分類（投資信託・単位補正）: 1,000,000口×2,500円 ÷ 10,000 = 250,000円 (25% -> 健全)
            $fundHolding = ucFrom005SectorTestHolding([
                'symbol_code' => 'eMAXIS Slim 全世界株式',
                'market' => 'mutual_fund',
                'instrument_type' => 'mutual_fund',
                'symbol_name' => 'eMAXIS Slim 全世界株式',
                'sector_classification_id' => null,
            ]);
            ucFrom005SectorTestHoldingSnapshot($snapshot, $fundHolding, [
                'quantity' => 1000000,
                'current_price' => 2500.00,
            ]);

            // Total = 450,000 + 300,000 + 250,000 = 1,000,000

            $response = ucFrom005SectorTestFetch($this);

            $response->assertSuccessful();

            $semiconductorRow = ucFrom005SectorTestFindSector($response, '半導体');
            expect($semiconductorRow)->not->toBeNull();
            expect((float) $semiconductorRow['allocation_rate'])->toEqualWithDelta(45.0, 0.5);
            expect($semiconductorRow['allocation_status'])->toBe('やや偏り');
            expect($semiconductorRow['is_overweight'])->toBeFalse();
            expect($semiconductorRow['suggested_sell_amount'])->toBeNull();
            expect($semiconductorRow['suggested_sell_quantity'])->toBeNull();

            $autoRow = ucFrom005SectorTestFindSector($response, '自動車');
            expect($autoRow)->not->toBeNull();
            expect((float) $autoRow['allocation_rate'])->toEqualWithDelta(30.0, 0.5);
            expect($autoRow['allocation_status'])->toBe('健全');
            expect($autoRow['is_overweight'])->toBeFalse();

            // 投資信託の単位補正 (quantity * current_price / 10000) が
            // 適用されていなければ 250,000 ではなく 2,500億円相当になり、
            // allocation_rateが約100%近くまで歪む。25%であることを検証する
            // ことで単位補正の適用有無を判定する。
            $unclassifiedRow = ucFrom005SectorTestFindSector($response, '未分類');
            expect($unclassifiedRow)->not->toBeNull();
            expect((float) $unclassifiedRow['allocation_rate'])->toEqualWithDelta(25.0, 0.5);
            expect($unclassifiedRow['allocation_status'])->toBe('健全');
        });
    });

    describe('偏り警告セクターのsuggested_sell_amount / suggested_sell_quantity', function () {
        test('偏り警告セクターのsuggested_sell_amountは、セクター単体ではなく保有評価額合計全体を用いて算出される', function () {
            [, $snapshot] = ucFrom005SectorTestImportBatch();

            // 半導体セクター: 80株×10,000円 = 800,000円 (80% -> 偏り警告)。
            // holding_snapshot_accountsの内訳を作らず、UC-004と同じ
            // 「内訳なし = 全量課税口座扱い」のフォールバックを利用する。
            $semiconductorSector = ucFrom005SectorTestSector('半導体');
            $semiconductorHolding = ucFrom005SectorTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            ucFrom005SectorTestHoldingSnapshot($snapshot, $semiconductorHolding, [
                'quantity' => 80,
                'current_price' => 10000.00,
            ]);

            // 自動車セクター: 200株×1,000円 = 200,000円 (20% -> 健全)
            $autoSector = ucFrom005SectorTestSector('自動車');
            $autoHolding = ucFrom005SectorTestHolding([
                'symbol_code' => '7203',
                'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            ucFrom005SectorTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 200,
                'current_price' => 1000.00,
            ]);

            // Total = 800,000 + 200,000 = 1,000,000
            // suggested_sell_amount = (80% - 70%) / 100 * 1,000,000 = 100,000
            // suggested_sell_quantity = 100,000 / 10,000(加重平均現在値) = 10

            $response = ucFrom005SectorTestFetch($this);

            $response->assertSuccessful();

            $semiconductorRow = ucFrom005SectorTestFindSector($response, '半導体');
            expect($semiconductorRow)->not->toBeNull();
            expect((float) $semiconductorRow['allocation_rate'])->toEqualWithDelta(80.0, 0.5);
            expect($semiconductorRow['allocation_status'])->toBe('偏り警告');
            expect($semiconductorRow['is_overweight'])->toBeTrue();

            expect((float) $semiconductorRow['suggested_sell_amount'])->toEqualWithDelta(100000.0, 1.0);
            expect((float) $semiconductorRow['suggested_sell_quantity'])->toEqualWithDelta(10.0, 1.0);

            // 偏り警告でないセクターにはsuggested_sell_amount/quantityが
            // 付与されない。
            $autoRow = ucFrom005SectorTestFindSector($response, '自動車');
            expect($autoRow)->not->toBeNull();
            expect($autoRow['suggested_sell_amount'])->toBeNull();
            expect($autoRow['suggested_sell_quantity'])->toBeNull();
        });

        test('偏り警告セクターの保有が全てNISA区分（課税口座分が0）の場合、suggested_sell_amount・suggested_sell_quantityは0になる', function () {
            [, $snapshot] = ucFrom005SectorTestImportBatch();

            // ヘルスケアセクター: 100株×7,500円 = 750,000円 (75% -> 偏り警告)。
            // 内訳は全てNISA区分（成長投資枠+つみたて投資枠）で課税口座分は0。
            $healthcareSector = ucFrom005SectorTestSector('ヘルスケア');
            $healthcareHolding = ucFrom005SectorTestHolding([
                'symbol_code' => '4568',
                'symbol_name' => '第一三共',
                'sector_classification_id' => $healthcareSector->id,
            ]);
            $healthcareSnapshot = ucFrom005SectorTestHoldingSnapshot($snapshot, $healthcareHolding, [
                'quantity' => 100,
                'current_price' => 7500.00,
            ]);
            ucFrom005SectorTestAccount($healthcareSnapshot, ['account_type' => 'nisa_growth', 'quantity' => 60, 'average_cost' => 5000.00]);
            ucFrom005SectorTestAccount($healthcareSnapshot, ['account_type' => 'nisa_tsumitate', 'quantity' => 40, 'average_cost' => 5000.00]);

            // 自動車セクター: 250株×1,000円 = 250,000円 (25% -> 健全)
            $autoSector = ucFrom005SectorTestSector('自動車');
            $autoHolding = ucFrom005SectorTestHolding([
                'symbol_code' => '7203',
                'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            ucFrom005SectorTestHoldingSnapshot($snapshot, $autoHolding, [
                'quantity' => 250,
                'current_price' => 1000.00,
            ]);

            // Total = 750,000 + 250,000 = 1,000,000 -> ヘルスケア75%は偏り警告
            // だが課税口座分が0のため売却提案は0円・0株とする。

            $response = ucFrom005SectorTestFetch($this);

            $response->assertSuccessful();

            $healthcareRow = ucFrom005SectorTestFindSector($response, 'ヘルスケア');
            expect($healthcareRow)->not->toBeNull();
            expect($healthcareRow['allocation_status'])->toBe('偏り警告');
            expect($healthcareRow['is_overweight'])->toBeTrue();

            expect((float) $healthcareRow['suggested_sell_amount'])->toEqualWithDelta(0.0, 0.01);
            expect((float) $healthcareRow['suggested_sell_quantity'])->toEqualWithDelta(0.0, 0.01);
        });
    });

    describe('rebalance_candidates（NewCandidateFinderの流用・リマップ）', function () {
        test('rebalance_candidatesはNewCandidateFinderと同じ抽出条件で得られ、sector_name/reason/suggested_purchase_amountにリマップされる', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            [, $snapshot] = ucFrom005SectorTestImportBatch();

            // 既存ポートフォリオ（候補抽出には無関係なセクターのみ、偏り無し）
            $materialSector = ucFrom005SectorTestSector('素材');
            $heldStock = ucFrom005SectorTestHolding([
                'symbol_code' => '9999',
                'symbol_name' => '既存保有株',
                'sector_classification_id' => $materialSector->id,
            ]);
            ucFrom005SectorTestHoldingSnapshot($snapshot, $heldStock, [
                'quantity' => 100,
                'current_price' => 5000.00, // 500,000円
            ]);

            $heldFund = ucFrom005SectorTestHolding([
                'symbol_code' => 'eMAXIS Slim 全世界株式',
                'market' => 'mutual_fund',
                'instrument_type' => 'mutual_fund',
                'symbol_name' => 'eMAXIS Slim 全世界株式',
                'sector_classification_id' => null,
            ]);
            ucFrom005SectorTestHoldingSnapshot($snapshot, $heldFund, [
                'quantity' => 200000,
                'current_price' => 10000.00, // 200,000口 * 10,000 / 10000 = 200,000円
            ]);
            // Total portfolio evaluation value = 500,000 + 200,000 = 700,000
            // suggested_purchase_amount (NewCandidateFinderと同じ1%) = 7,000

            $candidateSector = ucFrom005SectorTestSector('AI半導体');
            $candidate = ucFrom005SectorTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $candidateSector->id,
            ]);
            ucFrom005SectorTestFundamental($candidate, ['equity_ratio' => 45.0, 'roe' => 12.0]);

            $response = ucFrom005SectorTestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom005SectorTestFindCandidate($response, '6920');
            expect($row)->not->toBeNull();
            expect($row['symbol_name'])->toBe('レーザーテック');
            expect($row['sector_name'])->toBe('AI半導体');

            expect($row['reason'])->toBeString();
            expect($row['reason'])->toContain('45');
            expect($row['reason'])->toContain('12');

            expect((float) $row['suggested_purchase_amount'])->toEqualWithDelta(7000.0, 1.0);
            expect($row['nisa_recommended'])->toBeFalse();
        });

        test('偏り警告セクターに属する候補銘柄はrebalance_candidatesから除外される', function () {
            WatchedTheme::create(['name' => '半導体']);

            [, $snapshot] = ucFrom005SectorTestImportBatch();

            // 半導体セクターを単独保有で100%（偏り警告）にする。
            $semiconductorSector = ucFrom005SectorTestSector('半導体');
            $heldStock = ucFrom005SectorTestHolding([
                'symbol_code' => '9999',
                'symbol_name' => '既存保有株',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            ucFrom005SectorTestHoldingSnapshot($snapshot, $heldStock, [
                'quantity' => 100,
                'current_price' => 1000.00,
            ]);
            // Total = 100,000, 半導体 = 100,000/100,000 = 100% -> 偏り警告

            // NewCandidateFinder単体では抽出条件（テーマ合致・未保有・
            // 財務健全性フィルタ）を満たすはずの候補だが、所属セクター
            // （半導体）が現在偏り警告状態のため除外されなければならない。
            $candidate = ucFrom005SectorTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            ucFrom005SectorTestFundamental($candidate, ['equity_ratio' => 45.0, 'roe' => 12.0]);

            $response = ucFrom005SectorTestFetch($this);

            $response->assertSuccessful();

            $semiconductorRow = ucFrom005SectorTestFindSector($response, '半導体');
            expect($semiconductorRow)->not->toBeNull();
            expect($semiconductorRow['allocation_status'])->toBe('偏り警告');

            expect(ucFrom005SectorTestFindCandidate($response, '6920'))->toBeNull();
        });
    });

    describe('空状態', function () {
        test('保有銘柄が1件も存在しない場合、sectorsもrebalance_candidatesも空配列になる', function () {
            // WatchedThemeを登録しても保有銘柄が皆無であれば候補自体が
            // 存在しないため、rebalance_candidatesも空になる。
            WatchedTheme::create(['name' => 'AI半導体']);

            $response = ucFrom005SectorTestFetch($this);

            $response->assertSuccessful();
            expect($response->json('data.sectors'))->toBe([]);
            expect($response->json('data.rebalance_candidates'))->toBe([]);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーはセクター配分ダッシュボードを取得できない', function () {
            [, $snapshot] = ucFrom005SectorTestImportBatch();
            $holding = ucFrom005SectorTestHolding();
            ucFrom005SectorTestHoldingSnapshot($snapshot, $holding);

            $response = $this->getJson('/api/sector-dashboard');

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web
            // guard) or a 401/403 (API-style guard). Exact status is an
            // implementation choice left to the Green phase (same convention
            // as UC-001〜UC-004/UC-008).
            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
