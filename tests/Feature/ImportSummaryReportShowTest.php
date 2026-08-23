<?php

namespace Tests\Feature;

use App\Actions\ImportSummaryReport\ShowImportSummaryReportAction;
use App\Livewire\ImportSummaryReport\Show;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use App\Models\WatchedTheme;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UC-009: 取込後サマリーレポート画面（Livewireフルページ） — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-009)
|   - docs/architecture/data-model.md (import_summary_reports /
|     import_summary_report_items)
|   - stock_auto_order-frontend-implementation-phase.md Phase 2
|
| App\Livewire\ImportSummaryReport\Show does not exist yet (no class, no
| route, no Blade view). Every test in describe('UC-009: 取込後サマリー
| レポート画面（Livewire）') is expected to fail with a "class not found"
| style fatal error (or a 404 for the not-yet-registered
| /import-batches/{id}/summary-report route, for the two plain-HTTP tests).
| That is the intended Red state, not a typo/setup bug.
|
| Seed helper functions below (importSummaryReportShowTest*) are a verbatim
| duplicate (unique prefix to avoid cross-file redeclaration errors) of the
| equivalent helpers already proven in tests/Feature/UC009ImportSummaryReportTest.php,
| so Green behavior for the underlying ShowImportSummaryReportAction stays
| consistent with that already-approved contract. This file does not
| re-assert the Action's scoring/ranking edge cases (already covered there)
| — it only asserts that the Livewire screen correctly displays whatever
| the Action returns, calls the Action exactly once (mount()-only, per the
| task's side-effect note: the Action deletes+reinserts
| import_summary_report_items on every call), and wires up the UC-003/
| UC-005/UC-006 navigation links.
|
| Assumptions made while writing these tests (flag at Gate 4 if a different
| contract is preferred):
|   - Route: GET /import-batches/{importBatch}/summary-report, `auth`
|     middleware, route-model-bound on import_batches.id (mirrors the
|     existing JSON API's ImportSummaryReportController::show() route
|     model binding — a nonexistent id is expected to 404 automatically).
|     NOTE: because the route does not exist AT ALL yet in this Red state,
|     the "存在しない取込バッチIDへのアクセスは404になる" test below
|     coincidentally also returns 404 today (Laravel's default "no matching
|     route" response), for the WRONG reason. This must be re-verified once
|     Green work adds the route, to confirm the 404 then comes from route
|     model binding rejecting a bad id, not from the route being entirely
|     absent. Flagged explicitly here and in the completion report so this
|     particular test is not mistaken for a meaningful Red-phase failure.
|   - The Livewire component's mount(ImportBatch $importBatch) resolves
|     ShowImportSummaryReportAction via Laravel's container (method
|     injection), exactly like ImportSummaryReportController::show()
|     already does — this lets the "呼び出しは1回だけ" test below bind a
|     Mockery double via $this->mock() and have it picked up automatically.
|   - Temporary link shape (see the file's own header docblock note in the
|     task instructions this file was generated from): 利確検討/新規投資候補
|     rows link to "/holdings?symbol_code={symbol_code}" (query-param based,
|     since UC-003's own holding-detail screen isn't built until Phase 4
|     and the list screen that could resolve symbol_code→id isn't built
|     until Phase 3 either) and リバランス rows link to "/sector-dashboard"
|     (a single dashboard page, no per-sector route). This is explicitly
|     NOT a locked contract — confirm at Gate 4, and expect it to be
|     replaced with a proper "/holdings/{id}" link once Phase 3/4 exist.
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function importSummaryReportShowTestImportBatch(): array
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => now(),
    ]);

    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => now(),
    ]);

    return [$batch, $snapshot];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportShowTestHolding(array $attributes = []): Holding
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
function importSummaryReportShowTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 1000,
        'current_price' => 1000,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 0,
        'unrealized_gain_rate' => 0.0,
        'is_newly_detected' => false,
    ], $attributes));
}

function importSummaryReportShowTestSectorClassification(string $name, ?string $code = null): SectorClassification
{
    return SectorClassification::create(['code' => $code, 'name' => $name]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportShowTestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 70.0,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function importSummaryReportShowTestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 15.0,
        'pbr' => 1.5,
        'roe' => 8.0,
        'revenue_growth' => 5.0,
        'operating_income_growth' => 4.0,
        'equity_ratio' => 35.0,
        'dividend_yield' => 2.0,
        'dividend_payout_ratio' => 30.0,
        'fetched_at' => now(),
    ], $attributes));
}

function importSummaryReportShowTestWatchedTheme(string $name): WatchedTheme
{
    return WatchedTheme::create(['name' => $name]);
}

/**
 * Seed $count individually-qualifying 利確検討 candidates (含み益+20%超),
 * each in its own sector (mirrors
 * UC009ImportSummaryReportTest.php::ucFrom009TestSeedManyTakeProfitCandidates()),
 * used here only to push the total candidate count past 10 so the 11〜20位
 * supplementary section actually renders something.
 */
function importSummaryReportShowTestSeedManyTakeProfitCandidates(Snapshot $snapshot, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $sector = importSummaryReportShowTestSectorClassification("テストセクター{$i}", sprintf('%03d', $i));
        $holding = importSummaryReportShowTestHolding([
            'symbol_code' => sprintf('90%02d', $i),
            'market' => 'jp',
            'symbol_name' => "テスト銘柄{$i}",
            'sector_classification_id' => $sector->id,
        ]);

        $gainRate = 21.0 + $i;
        $averageCost = 1000.0;
        $currentPrice = $averageCost * (1 + $gainRate / 100);

        importSummaryReportShowTestHoldingSnapshot($snapshot, $holding, [
            'quantity' => 10,
            'average_cost' => $averageCost,
            'current_price' => $currentPrice,
            'unrealized_gain_amount' => ($currentPrice - $averageCost) * 10,
            'unrealized_gain_rate' => $gainRate,
        ]);

        importSummaryReportShowTestTechnicalIndicator($holding, ['rsi' => 60.0 + $i]);
    }
}

/**
 * Seed one scenario containing all three recommendation types at once
 * (利確検討 / リバランス / 新規投資候補), reusing the same numbers as
 * UC009ImportSummaryReportTest.php's individual per-type tests so the
 * expected composite behavior (sector allocation crossing 70%, financial
 * health filter passing) is already a proven combination.
 *
 * @return array{take_profit_symbol: string, rebalance_sector: string, new_candidate_symbol: string}
 */
function importSummaryReportShowTestSeedAllThreeTypes(Snapshot $snapshot): array
{
    // 利確検討: 含み益+30%・RSI75
    $takeProfitSector = importSummaryReportShowTestSectorClassification('サンプルセクター1', 'TP1');
    $takeProfitHolding = importSummaryReportShowTestHolding([
        'symbol_code' => '1111', 'market' => 'jp', 'symbol_name' => '利確対象銘柄',
        'sector_classification_id' => $takeProfitSector->id,
    ]);
    importSummaryReportShowTestHoldingSnapshot($snapshot, $takeProfitHolding, [
        'average_cost' => 1000.0, 'current_price' => 1300.0,
        'unrealized_gain_amount' => 3000.0, 'unrealized_gain_rate' => 30.0,
    ]);
    importSummaryReportShowTestTechnicalIndicator($takeProfitHolding, ['rsi' => 75.0]);

    // リバランス: 電気機器セクターへの偏り90%（UC009ImportSummaryReportTest.phpと同じ数値）
    $overweightSector = importSummaryReportShowTestSectorClassification('電気機器', '3650');
    $otherSector = importSummaryReportShowTestSectorClassification('輸送用機器', '3750');

    foreach (['9001', '9002', '9003'] as $code) {
        $holding = importSummaryReportShowTestHolding([
            'symbol_code' => $code, 'market' => 'jp', 'symbol_name' => "偏りテスト銘柄{$code}",
            'sector_classification_id' => $overweightSector->id,
        ]);
        importSummaryReportShowTestHoldingSnapshot($snapshot, $holding, [
            'quantity' => 1000, 'average_cost' => 2910.0, 'current_price' => 3000.0,
            'unrealized_gain_amount' => 90000.0, 'unrealized_gain_rate' => 3.0,
        ]);
    }

    $balancingHolding = importSummaryReportShowTestHolding([
        'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
        'sector_classification_id' => $otherSector->id,
    ]);
    importSummaryReportShowTestHoldingSnapshot($snapshot, $balancingHolding, [
        'quantity' => 1000, 'average_cost' => 970.0, 'current_price' => 1000.0,
        'unrealized_gain_amount' => 30000.0, 'unrealized_gain_rate' => 3.0,
    ]);

    // 新規投資候補: 注目テーマ「AI半導体」合致・財務健全性フィルタ通過
    importSummaryReportShowTestWatchedTheme('AI半導体');
    $themeSector = importSummaryReportShowTestSectorClassification('AI半導体', '9999');
    $candidateHolding = importSummaryReportShowTestHolding([
        'symbol_code' => '6920', 'market' => 'jp', 'symbol_name' => 'レーザーテック',
        'sector_classification_id' => $themeSector->id,
    ]);
    importSummaryReportShowTestFundamentalIndicator($candidateHolding, [
        'equity_ratio' => 60.0,
        'roe' => 15.0,
    ]);

    return [
        'take_profit_symbol' => '1111',
        'rebalance_sector' => '電気機器',
        'new_candidate_symbol' => '6920',
    ];
}

describe('UC-009: 取込後サマリーレポート画面（Livewire）', function () {
    describe('正常系', function () {
        test('headline・上位10件（利確検討・リバランス・新規投資候補を含む）が正しく表示される', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            importSummaryReportShowTestSeedAllThreeTypes($snapshot);

            $component = Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch]);

            $component->assertSee('利確対象銘柄');
            $component->assertSee('1111');
            $component->assertSee('電気機器');
            $component->assertSee('レーザーテック');
            $component->assertSee('6920');

            // x-badgeコンポーネントの想定バリアント文言（Phase0で正式化されたinfoバリアントを含む）
            $component->assertSee('利確検討');
            $component->assertSee('リバランス');
            $component->assertSee('新規投資候補');

            // portfolio_headline は空文字であってはならない（UC-009業務ルール）
            $report = app(ShowImportSummaryReportAction::class)->execute($batch->fresh());
            expect(trim((string) $report['portfolio_headline']))->not->toBe('');
        });

        test('候補が11件以上ある場合11〜20位の補足レコメンドセクションにも項目が表示される', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            importSummaryReportShowTestSeedManyTakeProfitCandidates($snapshot, 15);

            // 15件中、含み益率が最も低い（優先度が最も低い）候補群が11〜20位の
            // 補足レコメンドに回る想定（rankはcomposite_score降順）。
            // i=0のテスト銘柄90"00"（gainRate 21%）が最も優先度が低いため
            // 補足レコメンド側に表示されるはず。
            Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch])
                ->assertSee('9000') // 最も優先度が低い候補のsymbol_code
                ->assertSee('テスト銘柄0');
        });
    });

    describe('0件時', function () {
        test('該当バッチにおすすめ候補が無い場合は空状態が表示される', function () {
            $user = User::factory()->create();
            [$batch] = importSummaryReportShowTestImportBatch();
            // Deliberately no Holding/HoldingSnapshot rows created at all.

            Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch])
                ->assertSee('現時点でおすすめできる項目はありません');
        });
    });

    describe('異常系・境界値', function () {
        test('存在しない取込バッチIDを指定した場合は404になる', function () {
            $user = User::factory()->create();

            // NOTE: coincidentally already 404s today because the route
            // itself does not exist yet — see file-level docblock caveat.
            // Re-verify once the route/route-model-binding is added.
            $this->actingAs($user)->get('/import-batches/999999/summary-report')->assertStatus(404);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは取込後サマリーレポート画面にアクセスできない', function () {
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            $holding = importSummaryReportShowTestHolding();
            importSummaryReportShowTestHoldingSnapshot($snapshot, $holding, [
                'unrealized_gain_amount' => 3000.0,
                'unrealized_gain_rate' => 30.0,
            ]);

            $this->get("/import-batches/{$batch->id}/summary-report")->assertRedirect('/login');
        });
    });

    describe('副作用（GETで再集計・書き込みが走るAction）', function () {
        test('ShowImportSummaryReportActionはmount時に1回だけ呼び出される', function () {
            $user = User::factory()->create();
            [$batch] = importSummaryReportShowTestImportBatch();

            $fakeResult = [
                'portfolio_headline' => 'テスト用ヘッドライン',
                'generated_at' => now(),
                'top_recommendations' => [],
                'supplementary_recommendations' => [],
            ];

            $this->mock(ShowImportSummaryReportAction::class, function ($mock) use ($fakeResult) {
                $mock->shouldReceive('execute')->once()->andReturn($fakeResult);
            });

            Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch])
                ->assertSee('テスト用ヘッドライン');

            // Mockery::once()の検証（未実装クラスのためこの行に到達する前に
            // 「class not found」でRedになる想定 — Show作成後も、mount()以外
            // （render()等）でexecute()を再度呼んでいれば
            // "should be called exactly 1 times but called 2 times"で
            // Redのままになる）。
        });
    });

    describe('リンクhref（暫定仕様、Gate4確認事項）', function () {
        test('利確検討・新規投資候補の行は/holdings?symbol_code={symbol_code}へのリンクを持つ', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            importSummaryReportShowTestSeedAllThreeTypes($snapshot);

            $component = Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch]);

            $component->assertSeeHtml('href="/holdings?symbol_code=1111"'); // 利確検討
            $component->assertSeeHtml('href="/holdings?symbol_code=6920"'); // 新規投資候補
        });

        test('リバランスの行は/sector-dashboardへのリンクを持つ', function () {
            $user = User::factory()->create();
            [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
            importSummaryReportShowTestSeedAllThreeTypes($snapshot);

            Livewire::actingAs($user)->test(Show::class, ['importBatch' => $batch])
                ->assertSeeHtml('href="/sector-dashboard"');
        });
    });
});

/*
|--------------------------------------------------------------------------
| symbol_code フィールド追加（Green phase予定） — backend Action assertion
|--------------------------------------------------------------------------
|
| ShowImportSummaryReportAction::toResponseItem() currently omits a stable
| identifier for 利確検討/新規投資候補 items ($item['target'] is only the
| display string "{symbol_code} {symbol_name}"). The Show screen above
| needs a bare symbol_code to build its temporary "/holdings?symbol_code=..."
| link (see file-level docblock). This calls the Action directly (not
| through Livewire/HTTP) so the failure reason is isolated to "the array key
| doesn't exist / isn't correct" rather than entangled with the Livewire
| class not existing yet.
|
*/
describe('ShowImportSummaryReportAction: symbol_codeフィールド追加（Green phase予定、Gate4確認事項）', function () {
    test('利確検討レコメンド項目にsymbol_codeが含まれる', function () {
        [$batch, $snapshot] = importSummaryReportShowTestImportBatch();
        $holding = importSummaryReportShowTestHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
        importSummaryReportShowTestHoldingSnapshot($snapshot, $holding, [
            'average_cost' => 1000.0, 'current_price' => 1300.0,
            'unrealized_gain_amount' => 3000.0, 'unrealized_gain_rate' => 30.0,
        ]);
        importSummaryReportShowTestTechnicalIndicator($holding, ['rsi' => 75.0]);

        $data = app(ShowImportSummaryReportAction::class)->execute($batch);

        $item = collect($data['top_recommendations'])->firstWhere('recommendation_type', '利確検討');
        expect($item)->not->toBeNull();
        expect($item['symbol_code'] ?? null)->toBe('7203');
    });

    test('新規投資候補レコメンド項目にsymbol_codeが含まれる', function () {
        [$batch, $snapshot] = importSummaryReportShowTestImportBatch();

        // 保有銘柄が0件だと新規投資候補判定のロジック自体には影響しないが、
        // UC-009の前提条件（保有銘柄が存在する）に合わせ、軽く1件保有させる。
        $heldHolding = importSummaryReportShowTestHolding(['symbol_code' => '9432', 'market' => 'jp', 'symbol_name' => 'NTT']);
        importSummaryReportShowTestHoldingSnapshot($snapshot, $heldHolding, [
            'unrealized_gain_amount' => 500.0,
            'unrealized_gain_rate' => 5.0,
        ]);

        importSummaryReportShowTestWatchedTheme('AI半導体');
        $themeSector = importSummaryReportShowTestSectorClassification('AI半導体', '9999');
        $candidateHolding = importSummaryReportShowTestHolding([
            'symbol_code' => '6920', 'market' => 'jp', 'symbol_name' => 'レーザーテック',
            'sector_classification_id' => $themeSector->id,
        ]);
        importSummaryReportShowTestFundamentalIndicator($candidateHolding, [
            'equity_ratio' => 60.0,
            'roe' => 15.0,
        ]);

        $data = app(ShowImportSummaryReportAction::class)->execute($batch);

        $allItems = collect($data['top_recommendations'])->merge($data['supplementary_recommendations']);
        $item = $allItems->firstWhere('recommendation_type', '新規投資候補');
        expect($item)->not->toBeNull();
        expect($item['symbol_code'] ?? null)->toBe('6920');
    });

    test('リバランスレコメンド項目にはsymbol_codeが含まれない', function () {
        // NOTE: this assertion already passes today (the key has never
        // existed on any item, リバランス included), since
        // toResponseItem() adds no fields at all yet. It is not a
        // Red-phase failure — it is included as an explicit regression
        // guard so that once Green work adds 'symbol_code' to the 利確検討/
        // 新規投資候補 branches, a reviewer can confirm the リバランス branch
        // was deliberately left out rather than accidentally also gaining
        // the field. Flagged in the completion report.
        [$batch, $snapshot] = importSummaryReportShowTestImportBatch();

        $overweightSector = importSummaryReportShowTestSectorClassification('電気機器', '3650');
        $otherSector = importSummaryReportShowTestSectorClassification('輸送用機器', '3750');

        foreach (['9001', '9002', '9003'] as $code) {
            $holding = importSummaryReportShowTestHolding([
                'symbol_code' => $code, 'market' => 'jp', 'symbol_name' => "偏りテスト銘柄{$code}",
                'sector_classification_id' => $overweightSector->id,
            ]);
            importSummaryReportShowTestHoldingSnapshot($snapshot, $holding, [
                'quantity' => 1000, 'average_cost' => 2910.0, 'current_price' => 3000.0,
                'unrealized_gain_amount' => 90000.0, 'unrealized_gain_rate' => 3.0,
            ]);
        }

        $balancingHolding = importSummaryReportShowTestHolding([
            'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
            'sector_classification_id' => $otherSector->id,
        ]);
        importSummaryReportShowTestHoldingSnapshot($snapshot, $balancingHolding, [
            'quantity' => 1000, 'average_cost' => 970.0, 'current_price' => 1000.0,
            'unrealized_gain_amount' => 30000.0, 'unrealized_gain_rate' => 3.0,
        ]);

        $data = app(ShowImportSummaryReportAction::class)->execute($batch);

        $allItems = collect($data['top_recommendations'])->merge($data['supplementary_recommendations']);
        $rebalanceItem = $allItems->firstWhere('recommendation_type', 'リバランス');
        expect($rebalanceItem)->not->toBeNull();
        expect($rebalanceItem['symbol_code'] ?? null)->toBeNull();
    });
});
