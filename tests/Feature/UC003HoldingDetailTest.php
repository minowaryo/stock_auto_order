<?php

namespace Tests\Feature;

use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-003: Holding detail — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-003)
|   - docs/architecture/data-model.md (holdings / holding_snapshots /
|     snapshots / technical_indicators / fundamental_indicators / signals /
|     holding_memos)
|
| Nothing under app/ exists yet for this feature: no route, no controller,
| no FormRequest, and — most importantly — no `holding_memos` migration and
| no App\Models\HoldingMemo class at all. Tests that arrange memo history
| via the (not yet existing) HoldingMemo model are therefore expected to
| fail with a fatal "class not found" error during Arrange, rather than
| during Act/Assert — this is an intentional, expected Red state, not a
| typo (same convention as UC002HoldingListTest.php for
| SectorClassification/TechnicalIndicator/FundamentalIndicator/Signal
| before those existed).
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Endpoints:
|       GET  /holdings/{holding}          (route model binding on
|                                          holdings.id, `auth` middleware,
|                                          same "web" session guard
|                                          convention as UC-001/UC-002)
|       POST /holdings/{holding}/memos    (`auth` middleware, request body
|                                          field name `memo`)
|   - Detail response body shape: `{"data": {symbol_code, symbol_name,
|     average_cost, price_history, rsi, macd, bollinger_band:
|     {bb_upper, bb_lower}, ma20, ma75, per, pbr, roe, revenue_growth,
|     equity_ratio, dividend_yield, signal_result, signal_reason,
|     memo_history}}`. `bollinger_band` is assumed to be nested as
|     `{bb_upper, bb_lower}` per use-cases.md's `bollinger_band` output
|     name (not flattened into top-level bb_upper/bb_lower keys) — flag if
|     a flattened shape is preferred instead.
|   - `price_history` entries are `{date, close_price, ma20, ma75}` where
|     `date` is the owning weekly `holding_snapshots` row's
|     `snapshotted_at` date (Y-m-d) and `close_price` is
|     `holding_snapshots.current_price` (per data-model.md's note that the
|     chart reuses `current_price`/`ma20`/`ma75` as-is, no new fetch).
|   - `chart_period` filtering is a pure date cutoff from "now" (1y/3y/5y/
|     10y ago) applied to `holding_snapshots.snapshot.snapshotted_at`;
|     omitting the param defaults to `3y` exactly as use-cases.md states.
|   - Indicator fields (rsi/macd/macd_signal/ma20/ma75/bb_upper/bb_lower
|     and per/pbr/roe/revenue_growth/equity_ratio/dividend_yield) are the
|     *current-value cache* rows (`technical_indicators`/
|     `fundamental_indicators`, 1:1 per holding_id per data-model.md), not
|     derived from `price_history`. A missing row, or a present row with a
|     null column, both surface as `null` in the response ("取得不可").
|   - `signal_result`/`signal_reason` wording is NOT confirmed by
|     use-cases.md beyond the single example ("利確検討" /
|     "RSIが72から65に反落"). This test assumes, as a placeholder contract
|     to validate against:
|       - has signal(s) on the latest holding_snapshot -> signal_result
|         === '利確検討', signal_reason === the (single, in this test)
|         signal's `signals.reason_summary` value verbatim.
|       - no signal on the latest holding_snapshot -> signal_result ===
|         'シグナルなし', signal_reason === a non-empty explanatory string
|         (exact wording intentionally left unasserted here since
|         use-cases.md gives no "no signal" example text — only presence
|         of a reasonable message is checked).
|     **This exact wording is the single biggest open question for Gate 4
|     review** — please confirm or correct signal_result/signal_reason
|     values during the Gate 4 walkthrough.
|   - Memo save success response: 201, body `{"data": {"body": ...,
|     "recorded_at": ...}}` representing the newly created memo. Whether
|     the endpoint instead returns the full updated `memo_history` array is
|     left open; this test only relies on being able to re-fetch the detail
|     endpoint afterwards and see the new memo reflected in
|     `memo_history`, which holds under either response shape.
|   - decimal-cast numeric fields are compared as floats regardless of
|     whether the JSON encodes them as string or number (same convention
|     as UC002HoldingListTest.php).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom003TestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom003TestHolding(array $attributes = []): Holding
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
function ucFrom003TestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
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
 * @param  array<string, mixed>  $attributes
 */
function ucFrom003TestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 65.5,
        'macd' => 1.2345,
        'macd_signal' => 0.9876,
        'ma20' => 2400.0,
        'ma75' => 2300.0,
        'bb_upper' => 2600.0,
        'bb_lower' => 2200.0,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom003TestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 15.2,
        'pbr' => 1.3,
        'roe' => 12.5,
        'revenue_growth' => 8.4,
        'operating_income_growth' => 6.1,
        'equity_ratio' => 55.0,
        'dividend_yield' => 2.1,
        'dividend_payout_ratio' => 30.0,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom003TestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): Signal
{
    return Signal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72から65に反落',
    ], $attributes));
}

/**
 * `holding_memos` has no migration and App\Models\HoldingMemo does not
 * exist yet (docs/architecture/data-model.md). Calling this helper is
 * expected to fatal-error with a "class not found" error until Gate 4
 * Green work adds the model/migration — that is the intended Red state
 * for any test that uses it.
 *
 * @return object
 */
function ucFrom003TestMemo(Holding $holding, string $body, ?\DateTimeInterface $recordedAt = null)
{
    return \App\Models\HoldingMemo::create([
        'holding_id' => $holding->id,
        'body' => $body,
        'recorded_at' => $recordedAt ?? now(),
    ]);
}

/**
 * Fetch the holding detail as an authenticated user.
 *
 * @param  array<string, mixed>  $query
 */
function ucFrom003TestFetchDetail(TestCase $test, int|Holding $holding, array $query = [], ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();
    $holdingId = $holding instanceof Holding ? $holding->id : $holding;
    $url = "/holdings/{$holdingId}".(empty($query) ? '' : ('?'.http_build_query($query)));

    return $test->actingAs($user)->getJson($url);
}

/**
 * Save a memo for the given holding as an authenticated user.
 */
function ucFrom003TestSaveMemo(TestCase $test, Holding $holding, string $memo, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->postJson("/holdings/{$holding->id}/memos", ['memo' => $memo]);
}

describe('UC-003: 銘柄詳細表示', function () {
    describe('正常系（詳細取得）', function () {
        test('銘柄詳細を取得できる', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding([
                'symbol_code' => '7203',
                'market' => 'jp',
                'symbol_name' => 'トヨタ自動車',
            ]);
            ucFrom003TestHoldingSnapshot($snapshot, $holding, [
                'average_cost' => 2000.00,
                'current_price' => 2500.00,
            ]);

            $response = ucFrom003TestFetchDetail($this, $holding);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['symbol_code'])->toBe('7203');
            expect($data['symbol_name'])->toBe('トヨタ自動車');
            expect((float) $data['average_cost'])->toEqualWithDelta(2000.0, 0.01);
        });

        test('price_historyが週次スナップショットの時系列（date/close_price/ma20/ma75）として返る', function () {
            $holding = ucFrom003TestHolding();

            [, $week1] = ucFrom003TestImportBatch(now()->subWeeks(2));
            ucFrom003TestHoldingSnapshot($week1, $holding, ['current_price' => 2400.00, 'ma20' => 2350.00, 'ma75' => 2300.00]);

            [, $week2] = ucFrom003TestImportBatch(now()->subWeeks(1));
            ucFrom003TestHoldingSnapshot($week2, $holding, ['current_price' => 2450.00, 'ma20' => 2380.00, 'ma75' => 2310.00]);

            [, $week3] = ucFrom003TestImportBatch(now());
            ucFrom003TestHoldingSnapshot($week3, $holding, ['current_price' => 2500.00, 'ma20' => 2400.00, 'ma75' => 2320.00]);

            $response = ucFrom003TestFetchDetail($this, $holding);

            $response->assertSuccessful();

            $priceHistory = $response->json('data.price_history');
            expect($priceHistory)->toHaveCount(3);

            $latest = collect($priceHistory)->firstWhere('date', now()->toDateString());
            expect($latest)->not->toBeNull();
            expect((float) $latest['close_price'])->toEqualWithDelta(2500.0, 0.01);
            expect((float) $latest['ma20'])->toEqualWithDelta(2400.0, 0.01);
            expect((float) $latest['ma75'])->toEqualWithDelta(2320.0, 0.01);
        });

        test('chart_periodで絞り込める（1yを指定すると1年より古いスナップショットが除外される）', function () {
            $holding = ucFrom003TestHolding();

            [, $oldSnapshot] = ucFrom003TestImportBatch(now()->subYears(2));
            ucFrom003TestHoldingSnapshot($oldSnapshot, $holding, ['current_price' => 2000.00]);

            [, $recentSnapshot] = ucFrom003TestImportBatch(now()->subMonths(6));
            ucFrom003TestHoldingSnapshot($recentSnapshot, $holding, ['current_price' => 2600.00]);

            $response = ucFrom003TestFetchDetail($this, $holding, ['chart_period' => '1y']);

            $response->assertSuccessful();

            $priceHistory = $response->json('data.price_history');
            expect($priceHistory)->toHaveCount(1);
            expect((float) $priceHistory[0]['close_price'])->toEqualWithDelta(2600.0, 0.01);
        });

        test('chart_period省略時は3年がデフォルトになる', function () {
            $holding = ucFrom003TestHolding();

            // Older than 3y: must be excluded from the default view.
            [, $tooOldSnapshot] = ucFrom003TestImportBatch(now()->subYears(4));
            ucFrom003TestHoldingSnapshot($tooOldSnapshot, $holding, ['current_price' => 1500.00]);

            // Within 3y: must be included.
            [, $withinRangeSnapshot] = ucFrom003TestImportBatch(now()->subYears(2));
            ucFrom003TestHoldingSnapshot($withinRangeSnapshot, $holding, ['current_price' => 2100.00]);

            $response = ucFrom003TestFetchDetail($this, $holding);

            $response->assertSuccessful();

            $priceHistory = $response->json('data.price_history');
            expect($priceHistory)->toHaveCount(1);
            expect((float) $priceHistory[0]['close_price'])->toEqualWithDelta(2100.0, 0.01);
        });

        test('テクニカル指標・ファンダメンタルズ指標が反映される', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            ucFrom003TestHoldingSnapshot($snapshot, $holding);
            ucFrom003TestTechnicalIndicator($holding, ['rsi' => 65.5, 'macd' => 1.2345, 'bb_upper' => 2600.0, 'bb_lower' => 2200.0]);
            ucFrom003TestFundamentalIndicator($holding, ['per' => 15.2, 'roe' => 12.5]);

            $response = ucFrom003TestFetchDetail($this, $holding);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect((float) $data['rsi'])->toEqualWithDelta(65.5, 0.01);
            expect((float) $data['macd'])->toEqualWithDelta(1.2345, 0.001);
            expect((float) $data['bollinger_band']['bb_upper'])->toEqualWithDelta(2600.0, 0.01);
            expect((float) $data['bollinger_band']['bb_lower'])->toEqualWithDelta(2200.0, 0.01);
            expect((float) $data['per'])->toEqualWithDelta(15.2, 0.01);
            expect((float) $data['roe'])->toEqualWithDelta(12.5, 0.01);
        });

        test('指標データが存在しない場合は該当項目がnull（取得不可）になる', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            ucFrom003TestHoldingSnapshot($snapshot, $holding);
            // Deliberately no TechnicalIndicator/FundamentalIndicator rows created.

            $response = ucFrom003TestFetchDetail($this, $holding);

            $response->assertSuccessful();

            $data = $response->json('data');
            expect($data['rsi'])->toBeNull();
            expect($data['macd'])->toBeNull();
            expect($data['bollinger_band']['bb_upper'])->toBeNull();
            expect($data['bollinger_band']['bb_lower'])->toBeNull();
            expect($data['per'])->toBeNull();
            expect($data['roe'])->toBeNull();
        });

        test('signalsが存在する銘柄はsignal_result/signal_reasonにシグナルありの内容が反映される', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            $holdingSnapshot = ucFrom003TestHoldingSnapshot($snapshot, $holding);
            ucFrom003TestSignal($holdingSnapshot, ['reason_summary' => 'RSIが72から65に反落']);

            $response = ucFrom003TestFetchDetail($this, $holding);

            $response->assertSuccessful();

            $data = $response->json('data');
            // Placeholder contract — see file-level docblock. Confirm wording at Gate 4.
            expect($data['signal_result'])->toBe('利確検討');
            expect($data['signal_reason'])->toBe('RSIが72から65に反落');
        });

        test('signalsが存在しない銘柄はシグナルなしを示す内容になる', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            ucFrom003TestHoldingSnapshot($snapshot, $holding);
            // Deliberately no Signal row created.

            $response = ucFrom003TestFetchDetail($this, $holding);

            $response->assertSuccessful();

            $data = $response->json('data');
            // Placeholder contract — see file-level docblock. Confirm wording at Gate 4.
            expect($data['signal_result'])->toBe('シグナルなし');
            expect($data['signal_reason'])->toBeString();
            expect(trim((string) $data['signal_reason']))->not->toBe('');
        });

        test('保存済みメモ一覧（memo_history）が返る', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            ucFrom003TestHoldingSnapshot($snapshot, $holding);
            ucFrom003TestMemo($holding, 'LLMとの壁打ち: 決算好調、しばらく保有継続', now()->subDays(3));
            ucFrom003TestMemo($holding, '含み益+15%到達、様子見継続', now()->subDay());

            $response = ucFrom003TestFetchDetail($this, $holding);

            $response->assertSuccessful();

            $memoHistory = $response->json('data.memo_history');
            expect($memoHistory)->toHaveCount(2);

            $bodies = collect($memoHistory)->pluck('body')->all();
            expect($bodies)->toContain('LLMとの壁打ち: 決算好調、しばらく保有継続');
            expect($bodies)->toContain('含み益+15%到達、様子見継続');
        });
    });

    describe('正常系（メモ保存）', function () {
        test('メモを保存できる', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            ucFrom003TestHoldingSnapshot($snapshot, $holding);

            $response = ucFrom003TestSaveMemo($this, $holding, 'LLMとの壁打ち内容の転記テスト');

            $response->assertCreated();

            $detail = ucFrom003TestFetchDetail($this, $holding);
            $bodies = collect($detail->json('data.memo_history'))->pluck('body')->all();
            expect($bodies)->toContain('LLMとの壁打ち内容の転記テスト');
        });

        test('複数回保存すると追記されていく（既存メモは残る）', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            ucFrom003TestHoldingSnapshot($snapshot, $holding);

            ucFrom003TestSaveMemo($this, $holding, '1回目のメモ')->assertCreated();
            ucFrom003TestSaveMemo($this, $holding, '2回目のメモ')->assertCreated();

            $detail = ucFrom003TestFetchDetail($this, $holding);
            $bodies = collect($detail->json('data.memo_history'))->pluck('body')->all();

            expect($bodies)->toContain('1回目のメモ');
            expect($bodies)->toContain('2回目のメモ');
            expect(count($bodies))->toBeGreaterThanOrEqual(2);
        });
    });

    describe('異常系・境界値', function () {
        test('メモが2000文字を超える場合は422エラーになる', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            ucFrom003TestHoldingSnapshot($snapshot, $holding);

            $tooLongMemo = str_repeat('あ', 2001);

            $response = ucFrom003TestSaveMemo($this, $holding, $tooLongMemo);

            $response->assertStatus(422);
            $response->assertJsonPath('errors.memo.0', 'メモは2000文字以内で入力してください');
        });

        test('存在しない銘柄IDを指定した場合は404になる', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->getJson('/holdings/999999');

            $response->assertStatus(404);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは銘柄詳細を取得できない', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            ucFrom003TestHoldingSnapshot($snapshot, $holding);

            $response = $this->getJson("/holdings/{$holding->id}");

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web guard)
            // or a 401/403 (API-style guard). Exact status is an implementation
            // choice left to the Green phase (same convention as UC-001/UC-002).
            expect($response->status())->toBeIn([302, 401, 403]);
        });

        test('未認証ユーザーはメモを保存できない', function () {
            [, $snapshot] = ucFrom003TestImportBatch();
            $holding = ucFrom003TestHolding();
            ucFrom003TestHoldingSnapshot($snapshot, $holding);

            $response = $this->postJson("/holdings/{$holding->id}/memos", ['memo' => 'テスト']);

            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
