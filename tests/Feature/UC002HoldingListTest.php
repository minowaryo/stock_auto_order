<?php

namespace Tests\Feature;

use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-002: Holding list — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-002)
|   - docs/architecture/data-model.md (holdings / holding_snapshots /
|     snapshots / sector_classifications / technical_indicators /
|     fundamental_indicators / signals)
|
| Nothing under app/ exists yet for this feature (no route, no controller,
| no FormRequest for the query params). In addition, the
| sector_classifications / technical_indicators / fundamental_indicators /
| signals tables and their Eloquent models
| (App\Models\SectorClassification / TechnicalIndicator /
| FundamentalIndicator / Signal) do not exist yet at all (no migration).
| Several tests below therefore fail during Arrange (fatal "class not
| found" errors) rather than during Act/Assert — this is an intentional,
| expected Red state for those cases, not a typo.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Endpoint: GET /holdings, authenticated via the standard "web" session
|     guard (single-user app, same pattern as UC-001's POST /csv-import).
|   - Query params follow use-cases.md exactly: `sector` (string) and
|     `signal_only` (boolean, sent as "1"/"0").
|   - Requests use Accept: application/json (via getJson()).
|   - Success response body is assumed to be a Laravel API Resource
|     collection shape: `{"data": [ {id, symbol_code, symbol_name, market,
|     instrument_type, quantity, average_cost, current_price,
|     unrealized_gain_rate, sector, has_signal, rsi, per, revenue_growth,
|     is_newly_detected}, ... ] }` (`id` added 2026-08-23 for the frontend's
|     list -> detail link, stock_auto_order-frontend-implementation-phase.md
|     Phase0). This is the biggest unconfirmed part of
|     the contract — a bare top-level array, or a differently-named
|     wrapper key, would also satisfy use-cases.md's output definition.
|   - "対象外" (N/A) for rsi/per/revenue_growth on ETF/mutual fund rows is
|     represented as JSON null.
|   - "未分類" (unclassified sector) for holdings with
|     sector_classification_id = null is represented as the literal string
|     "未分類" in the `sector` output field (matching data-model.md's
|     wording for sector_classifications and UC-005's equivalent handling).
|   - The `sector` filter matches against the sector_classifications.name
|     value shown in the `sector` output field (including the literal
|     "未分類" per the assumption above, though not explicitly tested here).
|   - decimal-cast numeric fields (quantity/average_cost/current_price/
|     unrealized_gain_rate/rsi/per/revenue_growth) are compared as floats
|     regardless of whether the JSON encodes them as string or number,
|     to avoid over-constraining serialization format.
|
*/

/**
 * Create an import_batches + snapshots pair representing one weekly
 * snapshot generation (UC-001). Returns [ImportBatch, Snapshot].
 *
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom002TestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom002TestHolding(array $attributes = []): Holding
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
function ucFrom002TestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 2000,
        'current_price' => 2500,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 5000,
        'unrealized_gain_rate' => 25.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * sector_classifications does not exist yet (no migration, no model).
 * Referencing \App\Models\SectorClassification here is expected to blow up
 * with a "class not found" fatal error until Gate 4 Green work adds it.
 */
function ucFrom002TestSectorClassification(string $name, ?string $code = null): object
{
    return SectorClassification::create([
        'code' => $code,
        'name' => $name,
    ]);
}

/**
 * technical_indicators does not exist yet (no migration, no model).
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom002TestTechnicalIndicator(Holding $holding, array $attributes = []): object
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 65.5,
        'macd' => null,
        'macd_signal' => null,
        'ma20' => null,
        'ma75' => null,
        'bb_upper' => null,
        'bb_lower' => null,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * fundamental_indicators does not exist yet (no migration, no model).
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom002TestFundamentalIndicator(Holding $holding, array $attributes = []): object
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 15.2,
        'pbr' => null,
        'roe' => null,
        'revenue_growth' => 12.3,
        'operating_income_growth' => null,
        'equity_ratio' => null,
        'dividend_yield' => null,
        'dividend_payout_ratio' => null,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * signals does not exist yet (no migration, no model).
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom002TestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): object
{
    return Signal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72から65に反落',
    ], $attributes));
}

/**
 * Fetch the holding list as an authenticated user.
 *
 * @param  array<string, mixed>  $query
 */
function ucFrom002TestFetch(TestCase $test, array $query = [], ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();
    $url = '/api/holdings'.(empty($query) ? '' : ('?'.http_build_query($query)));

    return $test->actingAs($user)->getJson($url);
}

/**
 * Find a row in the assumed `{"data": [...]}` response body by symbol_code
 * + market (UC-001's identification rule: symbol_code + market is unique).
 *
 * @return array<string, mixed>|null
 */
function ucFrom002TestFindRow(TestResponse $response, string $symbolCode, string $market): ?array
{
    $rows = $response->json('data') ?? [];

    foreach ($rows as $row) {
        if (($row['symbol_code'] ?? null) === $symbolCode && ($row['market'] ?? null) === $market) {
            return $row;
        }
    }

    return null;
}

describe('UC-002: 保有銘柄一覧表示', function () {
    describe('正常系', function () {
        test('保有銘柄一覧を取得できる', function () {
            [, $snapshot] = ucFrom002TestImportBatch();
            $holding = ucFrom002TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            ucFrom002TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 100,
                'average_cost' => 2000.00,
                'current_price' => 2500.00,
                'unrealized_gain_amount' => 50000.00,
                'unrealized_gain_rate' => 25.0,
            ]);

            $response = ucFrom002TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom002TestFindRow($response, '7203', 'jp');
            expect($row)->not->toBeNull();
            // フロントエンド実装（一覧→詳細画面へのリンク生成、GET /holdings/{holding}
            // のroute model binding）に必要なため追加。stock_auto_order-frontend-
            // implementation-phase.md Phase0参照。
            expect($row['id'])->toBe($holding->id);
            expect($row['symbol_name'])->toBe('トヨタ自動車');
            expect($row['instrument_type'])->toBe('stock');
            expect((float) $row['quantity'])->toEqualWithDelta(100.0, 0.01);
            expect((float) $row['average_cost'])->toEqualWithDelta(2000.0, 0.01);
            expect((float) $row['current_price'])->toEqualWithDelta(2500.0, 0.01);
            expect((float) $row['unrealized_gain_rate'])->toEqualWithDelta(25.0, 0.01);
        });

        test('直近の週次スナップショットのみが表示対象となる', function () {
            [, $oldSnapshot] = ucFrom002TestImportBatch(now()->subWeek());
            $holdingA = ucFrom002TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            $holdingB = ucFrom002TestHolding(['symbol_code' => '9984', 'market' => 'jp', 'symbol_name' => 'ソフトバンクグループ']);
            ucFrom002TestHoldingSnapshot($oldSnapshot, $holdingA, ['current_price' => 2000.00]);
            ucFrom002TestHoldingSnapshot($oldSnapshot, $holdingB, ['current_price' => 5000.00]);

            // Second (latest) import: holdingA still held with an updated
            // price, holdingB was sold off and no longer appears.
            [, $latestSnapshot] = ucFrom002TestImportBatch(now());
            ucFrom002TestHoldingSnapshot($latestSnapshot, $holdingA, ['current_price' => 2600.00]);

            $response = ucFrom002TestFetch($this);

            $response->assertSuccessful();

            $rows = $response->json('data');
            expect($rows)->toHaveCount(1);

            $rowA = ucFrom002TestFindRow($response, '7203', 'jp');
            expect($rowA)->not->toBeNull();
            expect((float) $rowA['current_price'])->toEqualWithDelta(2600.0, 0.01);

            $rowB = ucFrom002TestFindRow($response, '9984', 'jp');
            expect($rowB)->toBeNull();
        });

        test('sectorフィルタで絞り込める', function () {
            [, $snapshot] = ucFrom002TestImportBatch();

            $techSector = ucFrom002TestSectorClassification('情報・通信業', '5250');
            $autoSector = ucFrom002TestSectorClassification('輸送用機器', '3750');

            $holdingTech = ucFrom002TestHolding([
                'symbol_code' => '9432', 'market' => 'jp', 'symbol_name' => 'NTT',
                'sector_classification_id' => $techSector->id,
            ]);
            $holdingAuto = ucFrom002TestHolding([
                'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            ucFrom002TestHoldingSnapshot($snapshot, $holdingTech);
            ucFrom002TestHoldingSnapshot($snapshot, $holdingAuto);

            $response = ucFrom002TestFetch($this, ['sector' => '情報・通信業']);

            $response->assertSuccessful();

            $rows = $response->json('data');
            expect($rows)->toHaveCount(1);
            expect($rows[0]['symbol_code'])->toBe('9432');
        });

        test('signal_onlyフィルタでシグナル発生銘柄のみに絞り込める', function () {
            [, $snapshot] = ucFrom002TestImportBatch();

            $holdingWithSignal = ucFrom002TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            $snapshotWithSignal = ucFrom002TestHoldingSnapshot($snapshot, $holdingWithSignal);
            ucFrom002TestSignal($snapshotWithSignal);

            $holdingWithoutSignal = ucFrom002TestHolding(['symbol_code' => '9984', 'market' => 'jp', 'symbol_name' => 'ソフトバンクグループ']);
            ucFrom002TestHoldingSnapshot($snapshot, $holdingWithoutSignal);

            $response = ucFrom002TestFetch($this, ['signal_only' => '1']);

            $response->assertSuccessful();

            $rows = $response->json('data');
            expect($rows)->toHaveCount(1);
            expect($rows[0]['symbol_code'])->toBe('7203');
        });

        test('has_signal・is_newly_detectedが正しく反映される', function () {
            [, $snapshot] = ucFrom002TestImportBatch();

            $flaggedHolding = ucFrom002TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            $flaggedSnapshot = ucFrom002TestHoldingSnapshot($snapshot, $flaggedHolding, ['is_newly_detected' => true]);
            ucFrom002TestSignal($flaggedSnapshot);

            $plainHolding = ucFrom002TestHolding(['symbol_code' => '9984', 'market' => 'jp', 'symbol_name' => 'ソフトバンクグループ']);
            ucFrom002TestHoldingSnapshot($snapshot, $plainHolding, ['is_newly_detected' => false]);

            $response = ucFrom002TestFetch($this);

            $response->assertSuccessful();

            $flaggedRow = ucFrom002TestFindRow($response, '7203', 'jp');
            expect($flaggedRow['has_signal'])->toBeTrue();
            expect($flaggedRow['is_newly_detected'])->toBeTrue();

            $plainRow = ucFrom002TestFindRow($response, '9984', 'jp');
            expect($plainRow['has_signal'])->toBeFalse();
            expect($plainRow['is_newly_detected'])->toBeFalse();
        });

        test('ETF・投資信託はhas_signalが常にfalseで指標欄が対象外(null)になる', function () {
            [, $snapshot] = ucFrom002TestImportBatch();

            $etfHolding = ucFrom002TestHolding([
                'symbol_code' => 'VTI', 'market' => 'us', 'instrument_type' => 'etf', 'symbol_name' => 'Vanguard Total Stock Market ETF',
            ]);
            // Deliberately no TechnicalIndicator/FundamentalIndicator/Signal
            // rows are created for this holding, matching data-model.md's
            // rule that ETF/mutual fund holdings never get indicator rows.
            ucFrom002TestHoldingSnapshot($snapshot, $etfHolding, ['quantity' => 10, 'average_cost' => 200, 'current_price' => 250]);

            $response = ucFrom002TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom002TestFindRow($response, 'VTI', 'us');
            expect($row)->not->toBeNull();
            expect($row['instrument_type'])->toBe('etf');
            expect($row['has_signal'])->toBeFalse();
            expect($row['rsi'])->toBeNull();
            expect($row['per'])->toBeNull();
            expect($row['revenue_growth'])->toBeNull();
        });

        test('ETFに誤ってsignal行が存在してもhas_signalはfalseのままになる', function () {
            // Defends UC-002業務ルール「ETF・投資信託はhas_signal常にfalse」
            // at this endpoint itself, independent of whatever upstream
            // signal-detection logic (UC-004, not yet implemented) is
            // supposed to guarantee about which holdings get signal rows.
            [, $snapshot] = ucFrom002TestImportBatch();

            $etfHolding = ucFrom002TestHolding([
                'symbol_code' => 'VTI', 'market' => 'us', 'instrument_type' => 'etf', 'symbol_name' => 'Vanguard Total Stock Market ETF',
            ]);
            $etfSnapshot = ucFrom002TestHoldingSnapshot($snapshot, $etfHolding, ['quantity' => 10, 'average_cost' => 200, 'current_price' => 250]);
            ucFrom002TestSignal($etfSnapshot);

            $response = ucFrom002TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom002TestFindRow($response, 'VTI', 'us');
            expect($row)->not->toBeNull();
            expect($row['has_signal'])->toBeFalse();
        });

        test('未分類銘柄（sector_classification_idがnull）はsectorが「未分類」として一覧に出る', function () {
            [, $snapshot] = ucFrom002TestImportBatch();

            $holding = ucFrom002TestHolding([
                'symbol_code' => '1234', 'market' => 'jp', 'symbol_name' => '未分類テスト銘柄',
                'sector_classification_id' => null,
            ]);
            ucFrom002TestHoldingSnapshot($snapshot, $holding);

            $response = ucFrom002TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom002TestFindRow($response, '1234', 'jp');
            expect($row)->not->toBeNull();
            expect($row['sector'])->toBe('未分類');
        });

        test('取込データが1件も存在しない場合は空の一覧が返る', function () {
            $response = ucFrom002TestFetch($this);

            $response->assertSuccessful();
            expect($response->json('data'))->toBe([]);
        });
    });

    describe('バリデーションエラー', function () {
        test('signal_onlyに真偽値でない値を渡すと422になる', function () {
            $response = ucFrom002TestFetch($this, ['signal_only' => 'not-a-boolean']);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['signal_only']);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは保有銘柄一覧を取得できない', function () {
            [, $snapshot] = ucFrom002TestImportBatch();
            $holding = ucFrom002TestHolding();
            ucFrom002TestHoldingSnapshot($snapshot, $holding);

            $response = $this->getJson('/api/holdings');

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web guard)
            // or a 401/403 (API-style guard). Exact status is an implementation
            // choice left to the Green phase (same convention as UC-001).
            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
