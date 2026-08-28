<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateCheck;
use App\Models\FinancialStatement;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use App\Models\WatchedTheme;
use App\Models\WatchRecord;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UC-006 + UC-008: 新規投資候補（Livewireフルページ、統合画面） — Red phase
| Livewire Component Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-006 基本フロー・入力/出力表・業務ルール・
|     エラーケース、UC-008 基本フロー・出力表・業務ルール・エラーケース。
|     UC-006業務ルール「画面はUC-008と統合し、『新規投資候補』という単一の
|     メニュー項目の下部セクションとして提供する」/ UC-008業務ルール「表示
|     場所はUC-006と同一の『新規投資候補』画面の上部セクションとし、UC-008
|     専用の独立したメニュー項目は設けない」)
|   - docs/product/mockups/screen-UC006-candidate-check.html （統合画面の
|     モック。上部に「おすすめ候補（UC-008）」テーブル、下部に「個別銘柄を
|     チェック（UC-006）」フォーム→判定結果／ウォッチステータス・メモ／
|     テクニカル指標／ファンダメンタルズ指標／過去の業績推移の各カード）
|   - app/Actions/Candidate/ShowNewCandidateListAction.php （UC-008、既に
|     Green。execute(): array、副作用なし。各行
|     symbol_code/symbol_name/matched_theme/fundamental_summary/
|     suggested_amount/nisa_recommended/nisa_recommended_reason）
|   - app/Actions/Candidate/ShowCandidateCheckAction.php （UC-006、既に
|     Green。execute(Holding $holding): array、副作用なし。UC-003と同一の
|     テクニカル/ファンダメンタルズ指標一式 +
|     overlap_rate/diversification_comment/historical_performance/
|     watch_status/watch_memo_history）
|   - app/Actions/Candidate/SaveWatchRecordAction.php （UC-006、既に
|     Green。execute(Holding $holding, ?string $watchStatus,
|     ?string $watchMemo): WatchRecord、追記のみの副作用）
|   - app/Http/Requests/ShowCandidateCheckRequest.php /
|     SaveWatchRecordRequest.php （既存のJSON API `/api/candidate-check`・
|     `/api/candidate-check/watch-records`と同一のバリデーション規約を、
|     このLivewireコンポーネントのGate4確定契約としてそのまま流用する
|     — symbol_codeはholdingsテーブルに存在必須、watch_statusは
|     ['様子見','買い時','次回購入候補','リバランス対象']のいずれか、
|     watch_memoは最大2000文字、watch_status/watch_memoが両方空のPOSTは
|     拒否）
|   - tests/Feature/UC006CandidateCheckTest.php /
|     tests/Feature/UC008NewCandidateListTest.php （git show HEAD版。同じ
|     Actionを叩くJSON APIの既存Green Feature Test。fixture構築パターン
|     〔Holding/HoldingSnapshot/Snapshot/SectorClassification/
|     TechnicalIndicator/FundamentalIndicator/FinancialStatement/
|     WatchedTheme/WatchRecord/ImportBatch/User factory〕を踏襲する）
|   - tests/Feature/SectorDashboardTest.php （このアプリにおける直近の
|     Livewireフルページ・Feature Testの前例。fixture-helper命名規約
|     〔`sectorDashboardTest*`〕、Red状態の説明コメントの書式を踏襲する）
|   - 参照実装（いずれも既にGreen）: app/Livewire/Signal/SignalList.php +
|     resources/views/livewire/signal/signal-list.blade.php（純粋な参照専用、
|     render()のたびにActionを呼び直す規約）、
|     app/Livewire/Holding/HoldingDetail.php（保存系副作用を持つAction＋
|     wire:model.blurパターン）、app/Livewire/Sector/SectorDashboard.php
|     （同じ参照専用パターンの直近実装。resources/views/livewire/
|     sector/sector-dashboard.blade.php・
|     resources/views/livewire/holding/holding-list.blade.php が既に
|     `/candidate-check?symbol_code={code}` へのリンクを持っている
|     ことを確認済み — 本ファイルが検証するmount()自動チェック契約は
|     この既存リンクを受け止める側の契約）
|
| App\Livewire\Candidate\CandidateCheck does not exist yet (no class, no
| route, no Blade view). Every Livewire::test(CandidateCheck::class) call
| below is expected to fail with a Livewire\Exceptions\ComponentNotFoundException
| (or equivalent "component not found" fatal), and the plain HTTP guest test
| (`$this->get('/candidate-check')`) is expected to 404 because
| `GET /candidate-check` is not yet a registered web route — only
| `GET /api/candidate-check` (CandidateCheckController, JSON API) exists per
| Phase 0's route split (confirmed via routes/web.php). That is the intended
| Red state, same convention as every prior phase in this app
| (SignalListTest.php / SectorDashboardTest.php).
|
| This file reuses App\Actions\Candidate\ShowNewCandidateListAction /
| ShowCandidateCheckAction / SaveWatchRecordAction unmodified. It does NOT
| re-test their own calculation rules (overlap_rate/diversification_comment
| derivation, NewCandidateFinder's theme-matching/health-filter/suggested
| amount logic, watch record append-only semantics) — those are already
| covered by tests/Feature/UC006CandidateCheckTest.php and
| tests/Feature/UC008NewCandidateListTest.php against the JSON API. This file
| only verifies "the combined screen renders/wires what the Actions return"
| (same division of responsibility as SignalListTest.php vs.
| UC004SignalListTest.php — 30-testing.md CRUD網羅ルール／このタスクの指示に
| 従う).
|
| Fixture-building helpers below are a fresh copy (unique
| `candidateCheckTest` prefix, same convention as `sectorDashboardTest*` in
| tests/Feature/SectorDashboardTest.php and `ucFrom006CandidateTest*` /
| `ucFrom008CandidateTest*` in the existing JSON-API test files) to avoid
| cross-file function redeclaration errors while keeping fixture shapes
| consistent with the already-Green JSON API tests.
|
| Assumptions made while writing these tests (NOT yet confirmed by an
| implementation — flag prominently at Gate 4 review; a different contract
| may be chosen instead):
|
|   1. Route/query-string integration: other already-shipped screens
|      (SignalList, SectorDashboard, HoldingList's NEW badge) already link to
|      `/candidate-check?symbol_code={code}` expecting this screen to
|      pre-fill and auto-run the individual check when arriving via that
|      link. Assumes the Livewire component uses a
|      `#[Url(as: 'symbol_code')]`-bound public property `symbolCode`, and
|      `mount()` automatically runs the check if `symbolCode` is non-empty at
|      mount time. Tested via
|      `Livewire::withQueryParams(['symbol_code' => 'XXXX'])->test(CandidateCheck::class)`
|      immediately showing the check result without a separate method call.
|   2. Recommended-candidate row click behavior: per the mockup, clicking a
|      recommended-candidate row populates the check form's symbol_code via
|      Alpine.js (`$wire.symbolCode` + scrollIntoView) — a client-side JS
|      behavior that CANNOT be asserted via `Livewire::test()`. This file
|      does NOT attempt to assert the click/scroll itself; it only asserts
|      that the recommended-candidates table renders each candidate's data,
|      with a `data-symbol-code="XXXX"` attribute on the row for Alpine/JS to
|      target.
|   3. Manual check trigger: assumes a public method `checkCandidate()`
|      (invoked via `wire:submit` on the symbol_code form) rather than
|      auto-checking on every keystroke. Tested via
|      `->set('symbolCode', 'XXXX')->call('checkCandidate')`.
|   4. Unknown symbol_code: `checkCandidate()` with a symbol_code not present
|      in `holdings` sets a validation-style error message
|      "銘柄コードを確認してください" visible on the page (asserted via
|      assertSee), and does NOT crash / does NOT show any indicator data.
|   5. Watch record save: a public method `saveWatchRecord()` (invoked via
|      `wire:submit` on the watch-status form) calls SaveWatchRecordAction.
|      Both watchStatus/watchMemo blank -> validation error containing
|      "watch_statusまたはwatch_memoのいずれかを指定してください", no record
|      created. A successful save appends to watch_memo_history and the
|      newly-saved entry becomes visible on the page immediately (re-render
|      without a full page reload, same `->call()->assertSee()` pattern as
|      HoldingDetail's saveMemo()).
|   6. Empty recommended-candidates state: when
|      ShowNewCandidateListAction::execute() returns [] (e.g. no
|      WatchedTheme registered), shows empty-state text "おすすめ候補は
|      ありません" — consistent with this app's existing empty-state copy
|      convention (e.g. SectorDashboardTest.php's
|      "リバランス候補はありません"). Exact wording is a Gate4-adjustable
|      placeholder.
|   7. Unauthenticated access: `$this->get('/candidate-check')` -> redirect
|      to `/login` (302), consistent with every other Livewire full-page
|      screen in this app (HoldingList/SignalList/SectorDashboard).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function candidateCheckTestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function candidateCheckTestHolding(array $attributes = []): Holding
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
function candidateCheckTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
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

function candidateCheckTestSector(string $name): SectorClassification
{
    return SectorClassification::query()->firstOrCreate(['name' => $name]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function candidateCheckTestFundamental(Holding $holding, array $attributes = []): FundamentalIndicator
{
    return FundamentalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'per' => 18.0,
        'pbr' => 1.1,
        'roe' => 12.0,
        'revenue_growth' => 7.2,
        'equity_ratio' => 45.0,
        'dividend_yield' => 0.8,
        'eps_growth' => 9.4,
        'peg_ratio' => 1.9,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function candidateCheckTestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
{
    return TechnicalIndicator::create(array_merge([
        'holding_id' => $holding->id,
        'rsi' => 55.5,
        'macd' => 0.5432,
        'macd_signal' => 0.4321,
        'ma20' => 2400.0,
        'ma75' => 2300.0,
        'bb_upper' => 2600.0,
        'bb_lower' => 2200.0,
        'volume' => 1_100_000,
        'volume_ma20' => 900_000,
        'week52_high' => 2900.0,
        'week52_low' => 1800.0,
        'relative_strength_vs_market' => 2.1,
        'relative_strength_vs_sector' => -0.5,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function candidateCheckTestFinancialStatement(Holding $holding, array $attributes = []): FinancialStatement
{
    return FinancialStatement::create(array_merge([
        'holding_id' => $holding->id,
        'fiscal_period' => '2025Q4',
        'revenue' => 100_000_000.00,
        'operating_income' => 10_000_000.00,
        'eps' => 120.50,
        'revenue_yoy_change' => 5.5,
        'operating_income_yoy_change' => 3.2,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * `watch_records`/App\Models\WatchRecord already exist and are Green
 * (built for UC-006's JSON API in a prior cycle). Reused here unmodified.
 *
 * @param  array<string, mixed>  $attributes
 */
function candidateCheckTestWatchRecord(Holding $holding, array $attributes = []): WatchRecord
{
    return WatchRecord::create(array_merge([
        'holding_id' => $holding->id,
        'watch_status' => '様子見',
        'memo' => null,
        'recorded_at' => now(),
    ], $attributes));
}

describe('UC-006/UC-008: 新規投資候補（Livewire統合画面）', function () {
    describe('正常系: おすすめ候補一覧の表示（UC-008）', function () {
        test('複数の候補銘柄のsymbol_name/symbol_code/matched_theme/fundamental_summary/suggested_amountが表示される', function () {
            $user = User::factory()->create();
            WatchedTheme::create(['name' => 'AI半導体']);
            WatchedTheme::create(['name' => 'ヘルスケア']);

            [, $snapshot] = candidateCheckTestImportBatch();

            // 既存保有（ポートフォリオ評価額の基礎）。
            $heldStock = candidateCheckTestHolding(['symbol_code' => '9999', 'symbol_name' => '既存保有株']);
            candidateCheckTestHoldingSnapshot($snapshot, $heldStock, ['quantity' => 100, 'current_price' => 2000.00]);

            $semiconductorSector = candidateCheckTestSector('AI半導体');
            $candidateA = candidateCheckTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            candidateCheckTestFundamental($candidateA, ['equity_ratio' => 52.0, 'roe' => 14.5]);

            $healthcareSector = candidateCheckTestSector('ヘルスケア');
            $candidateB = candidateCheckTestHolding([
                'symbol_code' => '4568',
                'symbol_name' => 'サンプル製薬',
                'sector_classification_id' => $healthcareSector->id,
            ]);
            candidateCheckTestFundamental($candidateB, ['equity_ratio' => 65.0, 'roe' => 12.0]);

            $component = Livewire::actingAs($user)->test(CandidateCheck::class);

            $component->assertSee('レーザーテック');
            $component->assertSee('6920');
            $component->assertSee('AI半導体');
            $component->assertSee('サンプル製薬');
            $component->assertSee('4568');
            $component->assertSee('ヘルスケア');

            $html = $component->html();
            expect($html)->toContain('52'); // 候補Aの自己資本比率
            expect($html)->toContain('14.5'); // 候補AのROE
            expect($html)->toContain('65'); // 候補Bの自己資本比率

            // Alpine.js側が行クリックでsymbolCodeへ流し込むためのフック
            // (assumption 2)。クリック挙動自体はLivewireテストで検証不可。
            $component->assertSeeHtml('data-symbol-code="6920"');
            $component->assertSeeHtml('data-symbol-code="4568"');
        });
    });

    describe('おすすめ候補が0件の場合', function () {
        test('注目テーマが1件も登録されていない場合、空状態メッセージが表示される', function () {
            $user = User::factory()->create();

            $component = Livewire::actingAs($user)->test(CandidateCheck::class);

            $component->assertSee('おすすめ候補はありません');
        });
    });

    describe('個別銘柄チェック（UC-006）', function () {
        test('有効なsymbol_codeでcheckCandidate()を呼ぶと、overlap_rate・diversification_comment・テクニカル指標・ファンダメンタルズ指標・過去の業績推移が表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = candidateCheckTestImportBatch();

            // 半導体セクター: 100株×4,500円 = 450,000円 (45% -> やや偏り)。
            $semiconductorSector = candidateCheckTestSector('半導体');
            $target = candidateCheckTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $semiconductorSector->id,
            ]);
            candidateCheckTestHoldingSnapshot($snapshot, $target, ['quantity' => 100, 'current_price' => 4500.00]);

            // 自動車セクター: 300,000円 (30%)
            $autoSector = candidateCheckTestSector('自動車');
            $autoHolding = candidateCheckTestHolding([
                'symbol_code' => '7203',
                'symbol_name' => 'トヨタ自動車',
                'sector_classification_id' => $autoSector->id,
            ]);
            candidateCheckTestHoldingSnapshot($snapshot, $autoHolding, ['quantity' => 100, 'current_price' => 3000.00]);

            // 未分類: 250,000円 (25%)
            $unclassifiedHolding = candidateCheckTestHolding([
                'symbol_code' => '9999',
                'symbol_name' => 'その他保有株',
                'sector_classification_id' => null,
            ]);
            candidateCheckTestHoldingSnapshot($snapshot, $unclassifiedHolding, ['quantity' => 250, 'current_price' => 1000.00]);
            // Total = 450,000 + 300,000 + 250,000 = 1,000,000

            candidateCheckTestTechnicalIndicator($target, ['rsi' => 60.0]);
            candidateCheckTestFundamental($target, ['per' => 18.0]);

            candidateCheckTestFinancialStatement($target, [
                'fiscal_period' => '2025Q3',
                'revenue' => 90_000_000.00,
                'operating_income' => 8_000_000.00,
            ]);
            candidateCheckTestFinancialStatement($target, [
                'fiscal_period' => '2025Q4',
                'revenue' => 100_000_000.00,
                'operating_income' => 10_000_000.00,
            ]);

            $component = Livewire::actingAs($user)->test(CandidateCheck::class)
                ->set('symbolCode', '6920')
                ->call('checkCandidate');

            $component->assertSee('レーザーテック');
            $component->assertSee('半導体');

            $html = $component->html();
            // overlap_rate（重複度、45%前後）と分散影響コメントが表示される。
            expect($html)->toMatch('/45(\.0)?\s*%/');
            expect($html)->toContain('やや偏り');

            // テクニカル指標
            expect($html)->toMatch('/60(\.0)?/'); // RSI

            // ファンダメンタルズ指標
            expect($html)->toMatch('/18(\.0)?/'); // PER

            // 過去の業績推移（直近2期分）
            $component->assertSee('2025Q4');
            $component->assertSee('2025Q3');
            expect($html)->toContain('100,000,000');
            expect($html)->toContain('90,000,000');
        });

        test('存在しないsymbol_codeでcheckCandidate()を呼ぶとエラーメッセージが表示され指標は表示されない', function () {
            $user = User::factory()->create();

            $component = Livewire::actingAs($user)->test(CandidateCheck::class)
                ->set('symbolCode', '0000')
                ->call('checkCandidate');

            $component->assertSee('銘柄コードを確認してください');

            // 指標セクションが表示されていないこと（判定結果カード自体が
            // 出ない/中身が空である、のいずれの実装でも耐えられるよう、
            // ここでは典型的な指標ラベルが出ていないことのみ確認する）。
            $component->assertDontSee('過去の業績推移');
        });
    });

    describe('クエリパラメータ経由の自動チェック', function () {
        test('?symbol_code=XXXX付きでアクセスするとmount()時点で自動的にチェック結果が表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = candidateCheckTestImportBatch();

            $sector = candidateCheckTestSector('半導体');
            $target = candidateCheckTestHolding([
                'symbol_code' => '6920',
                'symbol_name' => 'レーザーテック',
                'sector_classification_id' => $sector->id,
            ]);
            candidateCheckTestHoldingSnapshot($snapshot, $target, ['quantity' => 100, 'current_price' => 1000.00]);
            candidateCheckTestTechnicalIndicator($target);
            candidateCheckTestFundamental($target);

            $component = Livewire::actingAs($user)
                ->withQueryParams(['symbol_code' => '6920'])
                ->test(CandidateCheck::class);

            // checkCandidate()を明示的に呼び出さなくても、mount()時点で
            // 自動的に判定結果が表示される（assumption 1）。
            $component->assertSee('レーザーテック');
            $component->assertSee('半導体');
        });
    });

    describe('ウォッチステータス・メモ保存', function () {
        test('正常系: 保存後に履歴へ即座に反映される', function () {
            $user = User::factory()->create();
            $candidate = candidateCheckTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
            ]);

            $component = Livewire::actingAs($user)->test(CandidateCheck::class)
                ->set('symbolCode', '4589')
                ->call('checkCandidate')
                ->set('watchStatus', '次回購入候補')
                ->set('watchMemo', '押し目が来たら次回検討')
                ->call('saveWatchRecord');

            $component->assertHasNoErrors();
            $component->assertSee('次回購入候補');
            $component->assertSee('押し目が来たら次回検討');

            expect(WatchRecord::where('holding_id', $candidate->id)->count())->toBe(1);
        });

        test('watch_status・watch_memoの両方が空の場合はバリデーションエラーになり保存されない', function () {
            $user = User::factory()->create();
            $candidate = candidateCheckTestHolding([
                'symbol_code' => '4589',
                'symbol_name' => 'オンコリスバイオファーマ',
            ]);

            $component = Livewire::actingAs($user)->test(CandidateCheck::class)
                ->set('symbolCode', '4589')
                ->call('checkCandidate')
                ->set('watchStatus', '')
                ->set('watchMemo', '')
                ->call('saveWatchRecord');

            $component->assertSee('watch_statusまたはwatch_memoのいずれかを指定してください');

            expect(WatchRecord::where('holding_id', $candidate->id)->count())->toBe(0);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは/candidate-checkへアクセスできない', function () {
            $this->get('/candidate-check')->assertRedirect('/login');
        });
    });
});
