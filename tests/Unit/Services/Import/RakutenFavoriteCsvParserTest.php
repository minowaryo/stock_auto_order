<?php

namespace Tests\Unit\Services\Import;

use App\Exceptions\Import\CsvStructureException;
use App\Services\Import\RakutenFavoriteCsvParser;

/*
|--------------------------------------------------------------------------
| RakutenFavoriteCsvParser — Red phase Unit Test (F-012 / UC-012 / ADR-0013)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0013-favorites-watchlist.md (D2, CSV の実態)
|   - docs/product/use-cases.md UC-012「基本フロー（CSV取込）」フロー2
|   - docs/product/requirements.md 6章（お気に入り銘柄CSVの形式）
|   - 実ファイル docs/original-docs/お気に入り銘柄CSV.csv
|
| 楽天証券の「お気に入り銘柄リスト」CSV は保有CSV（UC-001）とは別形式:
|   - ヘッダ行なし・1行目は "MS2","2" のメタ行
|   - 6列: 市場区分, 銘柄コード, フォルダ名, 取引所コード, 取引所ラベル, 銘柄名
|   - 保有数量・取得単価・口座区分を含まない
|   - CP932エンコード（呼び出し側が UTF-8 にデコードして parse() に渡す。
|     ImportCsvAction::decode() と同じ mb_convert_encoding(..., 'SJIS-win')）
|   - 改行は CRLF（`file(1)` の NEL 誤検出は SJIS ダブルバイトの後続バイト 0x85）
|
| parse() は保有CSVパーサ群と同じ「行単位のスキップ + 全体不能なら例外」契約
| （ParsedFavoriteFile { rows, skippedCount } / CsvStructureException）。
|
| 現時点で App\Services\Import\RakutenFavoriteCsvParser も
| App\Services\Import\Support\ParsedFavoriteRow / ParsedFavoriteFile も
| 存在しないため、下記のテストはすべて「クラス未定義」で失敗する（Red）。
|
| 純粋パーシングの Unit Test（DB/HTTP 依存なし）なので、既存の
| JpStockCsvParserTest と同様 Tests\TestCase にはバインドしない。
*/

/**
 * お気に入り銘柄CSVの1行を組み立てる（UTF-8、クォート付き6列）。
 */
function favRow(string $market, string $code, string $folder, string $exchangeCode, string $exchangeLabel, string $name): string
{
    return sprintf('"%s","%s","%s","%s","%s","%s"', $market, $code, $folder, $exchangeCode, $exchangeLabel, $name);
}

/**
 * 複数行を CRLF で連結し、1行目に楽天のメタ行 "MS2","2" を付ける。
 *
 * @param  array<int, string>  $rows
 */
function favCsv(array $rows, bool $withMetaLine = true): string
{
    $lines = [];

    if ($withMetaLine) {
        $lines[] = '"MS2","2"';
    }

    foreach ($rows as $row) {
        $lines[] = $row;
    }

    return implode("\r\n", $lines)."\r\n";
}

test('STK行は日本株の個別株として取り込まれる', function () {
    $csv = favCsv([
        favRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ'),
    ]);

    $parsed = (new RakutenFavoriteCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->code)->toBe('9433');
    expect($parsed->rows[0]->market)->toBe('jp');
    expect($parsed->rows[0]->instrumentType)->toBe('stock');
    expect($parsed->rows[0]->name)->toBe('ＫＤＤＩ');
    expect($parsed->rows[0]->folderName)->toBe('通信/情報系　日本株');
    expect($parsed->rows[0]->exchangeLabel)->toBe('東Ｐ');
    expect($parsed->skippedCount)->toBe(0);
});

test('USS行は米国株の個別株として取り込まれる', function () {
    $csv = favCsv([
        favRow('USS', 'NVDA', '通信/情報系　米国株', 'A9', '米国', 'エヌビディア'),
    ]);

    $parsed = (new RakutenFavoriteCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->code)->toBe('NVDA');
    expect($parsed->rows[0]->market)->toBe('us');
    expect($parsed->rows[0]->instrumentType)->toBe('stock');
    expect($parsed->rows[0]->name)->toBe('エヌビディア');
});

test('1行目のメタ行（MS2,2）は行としても スキップ件数としてもカウントされない', function () {
    $csv = favCsv([
        favRow('STK', '7203', '日本株　分散投資', '1', '東Ｐ', 'トヨタ自動車'),
    ]);

    $parsed = (new RakutenFavoriteCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->skippedCount)->toBe(0);
});

test('CFD行は対象外としてスキップされ、スキップ件数に数えられる', function () {
    $csv = favCsv([
        favRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ'),
        favRow('CFD', '113', 'アメリカ　ETF CFD', '', '', 'エネルギー・セレクト・セクターSPDRファンド'),
        favRow('CFD', '114', 'アメリカ　ETF CFD', '', '', 'バンガード・エネルギーETF'),
    ]);

    $parsed = (new RakutenFavoriteCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->code)->toBe('9433');
    expect($parsed->skippedCount)->toBe(2);
});

test('列数が6に満たない不正な行はスキップされ、スキップ件数に数えられる', function () {
    $csv = favCsv([
        favRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ'),
        '"STK","7616"',
    ]);

    $parsed = (new RakutenFavoriteCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->skippedCount)->toBe(1);
});

test('日本株・米国株・CFDが混在するCSVを正しく分類する', function () {
    $csv = favCsv([
        favRow('USS', 'ZM', '通信/情報系　米国株', 'A9', '米国', 'ズーム・コミュニケーションズ'),
        favRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ'),
        favRow('USS', 'WIT', 'インド株', 'A1', '米国', 'ウィプロ'),
        favRow('CFD', '115', 'アメリカ　ETF CFD', '', '', 'iシェアーズ 米国エネルギーETF'),
        favRow('STK', '7616', '日本株　食品関連', '1', '東Ｐ', 'コロワイド'),
    ]);

    $parsed = (new RakutenFavoriteCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(4);
    expect(collect($parsed->rows)->pluck('code')->all())->toBe(['ZM', '9433', 'WIT', '7616']);
    expect(collect($parsed->rows)->firstWhere('code', 'ZM')->market)->toBe('us');
    expect(collect($parsed->rows)->firstWhere('code', '9433')->market)->toBe('jp');
    expect($parsed->skippedCount)->toBe(1);
});

test('対象となる有効行が1件も無い場合はCsvStructureExceptionを投げる', function () {
    $csv = favCsv([
        favRow('CFD', '113', 'アメリカ　ETF CFD', '', '', 'エネルギー・セレクト・セクターSPDRファンド'),
        favRow('CFD', '114', 'アメリカ　ETF CFD', '', '', 'バンガード・エネルギーETF'),
    ]);

    expect(fn () => (new RakutenFavoriteCsvParser)->parse($csv))
        ->toThrow(CsvStructureException::class);
});

test('メタ行しか無い（実質空の）CSVはCsvStructureExceptionを投げる', function () {
    expect(fn () => (new RakutenFavoriteCsvParser)->parse("\"MS2\",\"2\"\r\n"))
        ->toThrow(CsvStructureException::class);
});

test('フォルダ名の全角スペース・スラッシュはそのまま保持される', function () {
    $csv = favCsv([
        favRow('STK', '5805', '日本株 トレンド国策銘柄', '1', '東Ｐ', 'ＳＷＣＣ'),
    ]);

    $parsed = (new RakutenFavoriteCsvParser)->parse($csv);

    expect($parsed->rows[0]->folderName)->toBe('日本株 トレンド国策銘柄');
});

test('末尾に空行やCRLFのみの行があっても無視される（スキップ件数に数えない）', function () {
    $csv = "\"MS2\",\"2\"\r\n"
        .favRow('STK', '9433', '通信/情報系　日本株', '1', '東Ｐ', 'ＫＤＤＩ')."\r\n"
        ."\r\n"
        ."   \r\n";

    $parsed = (new RakutenFavoriteCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->skippedCount)->toBe(0);
});

test('実際のお気に入り銘柄CSVをデコードしてパースすると216件・CFD4件スキップになる', function () {
    $path = dirname(__DIR__, 4).'/docs/original-docs/お気に入り銘柄CSV.csv';

    if (! is_file($path)) {
        $this->markTestSkipped('実ファイルが存在しません（開発環境限定の一次資料）');
    }

    $utf8 = mb_convert_encoding(file_get_contents($path), 'UTF-8', 'SJIS-win');

    $parsed = (new RakutenFavoriteCsvParser)->parse($utf8);

    expect($parsed->rows)->toHaveCount(216);
    expect($parsed->skippedCount)->toBe(4);
    expect(collect($parsed->rows)->where('market', 'jp')->count())->toBe(144);
    expect(collect($parsed->rows)->where('market', 'us')->count())->toBe(72);
    // STK/USS 行のフォルダ名は10種（CFD専用フォルダを除く。「アメリカ　ETF CFD」は
    // SPYD/HDV/VYM の USS 行があるため残る）。
    expect(collect($parsed->rows)->pluck('folderName')->unique()->values()->count())->toBe(10);
});
