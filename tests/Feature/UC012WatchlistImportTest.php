<?php

namespace Tests\Feature;

use App\Actions\Watchlist\ImportFavoriteCsvAction;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\Snapshot;
use App\Models\WatchlistItem;
use Illuminate\Http\UploadedFile;

/*
|--------------------------------------------------------------------------
| UC-012: お気に入り銘柄CSV取込 — Red phase Feature Test (F-012 / ADR-0013 / CHG-0014)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0013-favorites-watchlist.md (D2, D7)
|   - docs/product/use-cases.md UC-012「基本フロー（CSV取込）」
|   - docs/architecture/data-model.md #watchlist_items / #holdings「作成経路②」
|   - ~/.claude/plans/stock_auto_order-favorites-watchlist-implementation-phase.md (Cycle 2)
|
| Nothing exists yet for this cycle: no App\Actions\Watchlist\ImportFavoriteCsvAction,
| no App\Models\WatchlistItem, no `watchlist_items` migration. Every test below
| is expected to fail (Red).
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - ImportFavoriteCsvAction::execute(UploadedFile $file): FavoriteImportResult
|     with ->success (bool), ->registeredCount (int, in-scope rows written),
|     ->skippedCount (int, CFD/invalid rows), ->failureReason (?string).
|   - The action decodes CP932 itself (like ImportCsvAction::decode()),
|     parses via RakutenFavoriteCsvParser, then per in-scope row:
|       * Holding::firstOrCreate(['symbol_code','market'], [instrument_type,
|         symbol_name, first_detected_at]) — reuses an existing holding row
|       * upserts one watchlist_items row keyed by holding_id, setting
|         folder_name / exchange_label / source='rakuten_favorites_csv' /
|         last_seen_in_csv_at=now(), preserving is_starred
|   - Re-import is additive: rows absent from the new CSV are NOT deleted;
|     is_starred is preserved; folder_name / last_seen_in_csv_at are refreshed.
|   - The bulk refresh Job dispatch is Cycle 3 — NOT asserted here.
|   - `signals` / `buy_signals` / snapshots are never touched by this action.
*/

function uc012FavRow(string $market, string $code, string $folder, string $exCode, string $exLabel, string $name): string
{
    return sprintf('"%s","%s","%s","%s","%s","%s"', $market, $code, $folder, $exCode, $exLabel, $name);
}

/**
 * @param  array<int, string>  $rows
 */
function uc012FavCsvFile(array $rows, string $filename = 'favorites.csv'): UploadedFile
{
    $lines = array_merge(['"MS2","2"'], $rows);
    $utf8 = implode("\r\n", $lines)."\r\n";
    $cp932 = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');

    return UploadedFile::fake()->createWithContent($filename, $cp932);
}

test('お気に入りCSVを取り込むと未知の銘柄がholdingsとwatchlist_itemsに登録される', function () {
    $file = uc012FavCsvFile([
        uc012FavRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ'),
        uc012FavRow('USS', 'NVDA', '通信/情報系　米国株', 'A9', '米国', 'エヌビディア'),
    ]);

    $result = app(ImportFavoriteCsvAction::class)->execute($file);

    expect($result->success)->toBeTrue();
    expect($result->registeredCount)->toBe(2);
    expect($result->skippedCount)->toBe(0);

    $jp = Holding::where('symbol_code', '9433')->where('market', 'jp')->first();
    expect($jp)->not->toBeNull();
    expect($jp->instrument_type)->toBe('stock');

    expect(WatchlistItem::count())->toBe(2);
    $item = WatchlistItem::where('holding_id', $jp->id)->first();
    expect($item->folder_name)->toBe('通信/情報系　日本株');
    expect($item->exchange_label)->toBe('東Ｐ');
    expect($item->source)->toBe('rakuten_favorites_csv');
    expect($item->is_starred)->toBeFalse();
    expect($item->last_seen_in_csv_at)->not->toBeNull();
});

test('既に保有している銘柄は既存のholding行を再利用し重複を作らない', function () {
    $existing = Holding::create([
        'symbol_code' => '7203',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'トヨタ自動車',
        'first_detected_at' => now()->subMonth(),
    ]);

    $file = uc012FavCsvFile([
        uc012FavRow('STK', '7203', '日本株　分散投資', '1', '東Ｐ', 'トヨタ自動車'),
    ]);

    app(ImportFavoriteCsvAction::class)->execute($file);

    expect(Holding::where('symbol_code', '7203')->where('market', 'jp')->count())->toBe(1);
    expect(WatchlistItem::where('holding_id', $existing->id)->exists())->toBeTrue();
});

test('CFD行と列不足の行はスキップされ登録されない', function () {
    $file = uc012FavCsvFile([
        uc012FavRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ'),
        uc012FavRow('CFD', '113', 'アメリカ　ETF CFD', '', '', 'エネルギー・セレクト・セクターSPDRファンド'),
        '"STK","7616"',
    ]);

    $result = app(ImportFavoriteCsvAction::class)->execute($file);

    expect($result->registeredCount)->toBe(1);
    expect($result->skippedCount)->toBe(2);
    expect(Holding::where('symbol_code', '113')->exists())->toBeFalse();
    expect(WatchlistItem::count())->toBe(1);
});

test('再取込は追加のみ: 前回あって今回無い銘柄もwatchlist_itemsに残る', function () {
    $first = uc012FavCsvFile([
        uc012FavRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ'),
        uc012FavRow('STK', '7616', '日本株　食品関連', '1', '東Ｐ', 'コロワイド'),
    ]);
    app(ImportFavoriteCsvAction::class)->execute($first);

    $second = uc012FavCsvFile([
        uc012FavRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ'),
    ]);
    app(ImportFavoriteCsvAction::class)->execute($second);

    // 7616 は2回目のCSVに無いが残る
    expect(WatchlistItem::count())->toBe(2);
    $korowide = Holding::where('symbol_code', '7616')->first();
    expect(WatchlistItem::where('holding_id', $korowide->id)->exists())->toBeTrue();
});

test('再取込で★お気に入りは保持され、フォルダ名とlast_seen_in_csv_atは更新される', function () {
    app(ImportFavoriteCsvAction::class)->execute(uc012FavCsvFile([
        uc012FavRow('STK', '5805', '日本株 トレンド国策銘柄', '1', '東Ｐ', 'ＳＷＣＣ'),
    ]));

    $holding = Holding::where('symbol_code', '5805')->first();
    $item = WatchlistItem::where('holding_id', $holding->id)->first();
    $item->update(['is_starred' => true]);
    $seenBefore = $item->last_seen_in_csv_at;

    $this->travel(2)->days();

    app(ImportFavoriteCsvAction::class)->execute(uc012FavCsvFile([
        uc012FavRow('STK', '5805', '日本株　分散投資', '1', '東Ｐ', 'ＳＷＣＣ'),
    ]));

    $item->refresh();
    expect($item->is_starred)->toBeTrue();
    expect($item->folder_name)->toBe('日本株　分散投資');
    expect($item->last_seen_in_csv_at->greaterThan($seenBefore))->toBeTrue();
});

test('対象の銘柄行が1件も無いCSVは失敗結果を返す', function () {
    $file = uc012FavCsvFile([
        uc012FavRow('CFD', '113', 'アメリカ　ETF CFD', '', '', 'エネルギー・セレクト・セクターSPDRファンド'),
    ]);

    $result = app(ImportFavoriteCsvAction::class)->execute($file);

    expect($result->success)->toBeFalse();
    expect($result->failureReason)->not->toBeNull();
    expect(WatchlistItem::count())->toBe(0);
});

test('お気に入り取込はsnapshots/holding_snapshotsを作らない', function () {
    $file = uc012FavCsvFile([
        uc012FavRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ'),
    ]);

    app(ImportFavoriteCsvAction::class)->execute($file);

    expect(Snapshot::count())->toBe(0);
    expect(HoldingSnapshot::count())->toBe(0);
});
