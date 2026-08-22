<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\HoldingSnapshot;
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
| Scope note (per Gate 4 precedent set by UC-009, see PLAN.md /
| data-model.md 変更履歴 2026-08-21 entry): holding_snapshot_accounts
| (NISA account-type breakdown, ADR-0002) has no writer yet (CSV parsers
| don't populate it), so NISA-account exclusion for split_limit_suggestion
| (use-cases.md UC-004 output table) is explicitly OUT OF SCOPE for this
| Gate 4 cycle. split_limit_suggestion is computed against the holding's
| whole (combined) quantity, matching the precedent's "全保有数量ベース"
| approach. This will need a follow-up Red/Green cycle once
| holding_snapshot_accounts is populated (same as UC-004/005/008 are noted
| as pending in data-model.md's 2026-08-21 entry for UC-009).
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
 * Fetch the signal list as an authenticated user.
 */
function ucFrom004TestFetch(TestCase $test, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->getJson('/signals');
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

    describe('権限', function () {
        test('未認証ユーザーは利確シグナル一覧を取得できない', function () {
            [, $snapshot] = ucFrom004TestImportBatch();
            $holding = ucFrom004TestHolding();
            ucFrom004TestHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 30.0]);

            $response = $this->getJson('/signals');

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web guard)
            // or a 401/403 (API-style guard). Exact status is an implementation
            // choice left to the Green phase (same convention as UC-001/002/003).
            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
