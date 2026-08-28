<?php

namespace Tests\Feature;

use App\Livewire\Holding\HoldingDetail;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingMemo;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Signal;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UC-003: 銘柄詳細画面（Livewireフルページ） — Red phase Livewire Component Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-003)
|   - docs/architecture/data-model.md (holdings / holding_snapshots /
|     snapshots / technical_indicators / fundamental_indicators / signals /
|     holding_memos)
|   - docs/product/mockups/screen-UC003-holding-detail.html (STALE — layout
|     only, does not reflect ADR-0004's volume/week52_high/week52_low/
|     relative_strength (vs market, vs sector)/eps_growth/peg_ratio additions)
|   - stock_auto_order-frontend-implementation-phase.md Phase 4
|   - tests/Feature/UC003HoldingDetailTest.php (already-Green JSON API test,
|     now at /api/holdings/{holding} — reused here as the fixture-building
|     pattern, NOT re-asserted against directly)
|
| App\Livewire\Holding\HoldingDetail does not exist yet (no class, no route,
| no Blade view). Every Livewire::test(HoldingDetail::class, ...) call below
| is expected to fail with a "class not found" style fatal error, and the
| plain HTTP guest/404 tests are expected to fail because GET /holdings/{id}
| is not yet a registered page route (only /api/holdings/{id} exists per
| Phase 0's route split, and /holdings itself — the list — already exists
| per Phase 3, but /holdings/{holding} does not). That is the intended Red
| state, not a typo/setup bug.
|
| This file reuses App\Actions\Holding\ShowHoldingDetailAction and
| App\Actions\Holding\SaveHoldingMemoAction unmodified (both already Green,
| the read Action has no side effects and is safe to call fresh on every
| render()). Fixture-building helpers below are a fresh copy (unique
| `holdingDetailTest` prefix, same convention as `holdingListTest*` in
| tests/Feature/HoldingListTest.php) of the ones already proven in
| tests/Feature/UC003HoldingDetailTest.php, to avoid cross-file function
| redeclaration errors while keeping fixture shapes consistent with that
| already-Green JSON API test.
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag at Gate 4 review if a different contract is
| preferred):
|   - Route: GET /holdings/{holding}, `auth` middleware, route-model-bound
|     on holdings.id, Livewire full-page component (mirrors the existing
|     JSON API's HoldingDetailController::show() route model binding — a
|     nonexistent id is expected to 404 automatically, and mirrors
|     HoldingList's row links which already point at this exact path).
|   - mount(Holding $holding) stores the holding as a public property;
|     render() calls ShowHoldingDetailAction::execute($this->holding,
|     $this->chartPeriod) fresh every time (pure read, same "call on every
|     render" convention as HoldingList's ListHoldingsAction/
|     ShowMarketIndicatorAction calls — unlike ImportSummaryReport\Show's
|     mount()-only call, since that Action has a write side effect and this
|     one does not).
|   - Public property name: $chartPeriod (string|null), default '3y',
|     settable directly via Livewire::set() regardless of how the period
|     toggle buttons internally set it (wire:click="$set('chartPeriod',
|     '1y')" vs. a dedicated method — this file only asserts on the
|     property's effect on rendered output, not on how the buttons wire up
|     internally, so it is agnostic to that detail). The four period toggle
|     button labels (1年/3年/5年/10年) are asserted to be present in the
|     rendered HTML, mirroring the mockup's segmented-control labels.
|   - Chart data-testability (the single biggest open question for Gate 4
|     review, per the task's own note that hand-rolled SVG markup should
|     not be asserted on pixel-precisely): this file assumes the component
|     renders one `data-testid="price-chart-point"` marker (e.g. a <circle>
|     or similar per-point element) per `price_history` entry, IN ADDITION
|     TO whatever polyline(s) it draws for the actual visual line — purely
|     as a test seam, so Red/Green can verify "the right number of data
|     points reached the chart" without asserting on pixel coordinates. This
|     is a new, not-yet-existing contract (the mockup's hand-written SVG has
|     no such attribute) — please confirm or propose an alternative (e.g.
|     asserting on <polyline points="..."> coordinate-pair counts, or on the
|     rendered aria-label text) at Gate 4. Separately, this file also
|     asserts the chart svg has `role="img"` and a non-empty `aria-label`
|     (content not pinned), mirroring the mockup's accessible chart pattern.
|   - "現在値" ("current value") stat box: ShowHoldingDetailAction does NOT
|     return a dedicated current_price/current_value field (only
|     average_cost). This file therefore only asserts on average_cost and
|     on the last price_history entry's close_price value being visible
|     somewhere in the rendered page (as the de-facto "current value"), and
|     does NOT assert on any invented field name or a specific stat-box
|     label — flag at Gate 4 whether a "現在値" stat box (sourced from the
|     last price_history entry) should be added, or whether the mockup's
|     three-stat-box row (取得単価/現在値/含み益率) should be reduced to what
|     the Action actually provides.
|   - Memo form: public string $newMemoBody = '' bound via
|     wire:model.blur (.claude/rules/15-frontend.md high-frequency-input
|     rule), saved via a `saveMemo()` public method that calls
|     SaveHoldingMemoAction and then resets $newMemoBody to ''. Validation
|     mirrors SaveHoldingMemoRequest (`required|string|max:2000`, custom
|     max message 'メモは2000文字以内で入力してください') but keyed on the
|     component's own property name (`newMemoBody`), NOT on `memo` — since
|     a Livewire component's rules() array is independent of the FormRequest
|     used by the JSON API sibling endpoint. This property/method naming is
|     a guess (same convention/risk as HoldingList's $sector/$signalOnly
|     assumption) — flag at Gate 4 if different names are preferred.
|   - memo_history ordering: rendered newest-first (matches
|     ShowHoldingDetailAction's orderByDesc('recorded_at')), verified here
|     via relative string position in the rendered HTML rather than a
|     structured assertion (Livewire component tests only expose raw HTML).
|
*/

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function holdingDetailTestImportBatch(?\DateTimeInterface $snapshottedAt = null): array
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
function holdingDetailTestHolding(array $attributes = []): Holding
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
function holdingDetailTestHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 300,
        'average_cost' => 2000,
        'current_price' => 2500,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 150000,
        'unrealized_gain_rate' => 25.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function holdingDetailTestTechnicalIndicator(Holding $holding, array $attributes = []): TechnicalIndicator
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
        'volume' => 1_200_000,
        'volume_ma20' => 950_000,
        'week52_high' => 2900.0,
        'week52_low' => 1800.0,
        'relative_strength_vs_market' => 3.25,
        'relative_strength_vs_sector' => -1.5,
        'computed_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function holdingDetailTestFundamentalIndicator(Holding $holding, array $attributes = []): FundamentalIndicator
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
        'eps_growth' => 12.4,
        'peg_ratio' => 1.23,
        'fetched_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function holdingDetailTestSignal(HoldingSnapshot $holdingSnapshot, array $attributes = []): Signal
{
    return Signal::create(array_merge([
        'holding_snapshot_id' => $holdingSnapshot->id,
        'signal_type' => 'rsi_reversal',
        'reason_summary' => 'RSIが72から65に反落',
    ], $attributes));
}

function holdingDetailTestMemo(Holding $holding, string $body, ?\DateTimeInterface $recordedAt = null): HoldingMemo
{
    return HoldingMemo::create([
        'holding_id' => $holding->id,
        'body' => $body,
        'recorded_at' => $recordedAt ?? now(),
    ]);
}

describe('UC-003: 銘柄詳細画面（Livewire）', function () {
    describe('正常系: 銘柄詳細表示', function () {
        test('銘柄詳細が正しく表示される（基本情報・テクニカル指標一式・ファンダメンタルズ指標一式）', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();

            $holding = holdingDetailTestHolding([
                'symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車',
            ]);
            holdingDetailTestHoldingSnapshot($snapshot, $holding, [
                'average_cost' => 2000.0, 'current_price' => 2500.0,
            ]);
            holdingDetailTestTechnicalIndicator($holding);
            holdingDetailTestFundamentalIndicator($holding);

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->assertSee('トヨタ自動車');
            $component->assertSee('7203');

            $html = $component->html();

            // average_cost / 現在値（価格ルール: number_format($value, 2) 相当。
            // カンマ区切り + 小数点2桁。DBのdecimal(15,2)キャスト由来の
            // "2000.00"/"2500.00" がそのまま出ている現状は非対応。
            expect($html)->toContain('2,000.00'); // average_cost
            expect($html)->toContain('2,500.00'); // 現在値（price_historyの最終close_price）

            // テクニカル指標一式（既存項目 + ADR-0004追加分）
            // RSI/MACD: 無単位指標値ルール（RSIは小数点1桁・MACDは小数点2桁）。
            // decimalキャスト由来の生値 "65.50"/"1.2345" は素朴なtoContainだと
            // フォーマット後の"65.5"/"1.23"を部分文字列として偶然含んでしまう
            // ため、<dd>タグ単位の完全一致で検証する。
            expect($html)->toContain('<dd>65.5</dd>'); // rsi（65.50 → 65.5）
            expect($html)->toContain('<dd>1.23</dd>'); // macd（1.2345 → 1.23）

            // 価格ルール（カンマ区切り + 小数点2桁）
            expect($html)->toContain('2,600.00'); // bb_upper
            expect($html)->toContain('2,200.00'); // bb_lower
            expect($html)->toContain('2,400.00'); // ma20
            expect($html)->toContain('2,300.00'); // ma75
            expect($html)->toContain('2,900.00'); // week52_high
            expect($html)->toContain('1,800.00'); // week52_low

            // 出来高ルール（カンマ区切り・小数点なし。number_format($value, 0)相当）
            expect($html)->toContain('1,200,000'); // volume
            expect($html)->toContain('950,000'); // volume_ma20（950000.00 → 四捨五入して950,000）

            // 相対強度（符号なしパーセントルール: 小数点1桁+%、符号は値のまま）
            expect($html)->toContain('3.3%'); // relative_strength_vs_market（3.2500 → 3.3%）
            expect($html)->toContain('-1.5%'); // relative_strength_vs_sector

            // ファンダメンタルズ指標一式（既存項目 + ADR-0004追加分）
            // PER/PBR/PEGレシオ: 無単位指標値ルール。rsi/macdと同じ理由で
            // <dd>タグ単位の完全一致で検証する。
            expect($html)->toContain('<dd>15.2</dd>'); // per（15.20 → 15.2）
            expect($html)->toContain('<dd>1.3</dd>'); // pbr（1.30 → 1.3）
            expect($html)->toContain('<dd>1.23</dd>'); // peg_ratio（1.2300 → 1.23）

            // 符号なしパーセントルール（小数点1桁+%、符号は値のまま強制付与しない）
            expect($html)->toContain('12.5%'); // roe
            expect($html)->toContain('8.4%'); // revenue_growth（成長率系: 既存の符号をそのまま活かす）
            expect($html)->toContain('55.0%'); // equity_ratio
            expect($html)->toContain('2.1%'); // dividend_yield
            expect($html)->toContain('12.4%'); // eps_growth（成長率系: 既存の符号をそのまま活かす）
        });

        test('指標が取得不可（null）の場合、該当項目が明示的な「取得不可」表示になる', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            holdingDetailTestHoldingSnapshot($snapshot, $holding);
            // TechnicalIndicator/FundamentalIndicatorの行を意図的に作らない。

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->assertSee('取得不可');

            $html = $component->html();
            expect($html)->not->toContain('>null<');
        });
    });

    describe('株価推移チャート', function () {
        test('price_historyのデータ点数がチャートに反映される', function () {
            $user = User::factory()->create();
            $holding = holdingDetailTestHolding();

            [, $week1] = holdingDetailTestImportBatch(now()->subWeeks(2));
            holdingDetailTestHoldingSnapshot($week1, $holding, ['current_price' => 2400.0, 'ma20' => 2350.0, 'ma75' => 2300.0]);

            [, $week2] = holdingDetailTestImportBatch(now()->subWeek());
            holdingDetailTestHoldingSnapshot($week2, $holding, ['current_price' => 2450.0, 'ma20' => 2380.0, 'ma75' => 2310.0]);

            [, $week3] = holdingDetailTestImportBatch(now());
            holdingDetailTestHoldingSnapshot($week3, $holding, ['current_price' => 2500.0, 'ma20' => 2400.0, 'ma75' => 2320.0]);

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->assertSeeHtml('role="img"');

            $html = $component->html();
            expect(substr_count($html, 'data-testid="price-chart-point"'))->toBe(3);
        });

        test('期間トグルでchartPeriodを変更すると表示件数が変わる（1年切り替えで直近1年分のみになる）', function () {
            $user = User::factory()->create();
            $holding = holdingDetailTestHolding();

            [, $oldSnapshot] = holdingDetailTestImportBatch(now()->subYears(2));
            holdingDetailTestHoldingSnapshot($oldSnapshot, $holding, ['current_price' => 2000.0]);

            [, $recentSnapshot] = holdingDetailTestImportBatch(now()->subMonths(6));
            holdingDetailTestHoldingSnapshot($recentSnapshot, $holding, ['current_price' => 2600.0]);

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            // デフォルト（3y）では両方の週次スナップショットが含まれる。
            expect(substr_count($component->html(), 'data-testid="price-chart-point"'))->toBe(2);

            $component->set('chartPeriod', '1y');

            // 1yに切り替えると2年前のスナップショットが除外され1件のみになる。
            expect(substr_count($component->html(), 'data-testid="price-chart-point"'))->toBe(1);
        });

        test('期間トグルのボタン（1年/3年/5年/10年）が表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            holdingDetailTestHoldingSnapshot($snapshot, $holding);

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->assertSee('1年');
            $component->assertSee('3年');
            $component->assertSee('5年');
            $component->assertSee('10年');
        });
    });

    describe('利確シグナル判定', function () {
        test('signalが存在する銘柄はsignal_result/signal_reasonが表示される（利確検討あり）', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            $holdingSnapshot = holdingDetailTestHoldingSnapshot($snapshot, $holding);
            holdingDetailTestSignal($holdingSnapshot, ['reason_summary' => 'RSIが72から65に反落']);

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->assertSee('利確検討');
            $component->assertSee('RSIが72から65に反落');
        });

        test('signalが存在しない銘柄はシグナルなしの内容が表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            holdingDetailTestHoldingSnapshot($snapshot, $holding);
            // Signal行を意図的に作らない。

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->assertSee('シグナルなし');
        });
    });

    describe('メモ', function () {
        test('既存のmemo_historyが新しい順に表示される', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            holdingDetailTestHoldingSnapshot($snapshot, $holding);
            holdingDetailTestMemo($holding, '古いメモ: 決算好調、しばらく保有継続', now()->subDays(3));
            holdingDetailTestMemo($holding, '新しいメモ: 含み益+15%到達、様子見継続', now()->subDay());

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->assertSee('古いメモ: 決算好調、しばらく保有継続');
            $component->assertSee('新しいメモ: 含み益+15%到達、様子見継続');

            $html = $component->html();
            $newPos = strpos($html, '新しいメモ: 含み益+15%到達、様子見継続');
            $oldPos = strpos($html, '古いメモ: 決算好調、しばらく保有継続');
            expect($newPos)->not->toBeFalse();
            expect($oldPos)->not->toBeFalse();
            expect($newPos)->toBeLessThan($oldPos);
        });

        test('新規メモを保存すると永続化され履歴に追加される（過去のメモは残る）', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            holdingDetailTestHoldingSnapshot($snapshot, $holding);
            holdingDetailTestMemo($holding, '既存メモ', now()->subDay());

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->set('newMemoBody', 'LLMとの壁打ち内容の転記テスト')
                ->call('saveMemo');

            $component->assertSee('LLMとの壁打ち内容の転記テスト');
            $component->assertSee('既存メモ'); // 過去のメモは編集・削除されず残る

            expect(HoldingMemo::where('holding_id', $holding->id)->count())->toBe(2);
            expect($component->get('newMemoBody'))->toBe(''); // 保存後は入力欄がリセットされる
        });

        test('複数回保存すると追記されていく', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            holdingDetailTestHoldingSnapshot($snapshot, $holding);

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->set('newMemoBody', '1回目のメモ')->call('saveMemo');
            $component->set('newMemoBody', '2回目のメモ')->call('saveMemo');

            $component->assertSee('1回目のメモ');
            $component->assertSee('2回目のメモ');
            expect(HoldingMemo::where('holding_id', $holding->id)->count())->toBe(2);
        });
    });

    describe('異常系・境界値', function () {
        test('メモが2000文字を超える場合はバリデーションエラーになる', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            holdingDetailTestHoldingSnapshot($snapshot, $holding);

            $tooLongMemo = str_repeat('あ', 2001);

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->set('newMemoBody', $tooLongMemo)->call('saveMemo');

            $component->assertHasErrors(['newMemoBody' => 'max']);
            expect(HoldingMemo::where('holding_id', $holding->id)->count())->toBe(0);
        });

        test('メモを空欄で保存しようとするとバリデーションエラーになる', function () {
            $user = User::factory()->create();
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            holdingDetailTestHoldingSnapshot($snapshot, $holding);

            $component = Livewire::actingAs($user)->test(HoldingDetail::class, ['holding' => $holding]);

            $component->set('newMemoBody', '')->call('saveMemo');

            $component->assertHasErrors(['newMemoBody' => 'required']);
            expect(HoldingMemo::where('holding_id', $holding->id)->count())->toBe(0);
        });

        test('存在しない銘柄IDへのアクセスは404になる', function () {
            // NOTE: coincidentally already passes today because the route
            // itself does not exist yet at all (Laravel's default "no
            // matching route" 404), not because route-model-binding
            // rejected id=999999 — same caveat as
            // tests/Feature/ImportSummaryReportShowTest.php's equivalent
            // test. Re-verify once Green adds the route, to confirm the 404
            // then comes from route model binding, not route absence.
            $user = User::factory()->create();

            $this->actingAs($user)->get('/holdings/999999')->assertStatus(404);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーは銘柄詳細画面にアクセスできない', function () {
            [, $snapshot] = holdingDetailTestImportBatch();
            $holding = holdingDetailTestHolding();
            holdingDetailTestHoldingSnapshot($snapshot, $holding);

            $this->get("/holdings/{$holding->id}")->assertRedirect('/login');
        });
    });
});
