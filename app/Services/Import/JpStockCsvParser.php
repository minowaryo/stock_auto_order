<?php

namespace App\Services\Import;

use App\Exceptions\Import\CsvStructureException;
use App\Services\Import\Support\AccountTypeMapper;
use App\Services\Import\Support\CsvNumberParser;
use App\Services\Import\Support\ParsedCsvFile;
use App\Services\Import\Support\ParsedCsvRow;
use InvalidArgumentException;

/**
 * Parses a 楽天証券 国内株式CSV (JP stock CSV export).
 *
 * The file is split into account sections (■特定口座 / ■NISA成長投資枠 etc.),
 * each repeating its own column header row before the data rows
 * (docs/product/use-cases.md UC-001業務ルール). This parser scans line by
 * line and re-detects the header whenever it reappears, so it naturally
 * aggregates across every section without needing to know the section names.
 */
final class JpStockCsvParser
{
    private const HEADER_MARKER = '銘柄コード';

    private const COLUMN_NAME = '銘柄名';

    private const COLUMN_QUANTITY = '保有数量［株］';

    private const COLUMN_AVERAGE_COST = '平均取得価額［円］';

    private const COLUMN_CURRENT_PRICE = '現在値［円］';

    public function parse(string $utf8Content): ParsedCsvFile
    {
        $lines = preg_split('/\r\n|\r|\n/', $utf8Content) ?: [];

        $headerFound = false;
        $inDataSection = false;
        $columnIndex = [];
        $rows = [];
        $errorCount = 0;
        $pendingAccountLabel = null;
        $currentAccountType = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                $inDataSection = false;

                continue;
            }

            $fields = str_getcsv($line);
            $first = trim((string) ($fields[0] ?? ''));

            if ($first === self::HEADER_MARKER) {
                $headerFound = true;
                $inDataSection = true;
                $columnIndex = array_flip(array_map('trim', $fields));

                try {
                    $currentAccountType = AccountTypeMapper::toEnum((string) $pendingAccountLabel);
                } catch (InvalidArgumentException) {
                    throw new CsvStructureException(
                        "国内株式CSVの口座区分「{$pendingAccountLabel}」を認識できませんでした（想定外の見出しの可能性があります）"
                    );
                }

                continue;
            }

            // Section headings (e.g. "■特定口座", "■NISA成長投資枠") are captured
            // regardless of $inDataSection so the label is available the next
            // time a HEADER_MARKER row re-establishes the account section
            // (docs/adr/ADR-0002-nisa-account-type-tracking.md).
            if (str_starts_with($first, '■')) {
                $pendingAccountLabel = mb_substr($first, mb_strlen('■'));
                $inDataSection = false;

                continue;
            }

            if (! $inDataSection) {
                continue;
            }

            if ($first === '') {
                $inDataSection = false;

                continue;
            }

            try {
                $rows[] = new ParsedCsvRow(
                    code: $first,
                    name: trim((string) ($fields[$columnIndex[self::COLUMN_NAME]] ?? '')),
                    market: 'jp',
                    instrumentType: 'stock',
                    quantity: CsvNumberParser::parse((string) ($fields[$columnIndex[self::COLUMN_QUANTITY]] ?? '')),
                    averageCost: CsvNumberParser::parse((string) ($fields[$columnIndex[self::COLUMN_AVERAGE_COST]] ?? '')),
                    currentPrice: CsvNumberParser::parse((string) ($fields[$columnIndex[self::COLUMN_CURRENT_PRICE]] ?? '')),
                    accountType: (string) $currentAccountType,
                );
            } catch (InvalidArgumentException) {
                $errorCount++;
            }
        }

        if (! $headerFound) {
            throw new CsvStructureException(
                '国内株式CSVの形式を確認してください（文字コード・列構成が想定と異なる可能性があります）'
            );
        }

        return new ParsedCsvFile($rows, $errorCount);
    }
}
