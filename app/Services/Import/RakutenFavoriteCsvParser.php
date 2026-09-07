<?php

namespace App\Services\Import;

use App\Exceptions\Import\CsvStructureException;
use App\Services\Import\Support\ParsedFavoriteFile;
use App\Services\Import\Support\ParsedFavoriteRow;

/**
 * Parses a 楽天証券「お気に入り銘柄リスト」CSV (F-012 / UC-012 / ADR-0013).
 *
 * Format (see docs/adr/ADR-0013-favorites-watchlist.md「CSV の実態」):
 *   - no header row; line 1 is a "MS2","2" meta line
 *   - 6 quoted columns: 市場区分, 銘柄コード, フォルダ名, 取引所コード,
 *     取引所ラベル, 銘柄名
 *   - no quantity / cost / account type
 *   - CP932-encoded (the caller decodes to UTF-8 before calling parse(),
 *     same as App\Actions\Import\ImportCsvAction::decode())
 *   - CRLF line endings
 *
 * Only `STK` (日本株) and `USS` (米国株) rows are in scope. `CFD` and any
 * other 市場区分, plus structurally invalid rows, are skipped and counted in
 * `skippedCount`. If no in-scope row is found at all, a CsvStructureException
 * is thrown (same 全体不能→例外 / 行単位→スキップ contract as the 保有CSV
 * parsers).
 */
final class RakutenFavoriteCsvParser
{
    private const META_MARKET = 'MS2';

    /**
     * 市場区分 → holdings.market の対応。ここに無い市場区分の行はスキップする。
     */
    private const MARKET_MAP = [
        'STK' => 'jp',
        'USS' => 'us',
    ];

    private const COLUMN_COUNT = 6;

    public function parse(string $utf8Content): ParsedFavoriteFile
    {
        $lines = preg_split('/\r\n|\r|\n/', $utf8Content) ?: [];

        $rows = [];
        $skippedCount = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = array_map('trim', str_getcsv($line));
            $market = $fields[0] ?? '';

            // 1行目の "MS2","2" メタ行は行にもスキップ件数にも数えない。
            if ($market === self::META_MARKET) {
                continue;
            }

            if (count($fields) < self::COLUMN_COUNT) {
                $skippedCount++;

                continue;
            }

            if (! array_key_exists($market, self::MARKET_MAP)) {
                $skippedCount++;

                continue;
            }

            $rows[] = new ParsedFavoriteRow(
                code: $fields[1],
                market: self::MARKET_MAP[$market],
                instrumentType: 'stock',
                name: $fields[5],
                folderName: $fields[2] !== '' ? $fields[2] : null,
                exchangeLabel: $fields[4] !== '' ? $fields[4] : null,
            );
        }

        if ($rows === []) {
            throw new CsvStructureException(
                'お気に入り銘柄CSVの形式を確認してください（対象の銘柄行が1件も見つかりませんでした）'
            );
        }

        return new ParsedFavoriteFile($rows, $skippedCount);
    }
}
