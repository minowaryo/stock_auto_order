<?php

namespace Tests\Feature;

use App\Livewire\Holding\HoldingList;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\MarketIndicatorSnapshot;
use App\Models\SectorClassification;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UC-002 + UC-007: 保有銘柄一覧画面（Livewireフルページ。UC-007の市場全体
| 指標ウィジェットを内包） — Red phase Livewire Component Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-002 / UC-007)
|   - docs/architecture/data-model.md (holdings / holding_snapshots /
|     snapshots / sector_classifications / technical_indicators /
|     fundamental_indicators / signals / market_indicator_snapshots)
|   - docs/product/mockups/screen-UC002-holding-list.html
|   - stock_auto_order-frontend-implementation-phase.md Phase 3
|
| App\Livewire\Holding\HoldingList does not exist yet (no class, no route,
| no Blade view). Every Livewire::test(HoldingList::class) call below is
| expected to fail with a "class not found" style fatal error, and the
| plain HTTP guest test is expected to fail because /holdings is not yet a
| registered route (currently only /api/holdings exists, per Phase 0's
| route split). That is the intended Red state, not a typo/setup bug.
|
| This file reuses App\Actions\Holding\ListHoldingsAction and
| App\Actions\Market\ShowMarketIndicatorAction unmodified (both are pure
| reads per the Phase 3 plan table, safe to call fresh on every render()).
| Fixture-building helpers below are a fresh copy (unique `holdingListTest`
| prefix, same convention as csvImportUploadTest* in
| tests/Feature/CsvImportUploadTest.php) of the ones already proven in
| tests/Feature/UC002HoldingListTest.php, to avoid cross-file function
| redeclaration errors while keeping fixture shapes consistent with that
| already-Green JSON API test.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag at Gate 4 review if a different contract is
| preferred):
|   - Route: GET /holdings, `auth` middleware, Livewire full-page
|     component (stock_auto_order-frontend-implementation-phase.md Phase 3
|     table + routes/web.php's existing `holdings` nav key).
|   - Public property names: $sector (string|null) and $signalOnly (bool),
|     bound via wire:model.live on a <select> and a checkbox respectively
|     (.claude/rules/15-frontend.md: low-frequency inputs, `.live` is fine).
|   - render() calls both Actions fresh every time and passes their raw
|     array results to the view (no intermediate DTO/ViewModel wrapping
|     assumed, but not asserted against directly here — this file only
|     asserts on rendered HTML, not on component public properties, so it
|     is agnostic to that internal detail).
|   - Numeric fields (quantity/average_cost/current_price/
|     unrealized_gain_rate/rsi/per/revenue_growth) are asserted only via
|     loose substring containment of the *unformatted* digits (e.g. "100"
|     for quantity=100), NOT an exact number_format()'d string — there is
|     no established currency/number formatting convention anywhere else
|     in this codebase yet (grepped resources/views and app/Actions,
|     nothing uses number_format() for these kinds of fields). If Green
|     adds thousands-separator formatting (e.g. "2,000"), these substring
|     assertions may need adjusting — flag at Gate 4 rather than silently
|     picking a format here.
|   - Market indicator widget: index_name -> Japanese label mapping is
|     nikkei225→日経平均, sp500→S&P500, us10y→米国10年債利回り, vix→VIX指数,
|     usdjpy→USD/JPY (mirrors the mockup's labels). us10y/vix/usdjpy are
|     always null per ShowMarketIndicatorAction's current implementation
|     (no fetch/save logic exists for them yet) and must render some
|     explicit "取得不可"-style placeholder rather than blank or the
|     literal string "null".
|   - "未分類" sector filter option: SectorClassification rows never
|     contain a "未分類" row (it's ListHoldingsAction's in-code fallback
|     label for holdings with sector_classification_id = null, per that
|     Action's docblock/data-model.md). This test file assumes the sector
|     <select> manually appends a "未分類" option (in addition to the
|     "すべて" default and the SectorClassification-backed options) so that
|     holdings with no sector classification can also be filtered to. This
|     is the single biggest unconfirmed/opinionated part of this test
|     file's contract — flag explicitly at Gate 4; the alternative (not
|     offering "未分類" as a filter option at all) is equally defensible
|     and use-cases.md does not decide between the two.
|   - NEW badge: renders as its own <a href="/candidate-check?symbol_code=
|     {symbol_code}"> element nested inside (but structurally distinct
|     from) the row's own /holdings/{id} link, so that a real browser click
|     with stopPropagation only triggers the badge's own navigation. This
|     test file can only assert the two hrefs both exist in the rendered
|     HTML (Livewire component tests do not execute JS click/stopPropagation
|     semantics) — the actual click-isolation behavior is left for the
|     `run` skill's manual browser check after Green, per
|     .claude/rules/30-testing.md.
|   - Row -> detail link: each stock/ETF... actually only stock rows are
|     clickable per UC-002 business rule (ETF/investment trusts are not).
|     The href target is `/holdings/{id}` where {id} is the `id` field
|     ListHoldingsAction returns (holdings.id, not holding_snapshots.id).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function holdingListTestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function holdingListTestHolding(array $attributes = []): Holding
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
function holdingListTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 2000,
        'current_price' => 2500,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 50000,
        'unrealized_gain_rate' => 25.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

function holdingListTestSectorClassification(string $name, ?string $code = null): SectorClassification
{
    return SectorClassification::create([
        'code' => $code,
        'name' => $name,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function holdingListTestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 65,
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
 * @param  array<string, mixed>  $attributes
 */
function holdingListTestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 24,
        'pbr' => null,
        'roe' => null,
        'revenue_growth' => 12,
        'operating_income_growth' => null,
        'equity_ratio' => null,
        'dividend_yield' => null,
        'dividend_payout_ratio' => null,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function holdingListTestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): Signal
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
function holdingListTestMarketIndicator(Snapshot $snapshot, string $indexName, array $attributes = []): MarketIndicatorSnapshot
{
    return MarketIndicatorSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'index_name' => $indexName,
        'value' => 39120,
        'change_rate' => 0.8,
        'ma_deviation' => 2.1,
    ], $attributes));
}

describe('UC-002/UC-007: 保有銘柄一覧画面（Livewire）', function () {
    describe('正常系: 保有銘柄一覧表示', function () {
        test('保有銘柄一覧が正しく表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingListTestImportBatch();

            $holding = holdingListTestHolding([
                'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
            ]);
            holdingListTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 100, 'average_cost' => 2000, 'current_price' => 2500, 'unrealized_gain_rate' => 25.0,
            ]);
            holdingListTestTechnicalIndicator($holding, ['rsi' => 65]);
            holdingListTestFundamentalIndicator($holding, ['per' => 24, 'revenue_growth' => 12]);

            $component = Livewire::actingAs($user)->test(HoldingList::class);

            $component->assertSee('トヨタ自動車');
            $component->assertSee('jp', false); // 市場区分（大文字/小文字はGreen実装依存のためignoreCase）
            $component->assertSee('7203');

            $html = $component->html();
            expect($html)->toContain('100'); // quantity
            expect($html)->toContain('2000'); // average_cost（生の小数キャスト値の一部として出現する想定）
            expect($html)->toContain('2500'); // current_price
            expect($html)->toContain('25'); // unrealized_gain_rate
            expect($html)->toContain('65'); // RSI代表値
            expect($html)->toContain('24'); // PER代表値
            expect($html)->toContain('12'); // 売上高成長率代表値
        });

        test('直近の週次スナップショットのみが表示対象となる', function () {
            $user = User::factory()->create();
            [, $oldSnapshot] = holdingListTestImportBatch(now()->subWeek());
            $holdingA = holdingListTestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            $holdingB = holdingListTestHolding(['symbol_code' => '9984', 'market' => 'jp', 'symbol_name' => 'ソフトバンクグループ']);
            holdingListTestHoldingSnapshot($oldSnapshot, $holdingA);
            holdingListTestHoldingSnapshot($oldSnapshot, $holdingB);

            [, $latestSnapshot] = holdingListTestImportBatch(now());
            holdingListTestHoldingSnapshot($latestSnapshot, $holdingA);

            $component = Livewire::actingAs($user)->test(HoldingList::class);

            $component->assertSee('トヨタ自動車');
            $component->assertDontSee('ソフトバンクグループ');
        });
    });

    describe('UC-007: 市場全体指標ウィジェット', function () {
        test('日経平均・S&P500は実際の値が表示され、米国10年債利回り・VIX指数・USD/JPYは取得不可のプレースホルダになる', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingListTestImportBatch();

            holdingListTestMarketIndicator($snapshot, 'nikkei225', ['value' => 39120, 'change_rate' => 0.8]);
            holdingListTestMarketIndicator($snapshot, 'sp500', ['value' => 5830, 'change_rate' => 0.3]);
            // us10y/vix/usdjpyはMarketIndicatorSnapshot行を意図的に作らない
            // （ShowMarketIndicatorActionが常にnullとして返す現状の仕様どおり）。

            $component = Livewire::actingAs($user)->test(HoldingList::class);

            $component->assertSee('日経平均');
            $component->assertSee('S&P500', false);
            $component->assertSee('米国10年債利回り');
            $component->assertSee('VIX指数');
            $component->assertSee('USD/JPY', false);

            $html = $component->html();
            expect($html)->toContain('39120');
            expect($html)->toContain('5830');

            // null指標は「取得不可」等の明示的なプレースホルダになり、
            // 空欄やリテラル文字列"null"にはならない。
            $component->assertSee('取得不可');
            expect($html)->not->toContain('>null<');
        });
    });

    describe('セクターフィルタ', function () {
        test('sectorをセットすると該当セクターの銘柄のみ表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingListTestImportBatch();

            $techSector = holdingListTestSectorClassification('情報・通信業', '5250');
            $autoSector = holdingListTestSectorClassification('輸送用機器', '3750');

            $holdingTech = holdingListTestHolding([
                'symbol_code' => '9432', 'market' => 'jp', 'symbol_name' => 'NTT',
                'sector_classification_id' => $techSector->id,
            ]);
            $holdingAuto = holdingListTestHolding([
                'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            holdingListTestHoldingSnapshot($snapshot, $holdingTech);
            holdingListTestHoldingSnapshot($snapshot, $holdingAuto);

            $component = Livewire::actingAs($user)->test(HoldingList::class)
                ->set('sector', '情報・通信業');

            $component->assertSee('NTT');
            $component->assertDontSee('トヨタ自動車');
        });

        test('未分類（Gate4要確認の前提）: sectorに「未分類」をセットするとsector_classification_idがnullの銘柄のみ表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingListTestImportBatch();

            $classifiedSector = holdingListTestSectorClassification('輸送用機器', '3750');
            $classifiedHolding = holdingListTestHolding([
                'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $classifiedSector->id,
            ]);
            $unclassifiedHolding = holdingListTestHolding([
                'symbol_code' => '1234', 'market' => 'jp', 'symbol_name' => '未分類テスト銘柄',
                'sector_classification_id' => null,
            ]);
            holdingListTestHoldingSnapshot($snapshot, $classifiedHolding);
            holdingListTestHoldingSnapshot($snapshot, $unclassifiedHolding);

            $component = Livewire::actingAs($user)->test(HoldingList::class)
                ->set('sector', '未分類');

            $component->assertSee('未分類テスト銘柄');
            $component->assertDontSee('トヨタ自動車');
        });
    });

    describe('シグナルのみ表示チェックボックス', function () {
        test('signalOnlyをtrueにするとhas_signal=trueの銘柄のみ表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingListTestImportBatch();

            $holdingWithSignal = holdingListTestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            $snapshotWithSignal = holdingListTestHoldingSnapshot($snapshot, $holdingWithSignal);
            holdingListTestSignal($snapshotWithSignal);

            $holdingWithoutSignal = holdingListTestHolding(['symbol_code' => '9984', 'market' => 'jp', 'symbol_name' => 'ソフトバンクグループ']);
            holdingListTestHoldingSnapshot($snapshot, $holdingWithoutSignal);

            $component = Livewire::actingAs($user)->test(HoldingList::class)
                ->set('signalOnly', true);

            $component->assertSee('トヨタ自動車');
            $component->assertDontSee('ソフトバンクグループ');
        });
    });

    describe('ETF・投資信託の扱い', function () {
        test('ETF・投資信託の行はクリック不可で主要指標が対象外表示になる', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingListTestImportBatch();

            $etfHolding = holdingListTestHolding([
                'symbol_code' => 'VTI', 'market' => 'us', 'instrument_type' => 'etf',
                'symbol_name' => 'Vanguard Total Stock Market ETF',
            ]);
            // 意図的にTechnicalIndicator/FundamentalIndicator/Signal行を作らない
            // （data-model.md: ETF/投資信託には指標行が作られない前提）。
            holdingListTestHoldingSnapshot($snapshot, $etfHolding, ['quantity' => 10, 'average_cost' => 200, 'current_price' => 250]);

            $component = Livewire::actingAs($user)->test(HoldingList::class);

            $component->assertSee('Vanguard Total Stock Market ETF');
            $component->assertSee('対象外');

            // ETF行は銘柄詳細（/holdings/{id}）へのリンクを持たない。
            $component->assertDontSeeHtml("href=\"/holdings/{$etfHolding->id}\"");
        });
    });

    describe('NEWバッジ', function () {
        test('is_newly_detected=trueの銘柄にNEWバッジが表示され、candidate-check画面へのリンクを持つ', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingListTestImportBatch();

            $newHolding = holdingListTestHolding([
                'symbol_code' => '4568', 'market' => 'jp', 'symbol_name' => 'サンプル新興半導体',
            ]);
            holdingListTestHoldingSnapshot($snapshot, $newHolding, ['is_newly_detected' => true]);

            $component = Livewire::actingAs($user)->test(HoldingList::class);

            $component->assertSee('NEW');
            $component->assertSeeHtml('href="/candidate-check?symbol_code=4568"');

            // NEWバッジがついた銘柄も、行自体は引き続き銘柄詳細（UC-003）への
            // リンクを保持する（バッジのクリックのみが例外的にUC-006へ遷移する
            // というUC-002業務ルール）。
            $component->assertSeeHtml("href=\"/holdings/{$newHolding->id}\"");
        });

        test('is_newly_detected=falseの銘柄にはNEWバッジが表示されない', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingListTestImportBatch();

            $holding = holdingListTestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            holdingListTestHoldingSnapshot($snapshot, $holding, ['is_newly_detected' => false]);

            $component = Livewire::actingAs($user)->test(HoldingList::class);

            $component->assertDontSee('NEW');
        });
    });

    describe('一覧行から詳細画面へのリンク', function () {
        test('保有銘柄一覧の行が/holdings/{id}へのリンクを持ち、idはActionが返すholdings.idと一致する', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingListTestImportBatch();

            $holding = holdingListTestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
            holdingListTestHoldingSnapshot($snapshot, $holding);

            $component = Livewire::actingAs($user)->test(HoldingList::class);

            $component->assertSeeHtml("href=\"/holdings/{$holding->id}\"");
        });
    });

    describe('空状態', function () {
        test('スナップショットが存在しない場合、一覧が空でもエラーにならない', function () {
            $user = User::factory()->create();

            $component = Livewire::actingAs($user)->test(HoldingList::class);

            // use-cases.md UC-002エラーケース: 「CSV取込が必要です」を表示し
            // UC-001への導線を示す。
            $component->assertSee('CSV取込が必要です');
            $component->assertSeeHtml('href="/csv-import"');
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは/holdingsにアクセスできない', function () {
            $this->get('/holdings')->assertRedirect('/login');
        });
    });
});
