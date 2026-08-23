<?php

namespace App\Services\Import;

use App\Exceptions\Import\CsvStructureException;
use App\Services\Import\Support\AccountTypeMapper;
use App\Services\Import\Support\CsvNumberParser;
use App\Services\Import\Support\ParsedCsvFile;
use App\Services\Import\Support\ParsedCsvRow;
use InvalidArgumentException;

/**
 * Parses a 楽天証券 米国株式CSV (US stock CSV export).
 *
 * Values are converted from USD to JPY at parse time using the reference FX
 * rate printed in the file's header (UC-001業務ルール).
 */
final class UsStockCsvParser
{
    private const HEADER_MARKER = 'ティッカー';

    private const COLUMN_NAME = '銘柄名';

    private const COLUMN_QUANTITY = '保有数量［株］';

    private const COLUMN_AVERAGE_COST = '平均取得価額［USドル］';

    private const COLUMN_CURRENT_PRICE = '現在値［USドル］';

    private const FX_RATE_MARKER = '参考為替レート(米ドル)';

    public function parse(string $utf8Content): ParsedCsvFile
    {
        $lines = preg_split('/\r\n|\r|\n/', $utf8Content) ?: [];

        $fxRate = $this->extractFxRate($lines);

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
                        "米国株式CSVの口座区分「{$pendingAccountLabel}」を認識できませんでした（想定外の見出しの可能性があります）"
                    );
                }

                continue;
            }

            // Section headings (e.g. "■特定口座", "■一般口座", "■NISA成長投資枠")
            // are captured regardless of $inDataSection so the label is
            // available the next time a HEADER_MARKER row re-establishes the
            // account section (docs/adr/ADR-0002-nisa-account-type-tracking.md).
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

            if ($fxRate === null) {
                // Cannot convert to JPY without a rate; skip and count as an error.
                $errorCount++;

                continue;
            }

            try {
                $quantity = CsvNumberParser::parse((string) ($fields[$columnIndex[self::COLUMN_QUANTITY]] ?? ''));
                $averageCostUsd = CsvNumberParser::parse((string) ($fields[$columnIndex[self::COLUMN_AVERAGE_COST]] ?? ''));
                $currentPriceUsd = CsvNumberParser::parse((string) ($fields[$columnIndex[self::COLUMN_CURRENT_PRICE]] ?? ''));

                $rows[] = new ParsedCsvRow(
                    code: $first,
                    name: trim((string) ($fields[$columnIndex[self::COLUMN_NAME]] ?? '')),
                    market: 'us',
                    instrumentType: 'stock',
                    quantity: $quantity,
                    averageCost: $averageCostUsd * $fxRate,
                    currentPrice: $currentPriceUsd * $fxRate,
                    accountType: (string) $currentAccountType,
                    fxRateUsed: $fxRate,
                );
            } catch (InvalidArgumentException) {
                $errorCount++;
            }
        }

        if (! $headerFound) {
            throw new CsvStructureException(
                '米国株式CSVの形式を確認してください（文字コード・列構成が想定と異なる可能性があります）'
            );
        }

        if ($fxRate === null) {
            throw new CsvStructureException('米国株式CSVの参考為替レートを取得できませんでした');
        }

        return new ParsedCsvFile($rows, $errorCount);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function extractFxRate(array $lines): ?float
    {
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = array_map('trim', str_getcsv($line));
            $markerIndex = array_search(self::FX_RATE_MARKER, $fields, true);

            if ($markerIndex === false || ! isset($fields[$markerIndex + 1])) {
                continue;
            }

            try {
                return CsvNumberParser::parse($fields[$markerIndex + 1]);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }
}
