<?php

namespace Tests\Feature;

use App\Actions\Holding\ShowHoldingDetailAction;
use App\Livewire\Candidate\CandidateCheck;
use App\Livewire\CsvImport\Upload;
use App\Livewire\Holding\HoldingDetail;
use App\Livewire\ImportSummaryReport\Latest;
use App\Livewire\ImportSummaryReport\Show;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingMemo;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\TechnicalIndicator;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Models\WatchlistRefreshRun;
use App\Models\WatchRecord;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| CHG-0024: 画面表示の日時・日付をマニラ時間に統一 — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0018-display-timezone-manila.md（Context の表示箇所6つ・D1〜D3）
|   - docs/product/ui-guidelines.md「日時・日付の表示」
|       日時は Y-m-d H:i、日付は Y-m-d。app.display_timezone（既定 Asia/Manila）
|       に変換してから表示。DB 保存は UTC のまま。
|
| 全テスト共通の基準時刻: 2026-09-26 16:30:00 UTC
|   → マニラ時間 2026-09-27 00:30（時刻だけでなく日付も翌日に変わる境界ケース）。
| 各テストは「マニラ時間の文字列が表示される」かつ「UTC の生の文字列
| '2026-09-26 16:30' が表示されない」ことを確認する。
|
| Expected Red (現状は変換なしで UTC のまま表示している):
|   - 画面系 a〜e: assertSee('2026-09-27 00:30') の不一致（UTC の 2026-09-26 16:30 が表示されている）
|   - f: price_history の date が '2026-09-26'（UTC の日付）のまま
|
*/

const CHG0024_UTC = '2026-09-26 16:30:00';
const CHG0024_UTC_LABEL = '2026-09-26 16:30';
const CHG0024_MANILA_LABEL = '2026-09-27 00:30';
const CHG0024_MANILA_DATE = '2026-09-27';

function chg0024User(): User
{
    return User::factory()->create();
}

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function chg0024ImportBatch(string|\DateTimeInterface $at, string $jpFilename = 'jp_stock.csv'): array
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => $jpFilename,
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 1,
        'error_count' => 0,
        'imported_at' => $at,
    ]);

    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => $at,
    ]);

    return [$batch, $snapshot];
}

function chg0024Holding(string $code = '7203', string $name = 'トヨタ自動車'): Holding
{
    return Holding::create([
        'symbol_code' => $code,
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => $name,
        'first_detected_at' => now(),
    ]);
}

function chg0024HoldingSnapshot(Snapshot $snapshot, Holding $holding): HoldingSnapshot
{
    return HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 1000,
        'current_price' => 1200,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 2000,
        'unrealized_gain_rate' => 20.0,
        'is_newly_detected' => false,
    ]);
}

/**
 * Unheld watchlist stock (shown as a row on /candidate-check).
 */
function chg0024WatchlistItem(string $code, string $name): WatchlistItem
{
    $holding = chg0024Holding($code, $name);

    TechnicalIndicator::create([
        'holding_id' => $holding->id,
        'rsi' => 50.0,
        'week52_high' => 2000.0,
        'week52_low' => 1000.0,
        'ma20' => 1500.0,
        'computed_at' => now(),
    ]);

    FundamentalIndicator::create([
        'holding_id' => $holding->id,
        'per' => 15.0,
        'pbr' => 1.5,
        'roe' => 12.0,
        'equity_ratio' => 45.0,
        'revenue_growth' => 5.0,
        'operating_income_growth' => 5.0,
        'operating_margin' => 12.0,
        'eps_growth' => 5.0,
        'peg_ratio' => 1.0,
        'fetched_at' => now(),
    ]);

    return WatchlistItem::create([
        'holding_id' => $holding->id,
        'folder_name' => 'テーマA',
        'exchange_label' => '東Ｐ',
        'source' => 'rakuten_favorites_csv',
        'is_starred' => false,
        'last_close' => 1500.0,
        'last_seen_in_csv_at' => now(),
        'registered_at' => now(),
    ]);
}

describe('CHG-0024: 画面の日時・日付はマニラ時間で表示される（ADR-0018）', function () {
    test('CSV取込画面の取込履歴の取込日時がマニラ時間で表示される', function () {
        chg0024ImportBatch(CHG0024_UTC, 'jp_tz_check.csv');

        Livewire::actingAs(chg0024User())->test(Upload::class)
            ->assertSee('jp_tz_check.csv')
            ->assertSee(CHG0024_MANILA_LABEL)
            ->assertDontSee(CHG0024_UTC_LABEL);
    });

    test('新規投資候補画面の「最終更新」がマニラ時間で表示される', function () {
        WatchlistRefreshRun::create([
            'status' => 'completed',
            'total_count' => 1,
            'processed_count' => 1,
            'failed_count' => 0,
            'started_at' => '2026-09-26 16:25:00',
            'finished_at' => CHG0024_UTC,
        ]);

        Livewire::actingAs(chg0024User())->test(CandidateCheck::class)
            ->assertSee('最終更新: '.CHG0024_MANILA_LABEL)
            ->assertDontSee(CHG0024_UTC_LABEL);
    });

    test('新規投資候補画面の行展開内のウォッチ記録日時がマニラ時間（Y-m-d H:i）で表示される', function () {
        $item = chg0024WatchlistItem('4444', '展開テスト製薬');
        WatchRecord::create([
            'holding_id' => $item->holding_id,
            'watch_status' => '買い時',
            'memo' => '決算good',
            'recorded_at' => CHG0024_UTC,
        ]);

        Livewire::actingAs(chg0024User())->test(CandidateCheck::class)
            ->call('toggleExpand', '4444')
            ->assertSee('決算good')
            ->assertSee(CHG0024_MANILA_LABEL)
            ->assertDontSee(CHG0024_MANILA_LABEL.':00') // 秒は表示しない（Y-m-d H:i）
            ->assertDontSee(CHG0024_UTC_LABEL);
    });

    test('銘柄詳細画面のメモ日時がマニラ時間（Y-m-d H:i）で表示される', function () {
        [, $snapshot] = chg0024ImportBatch(now());
        $holding = chg0024Holding();
        chg0024HoldingSnapshot($snapshot, $holding);
        HoldingMemo::create([
            'holding_id' => $holding->id,
            'body' => 'タイムゾーン確認メモ',
            'recorded_at' => CHG0024_UTC,
        ]);

        Livewire::actingAs(chg0024User())->test(HoldingDetail::class, ['holding' => $holding])
            ->assertSee('タイムゾーン確認メモ')
            ->assertSee(CHG0024_MANILA_LABEL)
            ->assertDontSee(CHG0024_MANILA_LABEL.':00') // 秒は表示しない（Y-m-d H:i）
            ->assertDontSee(CHG0024_UTC_LABEL);
    });

    test('サマリーレポート（最新タブ）の取込日時キャプションがマニラ時間で表示される', function () {
        [, $snapshot] = chg0024ImportBatch(CHG0024_UTC);
        chg0024HoldingSnapshot($snapshot, chg0024Holding());

        $component = Livewire::actingAs(chg0024User())->test(Latest::class);

        expect($component->get('importedAtLabel'))->toBe(CHG0024_MANILA_LABEL);
        $component->assertSee('取込日時: '.CHG0024_MANILA_LABEL)
            ->assertDontSee(CHG0024_UTC_LABEL);
    });

    test('サマリーレポート（取込直後の画面）の分類俯瞰の基準日時キャプションがマニラ時間で表示される', function () {
        [$batch, $snapshot] = chg0024ImportBatch(CHG0024_UTC);
        chg0024HoldingSnapshot($snapshot, chg0024Holding());

        $component = Livewire::actingAs(chg0024User())->test(Show::class, ['importBatch' => $batch]);

        expect($component->get('importedAtLabel'))->toBe(CHG0024_MANILA_LABEL);
        $component->assertSee('分類俯瞰の基準日時: '.CHG0024_MANILA_LABEL)
            ->assertDontSee(CHG0024_UTC_LABEL);
    });

    test('銘柄詳細の株価推移チャートの日付はスナップショット日時のマニラ時間の日付になる', function () {
        $this->travelTo(now()->parse('2026-09-27 03:00:00'));
        [, $snapshot] = chg0024ImportBatch(CHG0024_UTC);
        $holding = chg0024Holding();
        chg0024HoldingSnapshot($snapshot, $holding);

        $detail = app(ShowHoldingDetailAction::class)->execute($holding);

        expect($detail['price_history'])->toHaveCount(1);
        expect($detail['price_history'][0]['date'])->toBe(CHG0024_MANILA_DATE);
    });
});
