<?php

namespace Tests\Feature;

use App\Actions\Watchlist\RefreshWatchlistMarketDataAction;
use App\Jobs\RefreshWatchlistMarketDataJob;
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
use App\Models\WatchlistBuySignal;
use App\Models\WatchlistItem;
use App\Models\WatchlistRefreshRun;
use App\Models\WatchRecord;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\Fakes\FakeFinnhubClient;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| UC-012: お気に入り未保有銘柄ウォッチリスト画面 — Red phase Livewire Test (Cycle 4)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0013-favorites-watchlist.md (D4, D6)
|   - docs/product/use-cases.md UC-012「基本フロー（一覧の閲覧・★お気に入り）」・出力（一覧の各行）・業務ルール
|   - docs/product/ui-guidelines.md「ナビゲーション方針」2026-09-06 改訂節
|   - 参照実装: app/Livewire/Signal/SignalList.php（参照専用・render()毎にAction再呼び出し）
|
| 刷新後の `/candidate-check`（`CandidateCheck` Livewire フルページ）は:
|   - 上部: 取込・更新バー（お気に入りCSVアップロード / 「一括更新」ボタン / 進捗 / 最終更新）
|   - 主部: 未保有ウォッチリスト銘柄の一覧（App\Actions\Watchlist\ShowWatchlistAction）
|           透明マルチキーソート: ①押し目シグナル数 desc ②財務健全性 passed→unavailable→failed
|           ③同セクター保有比率 asc ④52週レンジ内位置 asc
|   - ★トグル: watchlist_items.is_starred を反転
|   - フィルタ: フォルダ / ★のみ
|   - 「おすすめ候補（UC-008）」「銘柄コード手入力（UC-006）」は廃止
|
| 未実装: App\Actions\Watchlist\ShowWatchlistAction、CandidateCheck の刷新
| （現状はまだ UC-006/UC-008 版）。下記テストは Red。
|
| 契約の想定（Gate 4 で要確認）:
|   - CandidateCheck::render() が ShowWatchlistAction を呼び `rows` を渡す。各行:
|     symbol_code / symbol_name / market / folder_name / is_starred / watchlist_item_id /
|     current_price / week52_range_position / overlap_rate / rebound_buy_signal_count /
|     fundamental_status / fundamental_summary / suggested_amount / nisa_recommended /
|     rsi / per / pbr / roe / equity_ratio / operating_margin / criteria
|   - current_price は watchlist_items.last_close（refresh 時に保存。Gate 4 で追加を提案）
|   - toggleStar(watchlistItemId) / refreshAll() / importFavorites() アクション
|   - refreshAll() は active な run があれば二重実行しない
*/

function uc012ScreenUser(): User
{
    return User::factory()->create();
}

function uc012Sector(string $name): SectorClassification
{
    return SectorClassification::firstOrCreate(['name' => $name], ['code' => null]);
}

/**
 * @param  array<string, mixed>  $opts
 */
function uc012ScreenWatchlist(string $code, array $opts = []): WatchlistItem
{
    $holding = Holding::create([
        'symbol_code' => $code,
        'market' => $opts['market'] ?? 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => $opts['name'] ?? "銘柄{$code}",
        'sector_classification_id' => $opts['sector_id'] ?? null,
        'first_detected_at' => now(),
    ]);

    TechnicalIndicator::create([
        'holding_id' => $holding->id,
        'rsi' => $opts['rsi'] ?? 50.0,
        'week52_high' => $opts['week52_high'] ?? 2000.0,
        'week52_low' => $opts['week52_low'] ?? 1000.0,
        'ma20' => 1500.0,
        'computed_at' => now(),
    ]);

    FundamentalIndicator::create([
        'holding_id' => $holding->id,
        'per' => $opts['per'] ?? 15.0,
        'pbr' => 1.5,
        'roe' => $opts['roe'] ?? 12.0,
        'equity_ratio' => $opts['equity_ratio'] ?? 45.0,
        'revenue_growth' => $opts['revenue_growth'] ?? 5.0,
        'operating_income_growth' => $opts['operating_income_growth'] ?? 5.0,
        // CHG-0017 / ADR-0015 D2 配線 Cycle 4c: 直近3期平均成長率の
        // 救済経路検証用（デフォルトnull＝救済なし、既存テストは無改変）。
        'avg_revenue_growth' => $opts['avg_revenue_growth'] ?? null,
        'avg_operating_income_growth' => $opts['avg_operating_income_growth'] ?? null,
        'operating_margin' => $opts['operating_margin'] ?? 12.0,
        'eps_growth' => 5.0,
        'peg_ratio' => 1.0,
        'fetched_at' => now(),
    ]);

    $item = WatchlistItem::create([
        'holding_id' => $holding->id,
        'folder_name' => $opts['folder'] ?? 'テーマA',
        'exchange_label' => '東Ｐ',
        'source' => 'rakuten_favorites_csv',
        'is_starred' => $opts['starred'] ?? false,
        'last_close' => $opts['last_close'] ?? 1500.0,
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);

    foreach (range(1, $opts['buy_signals'] ?? 0) as $i) {
        $types = ['rsi_oversold_rebound', 'macd_golden_cross', 'bollinger_oversold', 'week52_low_proximity'];
        WatchlistBuySignal::create([
            'holding_id' => $holding->id,
            'signal_type' => $types[$i - 1] ?? 'ma_deviation_oversold',
            'reason_summary' => 'テスト',
            'determined_at' => now(),
        ]);
    }

    return $item;
}

function uc012MarkHeldOnLatestSnapshot(Holding $holding): void
{
    $batch = ImportBatch::create([
        'status' => 'completed', 'jp_stock_filename' => 'jp.csv', 'us_stock_filename' => 'us.csv',
        'mutual_fund_filename' => null, 'imported_count' => 1, 'error_count' => 0, 'imported_at' => now(),
    ]);
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
    HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id, 'holding_id' => $holding->id,
        'quantity' => 10, 'average_cost' => 1000, 'current_price' => 1200, 'fx_rate_used' => null,
        'unrealized_gain_amount' => 2000, 'unrealized_gain_rate' => 20.0, 'is_newly_detected' => false,
    ]);
}

test('未保有のウォッチリスト銘柄が一覧に表示される', function () {
    uc012ScreenWatchlist('1111', ['name' => 'アルファ工業']);
    uc012ScreenWatchlist('2222', ['name' => 'ベータ商事']);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class);

    $component->assertSee('アルファ工業');
    $component->assertSee('ベータ商事');
});

test('保有済みのウォッチリスト銘柄は一覧に表示されない', function () {
    $held = uc012ScreenWatchlist('3333', ['name' => 'ガンマ保有中']);
    uc012MarkHeldOnLatestSnapshot($held->holding);
    uc012ScreenWatchlist('4444', ['name' => 'デルタ未保有']);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class);

    $component->assertSee('デルタ未保有');
    $component->assertDontSee('ガンマ保有中');
});

test('一覧は押し目シグナル数の多い順→財務健全性→同セクター保有比率→52週レンジ内位置でソートされる', function () {
    // A: シグナル2件（最上位）
    uc012ScreenWatchlist('AAAA', ['name' => 'エー', 'buy_signals' => 2, 'roe' => 20, 'equity_ratio' => 60, 'operating_margin' => 20]);
    // B: シグナル0件・財務passed
    uc012ScreenWatchlist('BBBB', ['name' => 'ビー', 'buy_signals' => 0, 'roe' => 20, 'equity_ratio' => 60, 'operating_margin' => 20]);
    // C: シグナル0件・財務failed（最下位）
    uc012ScreenWatchlist('CCCC', ['name' => 'シー', 'buy_signals' => 0, 'roe' => 1, 'equity_ratio' => 5, 'operating_margin' => 1]);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class);

    $rows = $component->viewData('rows');
    expect(collect($rows)->pluck('symbol_code')->all())->toBe(['AAAA', 'BBBB', 'CCCC']);
});

test('★トグルでis_starredが反転し永続化される', function () {
    $item = uc012ScreenWatchlist('5555', ['name' => 'スターテスト', 'starred' => false]);

    Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class)
        ->call('toggleStar', $item->id);

    expect($item->fresh()->is_starred)->toBeTrue();
});

test('★のみフィルタで is_starred の銘柄だけ表示される', function () {
    uc012ScreenWatchlist('6666', ['name' => 'スター付き', 'starred' => true]);
    uc012ScreenWatchlist('7777', ['name' => 'スター無し', 'starred' => false]);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class)
        ->set('starredOnly', true);

    $component->assertSee('スター付き');
    $component->assertDontSee('スター無し');
});

test('フォルダフィルタで該当フォルダの銘柄だけ表示される', function () {
    uc012ScreenWatchlist('8888', ['name' => 'ホテルチェーン', 'folder' => '訪日インバウンド旅行']);
    uc012ScreenWatchlist('9999', ['name' => '防衛関連メーカー', 'folder' => '日本株 トレンド国策銘柄']);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class)
        ->set('folderFilter', '訪日インバウンド旅行');

    $component->assertSee('ホテルチェーン');
    $component->assertDontSee('防衛関連メーカー');
});

test('一括更新ボタンでrunが作成されJobがdispatchされる', function () {
    Bus::fake();
    uc012ScreenWatchlist('1234', ['name' => 'リフレッシュ対象']);

    Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class)
        ->call('refreshAll');

    Bus::assertDispatched(RefreshWatchlistMarketDataJob::class);
    expect(WatchlistRefreshRun::active()->count())->toBe(1);
});

test('実行中のrunがある場合、一括更新は二重に実行されない', function () {
    Bus::fake();
    WatchlistRefreshRun::create(['status' => 'processing', 'started_at' => now()]);

    Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class)
        ->call('refreshAll');

    Bus::assertNotDispatched(RefreshWatchlistMarketDataJob::class);
    expect(WatchlistRefreshRun::count())->toBe(1);
});

test('一括更新は作成した run の id を Job に渡す（run のライフサイクルを1行に統一、review #1）', function () {
    Bus::fake();
    uc012ScreenWatchlist('9012', ['name' => 'ラン受け渡し']);

    Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class)
        ->call('refreshAll');

    $run = WatchlistRefreshRun::latest('id')->first();
    Bus::assertDispatched(RefreshWatchlistMarketDataJob::class, fn ($job) => $job->runId === $run->id);
});

test('一括更新の完了後、次の一括更新がまた実行できる（queued run が孤立しない、review #1）', function () {
    Bus::fake();
    uc012ScreenWatchlist('3210', ['name' => 'リラン可能テスト']);
    app()->instance(JpStockPriceClientInterface::class, new FakeJpStockPriceClient(['3210' => []]));
    app()->instance(UsStockPriceClientInterface::class, new FakeUsStockPriceClient);
    app()->instance(MarketIndexClientInterface::class, new FakeMarketIndexClient);
    app()->instance(JQuantsClientInterface::class, new FakeJQuantsClient);
    app()->instance(FinnhubClientInterface::class, new FakeFinnhubClient);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class);

    $component->call('refreshAll');
    $run = WatchlistRefreshRun::latest('id')->first();
    // 同じ run 行がライフサイクルを引き継ぐので、Job 実行後に active() が解ける
    (new RefreshWatchlistMarketDataJob($run->id))->handle(app(RefreshWatchlistMarketDataAction::class));
    expect($run->fresh()->status)->toBe('completed');
    expect(WatchlistRefreshRun::active()->exists())->toBeFalse();

    // 2回目の一括更新がまた実行できる（Job が再度 dispatch される）
    $component->call('refreshAll');
    Bus::assertDispatchedTimes(RefreshWatchlistMarketDataJob::class, 2);
    expect(WatchlistRefreshRun::count())->toBe(2);
});

test('ウォッチリストが空のとき空状態メッセージが表示される', function () {
    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class);

    $component->assertSee('お気に入り銘柄CSVを取り込んでください');
});

test('各行に同セクター保有比率・財務健全性・小口購入額・判定チェックリストが含まれる', function () {
    $sector = uc012Sector('電気機器');
    uc012ScreenWatchlist('2468', ['name' => 'フル指標銘柄', 'sector_id' => $sector->id]);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class);

    $row = collect($component->viewData('rows'))->firstWhere('symbol_code', '2468');
    expect($row)->toHaveKeys(['overlap_rate', 'fundamental_status', 'suggested_amount', 'criteria', 'week52_range_position']);
    expect($row['criteria'])->toHaveKey('technical');
    expect($row['criteria'])->toHaveKey('fundamental');
});

// ---------------------------------------------------------------------
// CR (2026-09-20, CHG-0017 / ADR-0015 D2 配線 Cycle 4c)
// ---------------------------------------------------------------------
// FundamentalHealthEvaluator自体はD2救済（直近3期平均成長率>0%のOR救済）を
// 実装済みだが、ShowWatchlistAction はまだ avg_revenue_growth/
// avg_operating_income_growth を evaluate() に渡していない。
// Red の出方（2026-09-20）: roe=12.0/equity_ratio=45.0（D1のRESCUE閾値
// ROE≧15%/自己資本比率≧50%は満たさない）・単年度成長率は両方マイナスの
// ため、現行実装は fundamental_status='failed' のままとなり、'passed' を
// 期待する以下のアサーションが不一致になる。
test('単年度成長率はマイナスだが3期平均成長率がプラスの銘柄は、D2救済によりfundamental_statusがpassedになる', function () {
    uc012ScreenWatchlist('7777', [
        'name' => 'D2救済銘柄',
        'roe' => 12.0,
        'equity_ratio' => 45.0,
        'revenue_growth' => -3.0,
        'operating_income_growth' => -1.0,
        'avg_revenue_growth' => 2.0,
    ]);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class);

    $row = collect($component->viewData('rows'))->firstWhere('symbol_code', '7777');
    expect($row)->not->toBeNull();
    expect($row['fundamental_status'])->toBe('passed');
});

test('刷新後の画面に「おすすめ候補」「銘柄コード手入力」セクションは無い', function () {
    uc012ScreenWatchlist('1357', ['name' => 'テスト銘柄']);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class);

    $component->assertDontSee('おすすめ候補');
    $component->assertDontSee('個別銘柄をチェック');
});

test('行を展開すると分散コメント・過去業績・ウォッチメモ履歴（UC-006相当）が表示される', function () {
    $sector = uc012Sector('医薬品');
    $item = uc012ScreenWatchlist('4444', ['name' => '展開テスト製薬', 'sector_id' => $sector->id]);
    FinancialStatement::create([
        'holding_id' => $item->holding_id,
        'fiscal_period' => '2025-03-31',
        'revenue' => 50000,
        'operating_income' => 8000,
        'fetched_at' => now(),
    ]);
    WatchRecord::create([
        'holding_id' => $item->holding_id,
        'watch_status' => '買い時',
        'memo' => '決算good',
        'recorded_at' => now(),
    ]);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class)
        ->call('toggleExpand', '4444');

    $component->assertSee('過去の業績推移');
    $component->assertSee('決算good');
    $component->assertSee('分散');
});

test('展開行からウォッチステータス・メモを記録できる', function () {
    $item = uc012ScreenWatchlist('5678', ['name' => 'メモ記録テスト']);

    Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class)
        ->call('toggleExpand', '5678')
        ->set('watchStatus', '次回購入候補')
        ->set('watchMemo', '来週の押し目を待つ')
        ->call('saveWatchRecord');

    $this->assertDatabaseHas('watch_records', [
        'holding_id' => $item->holding_id,
        'watch_status' => '次回購入候補',
        'memo' => '来週の押し目を待つ',
    ]);
});

test('ウォッチステータス・メモが両方空の記録は拒否される', function () {
    uc012ScreenWatchlist('6789', ['name' => '空記録テスト']);

    $component = Livewire::actingAs(uc012ScreenUser())->test(CandidateCheck::class)
        ->call('toggleExpand', '6789')
        ->call('saveWatchRecord');

    $component->assertHasErrors('watchRecord');
    $this->assertDatabaseCount('watch_records', 0);
});

test('未認証ユーザーは/candidate-checkでログインへリダイレクトされる', function () {
    $this->get('/candidate-check')->assertRedirect('/login');
});
