<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\MarketIndicatorSnapshot;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-007: 市場全体指標表示 — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-007 基本フロー・入力/出力表・業務ルール・
|     エラーケース、lines 384-422)
|   - docs/architecture/data-model.md (market_indicator_snapshots テーブル
|     定義: snapshot_id/index_name(enum: nikkei225/sp500/us10y/vix/usdjpy)/
|     value/change_rate/ma_deviation, unique(snapshot_id, index_name);
|     snapshots テーブルの「直近」＝snapshotted_at最大の1件、という週次
|     スナップショット運用の全体規約)
|   - app/Actions/Analysis/FetchExternalMarketDataAction.php (既にGreen。
|     saveMarketIndicatorSnapshot()は現状 'nikkei225'/'sp500' の2指標のみを
|     書き込み、'us10y'/'vix'/'usdjpy' の取得・保存ロジックはこのAction内に
|     一切存在しない — Grep/Read で確認済み)
|   - app/Http/Controllers/SectorDashboardController.php +
|     app/Actions/Sector/ShowSectorDashboardAction.php (UC-005、直近の
|     no-input GET エンドポイントの precedent: 薄いController + 単一Action +
|     `{"data": ...}` レスポンス封筒 + `auth` ミドルウェアのみ)
|
| Scope note (already confirmed with the user via AskUserQuestion before this
| Red phase — NOT a Gate4-要確認 flag, just documented scope for this cycle):
|   'us10y' / 'vix' / 'usdjpy' は本サイクルでは常にnull値のプレースホルダ
|   として返す。これら3指標を実際に外部データソースから取得・保存する
|   ロジックはこのコードベースのどこにも存在しない（確認済み）ため、本UCの
|   実装対象外（別サイクルの将来対応としてタスク上で追跡される）。
|   use-cases.md エラーケース「一部の指標の外部データ取得に失敗→該当指標
|   のみ「取得不可」と表示し、他の指標・画面全体の表示は継続する」を、
|   APIレベルでは「該当index_nameのvalue/change_rate/ma_deviationが全て
|   null」として表現する設計とする（「取得不可」ラベル自体のレンダリングは
|   未実装のフロントエンドの責務）。
|
| There is currently no Route, Controller, or Action for
| `GET /market-indicators` at all (confirmed via Grep against
| routes/web.php), so every test below is expected to fail with a 404
| (route not found) rather than an assertion failure — same convention as
| tests/Feature/UC005SectorDashboardTest.php (Red state caused purely by
| "no route yet", not by missing models/tables — market_indicator_snapshots
| itself already exists and is Gate3-approved/Gate4-confirmed via
| FetchExternalMarketDataActionTest.php).
|
| Assumptions made while writing these tests (NOT yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Endpoint: `GET /market-indicators`, `auth` middleware, same
|     single-user "web" session guard convention as UC-001〜UC-005/UC-008.
|   - Success response body shape: `{"data": [...]}`, a flat list of exactly
|     5 rows (not a keyed object), always in the fixed order
|     nikkei225/sp500/us10y/vix/usdjpy regardless of which rows exist in the
|     DB for the latest snapshot.
|   - Row shape: `{index_name, value, change_rate, ma_deviation}` (matches
|     use-cases.md's output table exactly — no additional fields such as a
|     human-readable label are asserted here, since UC-007's output table
|     only specifies these 4).
|   - decimal-cast numeric fields (value/change_rate/ma_deviation) are
|     compared as floats regardless of whether JSON encodes them as string
|     or number (same convention as UC002/UC003/UC004/UC005/UC008).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom007MarketTestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom007MarketTestIndicator(Snapshot $snapshot, string $indexName, array $attributes = []): MarketIndicatorSnapshot
{
    return MarketIndicatorSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'index_name' => $indexName,
        'value' => 30000.1234,
        'change_rate' => 1.2345,
        'ma_deviation' => 2.3456,
    ], $attributes));
}

/**
 * Fetch the market-wide indicator widget as an authenticated user.
 */
function ucFrom007MarketTestFetch(TestCase $test, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->getJson('/api/market-indicators');
}

/**
 * @return array<string, mixed>|null
 */
function ucFrom007MarketTestFindRow(TestResponse $response, string $indexName): ?array
{
    $rows = $response->json('data') ?? [];

    foreach ($rows as $row) {
        if (($row['index_name'] ?? null) === $indexName) {
            return $row;
        }
    }

    return null;
}

describe('UC-007: 市場全体指標表示', function () {
    describe('正常系: 直近スナップショットの指標表示', function () {
        test('直近スナップショットにnikkei225/sp500のmarket_indicator_snapshotsが存在する場合、両方のvalue/change_rate/ma_deviationが正しく返る', function () {
            [, $snapshot] = ucFrom007MarketTestImportBatch();

            ucFrom007MarketTestIndicator($snapshot, 'nikkei225', [
                'value' => 39500.5000,
                'change_rate' => 0.8500,
                'ma_deviation' => 3.2000,
            ]);
            ucFrom007MarketTestIndicator($snapshot, 'sp500', [
                'value' => 5600.2500,
                'change_rate' => -0.4200,
                'ma_deviation' => -1.1000,
            ]);

            $response = ucFrom007MarketTestFetch($this);

            $response->assertSuccessful();

            $nikkeiRow = ucFrom007MarketTestFindRow($response, 'nikkei225');
            expect($nikkeiRow)->not->toBeNull();
            expect((float) $nikkeiRow['value'])->toEqualWithDelta(39500.5000, 0.001);
            expect((float) $nikkeiRow['change_rate'])->toEqualWithDelta(0.8500, 0.001);
            expect((float) $nikkeiRow['ma_deviation'])->toEqualWithDelta(3.2000, 0.001);

            $sp500Row = ucFrom007MarketTestFindRow($response, 'sp500');
            expect($sp500Row)->not->toBeNull();
            expect((float) $sp500Row['value'])->toEqualWithDelta(5600.2500, 0.001);
            expect((float) $sp500Row['change_rate'])->toEqualWithDelta(-0.4200, 0.001);
            expect((float) $sp500Row['ma_deviation'])->toEqualWithDelta(-1.1000, 0.001);
        });
    });

    describe('us10y/vix/usdjpyの常時null化（取得ロジック未実装のためのプレースホルダ）', function () {
        test('nikkei225/sp500が存在していても、us10y/vix/usdjpyは常にvalue/change_rate/ma_deviationがnullで返る', function () {
            [, $snapshot] = ucFrom007MarketTestImportBatch();

            ucFrom007MarketTestIndicator($snapshot, 'nikkei225');
            ucFrom007MarketTestIndicator($snapshot, 'sp500');

            $response = ucFrom007MarketTestFetch($this);

            $response->assertSuccessful();

            foreach (['us10y', 'vix', 'usdjpy'] as $indexName) {
                $row = ucFrom007MarketTestFindRow($response, $indexName);
                expect($row)->not->toBeNull();
                expect($row['value'])->toBeNull();
                expect($row['change_rate'])->toBeNull();
                expect($row['ma_deviation'])->toBeNull();
            }
        });
    });

    describe('直近スナップショットの判定（snapshotted_at最大のみ参照）', function () {
        test('複数スナップショットが存在する場合、直近（snapshotted_at最大）のもののみを参照し、古いスナップショットの値を混ぜない', function () {
            [, $oldSnapshot] = ucFrom007MarketTestImportBatch(now()->subWeek());
            ucFrom007MarketTestIndicator($oldSnapshot, 'nikkei225', [
                'value' => 10000.0000,
                'change_rate' => -5.0000,
                'ma_deviation' => -8.0000,
            ]);

            [, $latestSnapshot] = ucFrom007MarketTestImportBatch(now());
            ucFrom007MarketTestIndicator($latestSnapshot, 'nikkei225', [
                'value' => 39500.5000,
                'change_rate' => 0.8500,
                'ma_deviation' => 3.2000,
            ]);

            $response = ucFrom007MarketTestFetch($this);

            $response->assertSuccessful();

            $nikkeiRow = ucFrom007MarketTestFindRow($response, 'nikkei225');
            expect($nikkeiRow)->not->toBeNull();
            expect((float) $nikkeiRow['value'])->toEqualWithDelta(39500.5000, 0.001);
            expect((float) $nikkeiRow['change_rate'])->toEqualWithDelta(0.8500, 0.001);
            expect((float) $nikkeiRow['ma_deviation'])->toEqualWithDelta(3.2000, 0.001);
        });
    });

    describe('空状態', function () {
        test('スナップショットが1件も存在しない場合でも全5指標がnull値で返り、エラーにならない', function () {
            $response = ucFrom007MarketTestFetch($this);

            $response->assertSuccessful();

            $rows = $response->json('data');
            expect($rows)->toHaveCount(5);

            foreach (['nikkei225', 'sp500', 'us10y', 'vix', 'usdjpy'] as $indexName) {
                $row = ucFrom007MarketTestFindRow($response, $indexName);
                expect($row)->not->toBeNull();
                expect($row['value'])->toBeNull();
                expect($row['change_rate'])->toBeNull();
                expect($row['ma_deviation'])->toBeNull();
            }
        });
    });

    describe('レスポンス形状（常に5件・固定順）', function () {
        test('レスポンスは常に5件（nikkei225/sp500/us10y/vix/usdjpy）がこの順で返る', function () {
            [, $snapshot] = ucFrom007MarketTestImportBatch();
            ucFrom007MarketTestIndicator($snapshot, 'sp500');
            ucFrom007MarketTestIndicator($snapshot, 'nikkei225');

            $response = ucFrom007MarketTestFetch($this);

            $response->assertSuccessful();

            $indexNames = collect($response->json('data'))->pluck('index_name')->all();

            expect($indexNames)->toBe(['nikkei225', 'sp500', 'us10y', 'vix', 'usdjpy']);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは市場全体指標を取得できない', function () {
            [, $snapshot] = ucFrom007MarketTestImportBatch();
            ucFrom007MarketTestIndicator($snapshot, 'nikkei225');

            $response = $this->getJson('/api/market-indicators');

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web
            // guard) or a 401/403 (API-style guard). Exact status is an
            // implementation choice left to the Green phase (same convention
            // as UC-001〜UC-005/UC-008).
            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
