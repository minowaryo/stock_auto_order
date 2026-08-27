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
| UC-010: 既存保有株の買い増しタイミングレコメンド一覧 — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-010)
|   - docs/architecture/data-model.md (`buy_signals` table + 「保留・確定が
|     必要な初期パラメータ値」の買い増し関連行)
|   - docs/adr/ADR-0007-existing-holding-add-on-buy-recommendation.md
|   - tests/Feature/UC004SignalListTest.php (screen-level Feature Test
|     precedent this file mirrors for helper naming, describe structure,
|     `{"data": [...]}` response-wrapper convention)
|
| This file targets the *screen* (list API) only, mirroring
| UC004SignalListTest.php's scope note: it does NOT re-verify
| BuySignalDeterminationService's judgement logic (that is
| BuySignalDeterminationServiceTest's responsibility) — it creates BuySignal
| rows directly and only exercises list retrieval/formatting/sorting.
|
| Original Red causes (historical — UC-010 is now Green-implemented; kept
| for context on this file's original authoring):
|   - App\Models\BuySignal does not exist yet (no `buy_signals` migration,
|     no model file under app/Models/). Every test that calls the
|     ucFrom010TestBuySignal() helper is expected to fail immediately with a
|     fatal "Class \"App\Models\BuySignal\" not found" error — before the
|     HTTP request is even made — since PHP resolves the class reference at
|     call time (same convention as UC003HoldingDetailTest.php failing on
|     HoldingMemo before that model existed).
|   - Separately, GET /api/buy-signals has no route/Controller/Action yet
|     (verified against routes/web.php and app/Http/Controllers/ before
|     writing this file), so tests that reach the HTTP call (the empty-list
|     and unauthenticated tests, which create no BuySignal rows) are expected
|     to fail with a 404 instead — same "no route yet" Red cause as
|     UC004SignalListTest.php.
|
| -------------------------------------------------------------------------
| CR (2026-08-25): ファンダメンタルズ健全性フィルタへの成長率条件追加
| -------------------------------------------------------------------------
| `/review` 指摘を受け、FundamentalHealthEvaluator::evaluate() のシグネチャに
| `$revenueGrowth`/`$operatingIncomeGrowth` の2引数を追加するCR（詳細な設計
| 理由は tests/Unit/Services/Analysis/FundamentalHealthEvaluatorTest.php
| 冒頭を参照）。本ファイルの `ucFrom010TestFundamentalIndicator()` ヘルパーは
| 元々 `revenue_growth: 8.0, operating_income_growth: 12.3`
| （use-cases.mdのfundamental_summary出力例
| 「ROE15.2%・自己資本比率58.0%・営業利益成長率+12.3%」と同値）を
| デフォルトで含んでいたため、既存の正常系テスト呼び出し（オーバーライドで
| 成長率を上書きしていない箇所）は変更不要だった。変更したのは、
| fundamental_summaryに成長率（数値・符号）が含まれることを検証する
| アサーションの追加のみ（後述）。
|
| Verified actual Red cause at the time of writing (2026-08-25 — confirmed by
| running `docker compose exec laravel.test php artisan test
| tests/Feature/UC010BuySignalListTest.php`, NOT merely hypothesized):
| **every single test in this file currently fails with HTTP 404**, because
| `routes/web.php` has no `GET /api/buy-signals` route registration at all
| (verified via `php artisan route:list` — App\Http\Controllers\
| BuySignalListController and app/Actions/Signal/ShowBuySignalListAction.php
| both exist as untracked files, but nothing wires the route to the
| Controller). This is a **pre-existing gap unrelated to this CR's growth-
| rate scope** — it already existed before this Red-phase revision and
| affects every test in this file uniformly, including the ones with no
| relation to fundamental health at all (e.g. the unauthenticated-access
| test, the empty-list tests). Since `routes/web.php` is outside this
| agent's editable scope (tests/ only), this file's Red-phase revision
| cannot resolve it and flags it here for Gate 4 review / the Green-phase
| implementer instead.
|
| Practical consequence for this CR's growth-rate assertion specifically:
| because every Feature-level HTTP call 404s before reaching
| ShowBuySignalListAction's body, the newly added `fundamental_summary`
| growth-rate assertion (see the normal-flow test below) cannot currently be
| distinguished, at the Feature-test level, from the pre-existing routing
| gap — both produce the same `assertSuccessful()` failure. The growth-rate
| CR's logic itself IS correctly exercised and fails for the intended reason
| at the Unit-test level (see
| tests/Unit/Services/Analysis/FundamentalHealthEvaluatorTest.php, which has
| no HTTP/routing dependency). Once the routing gap is fixed (Green phase,
| out of this file's scope) and the Action's `evaluate()` call site is
| updated for the new 4-arg signature, this file's growth-rate assertion
| will meaningfully re-verify the Feature-level integration.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Endpoint: GET /api/buy-signals, `auth` middleware, same convention as
|     the other /api/* routes in routes/web.php.
|   - Success response body shape: `{"data": [ {symbol_code, symbol_name,
|     current_price, unrealized_gain_rate, buy_signal_types,
|     buy_signal_reason_summary, fundamental_status, fundamental_summary,
|     nisa_recommended, nisa_recommended_reason, suggested_amount,
|     split_buy_down_suggestion}, ... ] }` per UC-010's 出力仕様.
|   - `buy_signal_types` is an array of the raw `buy_signals.signal_type`
|     enum values, `buy_signal_reason_summary` joins each buy_signal's
|     `reason_summary` (joiner left to the implementation — only substring
|     containment is asserted, mirroring UC004SignalListTest.php).
|   - `suggested_amount` = ポートフォリオ評価総額 × 2%
|     (docs/architecture/data-model.md「保留・確定が必要な初期パラメータ値」
|     「買い増し追加投資額の目安率」)、算出方法は
|     NewCandidateFinder::portfolioEvaluationTotal() と同一
|     (quantity×current_price、投資信託のみ÷10000補正)。全テストで対象銘柄を
|     直近スナップショット内の唯一の保有にすることで、この合計を単純化・予測可能にしている
|     （並び順テストを除く）。
|   - `split_buy_down_suggestion` は現在値×1.00/0.93/0.85の3段階
|     `{price, quantity}`、quantity = floor(suggested_amount ÷ price)
|     （UC-004の`ShowSignalListAction::splitLimitSuggestion()`と同様floor丸め
|     という設計提案）。
|   - `nisa_recommended`の追加基準は data-model.md 上 **未確定**
|     （「具体的な閾値は未定」）のため、本ファイルでは境界値を厳密にピン留めせず、
|     ・自己資本比率58.0%/ROE15.2%（use-cases.mdのfundamental_summary例と同値、
|       NewCandidateFinderの確定済みNISA基準50%/15%を明確に上回る）→true
|     ・自己資本比率42.0%/ROE11.0%（健全性フィルタ40%/10%はぎりぎり満たすが、
|       いかなる合理的なNISA追加基準（叩き台の50%/15%含む）にも遠く届かない）→false
|     という「どちらの側にも解釈の余地がない」安全マージンを取った2値のみ確認する。
|   - decimal-cast numeric fields are compared as floats regardless of
|     whether JSON encodes them as string or number (same convention as
|     UC002/UC003/UC004).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function ucFrom010TestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function ucFrom010TestHolding(array $attributes = []): Holding
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
function ucFrom010TestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 300,
        'average_cost' => 800,
        'current_price' => 1000,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 60000,
        'unrealized_gain_rate' => 25.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * Creates a `buy_signals` row directly (App\Models\BuySignal — does not
 * exist yet, see file-level docblock: this is the primary Red trigger for
 * every test that uses this helper).
 *
 * @param  array<string, mixed>  $attributes
 */
function ucFrom010TestBuySignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): mixed
{
    $class = 'App\\Models\\BuySignal';

    return $class::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_oversold_rebound',
        'reason_summary' => 'RSIが28から34へ反発しました',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom010TestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): Signal
{
    return Signal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72から65に反落',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ucFrom010TestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
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
 * @param  array<string, mixed>  $attributes
 */
function ucFrom010TestAccount(HoldingSnapshot $holdingSnapshot, array $attributes = []): HoldingSnapshotAccount
{
    return HoldingSnapshotAccount::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'account_type' => 'specific',
        'quantity' => 300,
        'average_cost' => 800.00,
    ], $attributes));
}

/**
 * Fetch the buy-signal list as an authenticated user.
 */
function ucFrom010TestFetch(TestCase $test, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->getJson('/api/buy-signals');
}

/**
 * @return array<string, mixed>|null
 */
function ucFrom010TestFindRow(TestResponse $response, string $symbolCode): ?array
{
    $rows = $response->json('data') ?? [];

    foreach ($rows as $row) {
        if (($row['symbol_code'] ?? null) === $symbolCode) {
            return $row;
        }
    }

    return null;
}

describe('UC-010: 既存保有株の買い増しタイミングレコメンド一覧', function () {
    describe('正常系', function () {
        test('押し目買いシグナルが1件以上あり、ファンダメンタルズ健全性フィルタを満たす銘柄が一覧に含まれ、主要フィールドが反映される', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding([
                'symbol_code' => '7203',
                'market' => 'jp',
                'symbol_name' => 'トヨタ自動車',
            ]);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 300,
                'average_cost' => 800.00,
                'current_price' => 1000.00,
                'unrealized_gain_rate' => 25.0,
            ]);
            ucFrom010TestBuySignal($holdingSnapshot, [
                'signal_type' => 'rsi_oversold_rebound',
                'reason_summary' => 'RSIが28から34へ反発しました',
            ]);
            ucFrom010TestFundamentalIndicator($holding, ['equity_ratio' => 58.0, 'roe' => 15.2]);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();

            $row = ucFrom010TestFindRow($response, '7203');
            expect($row)->not->toBeNull();
            expect($row['symbol_name'])->toBe('トヨタ自動車');
            expect((float) $row['current_price'])->toEqualWithDelta(1000.0, 0.01);
            expect((float) $row['unrealized_gain_rate'])->toEqualWithDelta(25.0, 0.01);
            expect($row['buy_signal_types'])->toBe(['rsi_oversold_rebound']);
            expect($row['buy_signal_reason_summary'])->toContain('RSIが28から34へ反発しました');
            expect($row['fundamental_status'])->toBe('passed');
            expect($row['fundamental_summary'])->toBeString();
            expect(trim((string) $row['fundamental_summary']))->not->toBe('');
            // CR (2026-08-25): use-cases.md UC-010出力例
            // 「ROE15.2%・自己資本比率58.0%・営業利益成長率+12.3%」に合わせ、
            // fundamental_summaryに成長率の数値・符号が含まれることを検証する。
            // ucFrom010TestFundamentalIndicator()のデフォルト値
            // operating_income_growth=12.3（プラス）を使用。正確な文言・
            // 採用する成長率指標（売上高/営業利益のいずれを表示するか）は
            // Gate4レビューで確認する前提とし、ここでは符号付き数値の
            // 部分文字列のみを検証する。
            expect($row['fundamental_summary'])->toContain('+12.3');

            // suggested_amount = portfolio total (300*1000=300,000) * 2% = 6,000
            expect((float) $row['suggested_amount'])->toEqualWithDelta(6000.0, 1.0);

            $suggestion = $row['split_buy_down_suggestion'];
            expect($suggestion)->toBeArray();
            expect(count($suggestion))->toBe(3);

            // Tier1: price = 1000 * 1.00 = 1000, quantity = floor(6000/1000) = 6
            expect((float) $suggestion[0]['price'])->toEqualWithDelta(1000.0, 0.01);
            expect((float) $suggestion[0]['quantity'])->toEqualWithDelta(6.0, 0.01);

            // Tier2: price = 1000 * 0.93 = 930, quantity = floor(6000/930) = 6
            expect((float) $suggestion[1]['price'])->toEqualWithDelta(930.0, 0.01);
            expect((float) $suggestion[1]['quantity'])->toEqualWithDelta(6.0, 0.01);

            // Tier3: price = 1000 * 0.85 = 850, quantity = floor(6000/850) = 7
            expect((float) $suggestion[2]['price'])->toEqualWithDelta(850.0, 0.01);
            expect((float) $suggestion[2]['quantity'])->toEqualWithDelta(7.0, 0.01);
        });

        test('財務健全性がNISA推奨の追加基準を明確に上回る場合、nisa_recommendedがtrueになる', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => '6758', 'market' => 'jp', 'symbol_name' => 'ソニーグループ']);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding);
            ucFrom010TestBuySignal($holdingSnapshot);
            ucFrom010TestFundamentalIndicator($holding, ['equity_ratio' => 58.0, 'roe' => 15.2]);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, '6758');
            expect($row)->not->toBeNull();
            expect($row['nisa_recommended'])->toBeTrue();
            expect($row['nisa_recommended_reason'])->toBeString();
            expect(trim((string) $row['nisa_recommended_reason']))->not->toBe('');
        });

        // ---------------------------------------------------------------
        // CR (2026-08-27): /review 指摘・修正2 — fundamental_summaryの
        // 成長率表示バグの再発防止
        // ---------------------------------------------------------------
        // evaluate()の合格条件は「売上高成長率または営業利益成長率のいずれか
        // がプラス」というOR条件だが、fundamentalSummary()は符号を見ずに
        // 営業利益成長率を無条件優先表示するため、「売上高成長率のプラスで
        // 合格したのに、要約文にはマイナスの営業利益成長率が表示される」と
        // いう矛盾が起こり得る。合格の実際の根拠になった方（プラスである方）
        // の成長率を優先表示すべき。
        test('売上高成長率がプラス・営業利益成長率がマイナスの場合、健全性判定はpassedになるがfundamental_summaryには合格根拠のプラスの売上高成長率が表示されるべき', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => '4523', 'market' => 'jp', 'symbol_name' => 'エーザイ']);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding);
            ucFrom010TestBuySignal($holdingSnapshot);
            ucFrom010TestFundamentalIndicator($holding, [
                'equity_ratio' => 58.0,
                'roe' => 15.2,
                'revenue_growth' => 5.0,
                'operating_income_growth' => -2.0,
            ]);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, '4523');
            expect($row)->not->toBeNull();

            // OR条件は売上高成長率+5.0のプラスで満たされているため、判定は
            // passedになる（この部分はFundamentalHealthEvaluator側は既に
            // 正しく動作する）。
            expect($row['fundamental_status'])->toBe('passed');

            // 現状のfundamentalSummary()は営業利益成長率を符号に関わらず
            // 無条件優先するため-2.0が表示されてしまう（バグ）。合格の実際の
            // 根拠になったプラスの売上高成長率+5.0が表示されるべきで、
            // マイナスの営業利益成長率-2.0は表示されるべきではない。
            expect($row['fundamental_summary'])->toContain('+5.0');
            expect($row['fundamental_summary'])->not->toContain('-2.0');
        });

        test('健全性フィルタはぎりぎり満たすがNISA推奨基準には遠く届かない場合、nisa_recommendedがfalseになる', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => '8306', 'market' => 'jp', 'symbol_name' => '三菱UFJフィナンシャル・グループ']);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding);
            ucFrom010TestBuySignal($holdingSnapshot);
            ucFrom010TestFundamentalIndicator($holding, ['equity_ratio' => 42.0, 'roe' => 11.0]);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, '8306');
            expect($row)->not->toBeNull();
            expect($row['fundamental_status'])->toBe('passed');
            expect($row['nisa_recommended'])->toBeFalse();
        });
    });

    describe('境界値・除外条件', function () {
        test('押し目買いシグナルが0件の銘柄は一覧から除外される（UC-004と異なりシグナル必須）', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => '9984', 'market' => 'jp', 'symbol_name' => 'ソフトバンクグループ']);
            ucFrom010TestHoldingSnapshot($snapshot, $holding);
            ucFrom010TestFundamentalIndicator($holding);
            // Deliberately no BuySignal row created.

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, '9984');
            expect($row)->toBeNull();
        });

        test('ファンダメンタルズ健全性フィルタを満たさない銘柄（failed相当）は一覧から除外される', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => '9433', 'market' => 'jp', 'symbol_name' => 'KDDI']);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding);
            ucFrom010TestBuySignal($holdingSnapshot);
            ucFrom010TestFundamentalIndicator($holding, ['equity_ratio' => 30.0, 'roe' => 5.0]);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, '9433');
            expect($row)->toBeNull();
        });

        test('ファンダメンタルズ指標が未取得（fundamentalIndicator行が存在しない、US株等）の銘柄はfundamental_status=unavailableとして一覧から除外されない', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => 'AAPL', 'market' => 'us', 'symbol_name' => 'Apple Inc.']);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding, [
                'current_price' => 25000.00,
                'fx_rate_used' => 150.0,
            ]);
            ucFrom010TestBuySignal($holdingSnapshot);
            // Deliberately no FundamentalIndicator row created.

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, 'AAPL');
            expect($row)->not->toBeNull();
            expect($row['fundamental_status'])->toBe('unavailable');
        });

        test('ETFは押し目買いシグナルがあっても一覧から除外される', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding([
                'symbol_code' => 'VTI', 'market' => 'us', 'instrument_type' => 'etf', 'symbol_name' => 'Vanguard Total Stock Market ETF',
            ]);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding, ['current_price' => 280.00]);
            ucFrom010TestBuySignal($holdingSnapshot);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, 'VTI');
            expect($row)->toBeNull();
        });

        test('投資信託は押し目買いシグナルがあっても一覧から除外される', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding([
                'symbol_code' => 'eMAXIS Slim 全世界株式', 'market' => 'mutual_fund', 'instrument_type' => 'mutual_fund',
                'symbol_name' => 'eMAXIS Slim 全世界株式',
            ]);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding, ['current_price' => 14000.00]);
            ucFrom010TestBuySignal($holdingSnapshot);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, 'eMAXIS Slim 全世界株式');
            expect($row)->toBeNull();
        });

        test('含み損（マイナスの含み益率）の銘柄も一覧に含まれる（UC-004の+20%ゲートと異なり、含み益率による絞り込みは一切行わない）', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => '4502', 'market' => 'jp', 'symbol_name' => '武田薬品工業']);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 100,
                'average_cost' => 4000.00,
                'current_price' => 3200.00,
                'unrealized_gain_rate' => -20.0,
            ]);
            ucFrom010TestBuySignal($holdingSnapshot);
            ucFrom010TestFundamentalIndicator($holding);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, '4502');
            expect($row)->not->toBeNull();
            expect((float) $row['unrealized_gain_rate'])->toEqualWithDelta(-20.0, 0.01);
        });

        test('同一銘柄に利確シグナル（signalsテーブル）が同時に成立している場合は一覧から除外される', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => '7267', 'market' => 'jp', 'symbol_name' => 'ホンダ']);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 30.0]);
            ucFrom010TestBuySignal($holdingSnapshot);
            ucFrom010TestSignal($holdingSnapshot);
            ucFrom010TestFundamentalIndicator($holding);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, '7267');
            expect($row)->toBeNull();
        });

        test('保有がすべてNISA区分（NISA成長投資枠・つみたて投資枠）の銘柄も一覧から除外されない（UC-004と異なりNISAは除外要因にしない）', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => '9432', 'market' => 'jp', 'symbol_name' => '日本電信電話']);
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding, ['quantity' => 300]);
            ucFrom010TestAccount($holdingSnapshot, ['account_type' => 'nisa_growth', 'quantity' => 200]);
            ucFrom010TestAccount($holdingSnapshot, ['account_type' => 'nisa_tsumitate', 'quantity' => 100]);
            ucFrom010TestBuySignal($holdingSnapshot);
            ucFrom010TestFundamentalIndicator($holding);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $row = ucFrom010TestFindRow($response, '9432');
            expect($row)->not->toBeNull();
        });

        test('対象銘柄が1件も存在しない場合は空配列が返る', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            ucFrom010TestHoldingSnapshot($snapshot, $holding);
            // Deliberately no BuySignal row created.

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            expect($response->json('data'))->toBe([]);
        });

        test('取込データが1件も存在しない場合も空配列が返る', function () {
            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            expect($response->json('data'))->toBe([]);
        });
    });

    describe('ソート順', function () {
        test('ファンダ状態(passedをunavailableより優先)→買い増しシグナル件数の多い順→含み益率の低い順に並ぶ', function () {
            [, $snapshot] = ucFrom010TestImportBatch();

            // A: fundamental_status=unavailable（本来ならpassedより後）だが、
            //    もし優先されればソート違反として検出できるよう極端に低い含み益率にする
            $holdingA = ucFrom010TestHolding(['symbol_code' => 'AAPL', 'market' => 'us', 'symbol_name' => 'Apple Inc.']);
            $snapshotA = ucFrom010TestHoldingSnapshot($snapshot, $holdingA, ['unrealized_gain_rate' => -90.0]);
            ucFrom010TestBuySignal($snapshotA, ['signal_type' => 'rsi_oversold_rebound']);

            // B: fundamental_status=passed, シグナル1件, 含み益率 +10%
            $holdingB = ucFrom010TestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            $snapshotB = ucFrom010TestHoldingSnapshot($snapshot, $holdingB, ['unrealized_gain_rate' => 10.0]);
            ucFrom010TestBuySignal($snapshotB, ['signal_type' => 'rsi_oversold_rebound']);
            ucFrom010TestFundamentalIndicator($holdingB);

            // C: fundamental_status=passed, シグナル2件, 含み益率 +5%
            $holdingC = ucFrom010TestHolding(['symbol_code' => '6758', 'market' => 'jp', 'symbol_name' => 'ソニーグループ']);
            $snapshotC = ucFrom010TestHoldingSnapshot($snapshot, $holdingC, ['unrealized_gain_rate' => 5.0]);
            ucFrom010TestBuySignal($snapshotC, ['signal_type' => 'rsi_oversold_rebound']);
            ucFrom010TestBuySignal($snapshotC, ['signal_type' => 'macd_golden_cross', 'reason_summary' => 'MACDがゴールデンクロスしました']);
            ucFrom010TestFundamentalIndicator($holdingC);

            // D: fundamental_status=passed, シグナル2件, 含み益率 -10%（Cと同件数だが含み益率が低い）
            $holdingD = ucFrom010TestHolding(['symbol_code' => '8306', 'market' => 'jp', 'symbol_name' => '三菱UFJフィナンシャル・グループ']);
            $snapshotD = ucFrom010TestHoldingSnapshot($snapshot, $holdingD, ['unrealized_gain_rate' => -10.0]);
            ucFrom010TestBuySignal($snapshotD, ['signal_type' => 'rsi_oversold_rebound']);
            ucFrom010TestBuySignal($snapshotD, ['signal_type' => 'bollinger_oversold', 'reason_summary' => '終値がボリンジャーバンド下限を下回りました']);
            ucFrom010TestFundamentalIndicator($holdingD);

            $response = ucFrom010TestFetch($this);

            $response->assertSuccessful();
            $rows = $response->json('data');
            $order = array_column($rows, 'symbol_code');

            // Expected: D (passed, 2 signals, -10%) -> C (passed, 2 signals, +5%)
            //   -> B (passed, 1 signal, +10%) -> A (unavailable, 1 signal, -90%)
            expect($order)->toBe(['8306', '6758', '7203', 'AAPL']);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは買い増しタイミングレコメンド一覧を取得できない', function () {
            [, $snapshot] = ucFrom010TestImportBatch();
            $holding = ucFrom010TestHolding();
            $holdingSnapshot = ucFrom010TestHoldingSnapshot($snapshot, $holding);
            ucFrom010TestBuySignal($holdingSnapshot);

            $response = $this->getJson('/api/buy-signals');

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web guard)
            // or a 401/403 (API-style guard). Exact status is an implementation
            // choice left to the Green phase (same convention as UC-001〜004).
            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
