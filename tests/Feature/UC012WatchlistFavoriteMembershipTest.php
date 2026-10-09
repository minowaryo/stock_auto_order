<?php

namespace Tests\Feature;

use App\Actions\Watchlist\ImportFavoriteCsvAction;
use App\Actions\Watchlist\ShowWatchlistAction;
use App\Livewire\Candidate\CandidateCheck;
use App\Models\Holding;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** UC-012「お気に入り未保有銘柄ウォッチリスト」: 直近の成功したCSVの所属と履歴経路を区別する。 */
beforeEach(fn () => Queue::fake());

function uc012MembershipCsv(array $symbols): UploadedFile
{
    $rows = ['"MS2","2"'];
    foreach ($symbols as $symbol) {
        $rows[] = sprintf('"STK","%s","日本株テーマ","1","東Ｐ","銘柄%s"', $symbol, $symbol);
    }

    return UploadedFile::fake()->createWithContent(
        'favorites.csv',
        mb_convert_encoding(implode("\r\n", $rows)."\r\n", 'SJIS-win', 'UTF-8'),
    );
}

function uc012MembershipRow(string $symbol): array
{
    return collect(app(ShowWatchlistAction::class)->execute())->firstWhere('symbol_code', $symbol);
}

test('UC-012 お気に入り未保有銘柄ウォッチリストは再取込で欠落した銘柄を保持し楽天側の解除を表示する', function () {
    $import = app(ImportFavoriteCsvAction::class);
    expect($import->execute(uc012MembershipCsv(['1111', '2222']))->success)->toBeTrue();
    expect($import->execute(uc012MembershipCsv(['1111']))->success)->toBeTrue();

    $remaining = uc012MembershipRow('1111');
    $removed = uc012MembershipRow('2222');
    expect($remaining['in_rakuten_favorites'])->toBeTrue()
        ->and($removed['in_rakuten_favorites'])->toBeFalse()
        ->and($removed['registration_routes'])->toContain('CSV')
        ->and(WatchlistItem::count())->toBe(2);

    Livewire::actingAs(User::factory()->create())->test(CandidateCheck::class)
        ->assertSee('楽天お気に入り解除済');
});

test('UC-012 お気に入り未保有銘柄ウォッチリストは同じ秒の連続取込も最後の成功バッチで判定する', function () {
    $this->freezeTime();
    $import = app(ImportFavoriteCsvAction::class);
    expect($import->execute(uc012MembershipCsv(['1111', '2222']))->success)->toBeTrue();
    expect($import->execute(uc012MembershipCsv(['2222']))->success)->toBeTrue();

    expect(uc012MembershipRow('1111')['in_rakuten_favorites'])->toBeFalse()
        ->and(uc012MembershipRow('2222')['in_rakuten_favorites'])->toBeTrue();
});

test('UC-012 お気に入り未保有銘柄ウォッチリストは失敗したCSV取込で直近成功分の所属を変えない', function () {
    $import = app(ImportFavoriteCsvAction::class);
    expect($import->execute(uc012MembershipCsv(['1111', '2222']))->success)->toBeTrue();
    expect($import->execute(uc012MembershipCsv(['1111']))->success)->toBeTrue();

    // 対象銘柄がないCSVは既存UC-012契約どおり失敗し、空の成功バッチにはしない。
    $failed = $import->execute(UploadedFile::fake()->createWithContent(
        'favorites.csv',
        mb_convert_encoding("\"MS2\",\"2\"\r\n\"CFD\",\"113\",\"対象外\",\"\",\"\",\"CFD\"\r\n", 'SJIS-win', 'UTF-8'),
    ));

    expect($failed->success)->toBeFalse()
        ->and(uc012MembershipRow('1111')['in_rakuten_favorites'])->toBeTrue()
        ->and(uc012MembershipRow('2222')['in_rakuten_favorites'])->toBeFalse();
});

test('UC-012 お気に入り未保有銘柄ウォッチリストは調査候補のみの銘柄に楽天側解除済みを表示しない', function () {
    $holding = Holding::create([
        'symbol_code' => '3333',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => '調査候補のみの企業',
        'first_detected_at' => now(),
    ]);
    WatchlistItem::create([
        'holding_id' => $holding->id,
        'source' => 'manual',
        'is_starred' => true,
        'folder_name' => null,
        'last_seen_in_csv_at' => null,
        'registered_at' => now(),
    ]);
    expect(app(ImportFavoriteCsvAction::class)->execute(uc012MembershipCsv(['1111']))->success)->toBeTrue();

    $row = uc012MembershipRow('3333');
    expect($row['in_rakuten_favorites'])->toBeFalse()
        ->and($row['registration_routes'])->not->toContain('CSV');
    Livewire::actingAs(User::factory()->create())->test(CandidateCheck::class)
        ->assertSee('調査候補のみの企業')
        ->assertDontSee('楽天お気に入り解除済');
});
