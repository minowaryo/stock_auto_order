<?php

namespace App\Services\Import;

use App\Exceptions\Import\CsvStructureException;
use App\Services\Import\Support\AccountTypeMapper;
use App\Services\Import\Support\CsvNumberParser;
use App\Services\Import\Support\ParsedCsvFile;
use App\Services\Import\Support\ParsedCsvRow;
use InvalidArgumentException;

/**
 * Parses a 楽天証券 投資信託CSV (mutual fund CSV export).
 *
 * Unlike the stock CSVs, this is a single flat table with no account-section
 * splitting (UC-001業務ルール). The fund name itself is used as both the
 * symbol code and the symbol name, since 楽天証券 does not assign a short
 * code to mutual funds.
 */
final class MutualFundCsvParser
{
    private const HEADER_MARKER = '投資信託種別';

    private const COLUMN_ACCOUNT_TYPE = '口座区分';

    private const COLUMN_FUND_NAME = 'ファンド';

    private const COLUMN_QUANTITY = '保有数量[口]';

    private const COLUMN_AVERAGE_COST = '平均取得価額[円]';

    private const COLUMN_BASE_PRICE = '基準価額[円]';

    public function parse(string $utf8Content): ParsedCsvFile
    {
        $lines = preg_split('/\r\n|\r|\n/', $utf8Content) ?: [];

        $headerFound = false;
        $inDataSection = false;
        $columnIndex = [];
        $rows = [];
        $errorCount = 0;

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

                continue;
            }

            if (! $inDataSection) {
                continue;
            }

            try {
                $fundName = trim((string) ($fields[$columnIndex[self::COLUMN_FUND_NAME]] ?? ''));

                if ($fundName === '') {
                    throw new InvalidArgumentException('Missing fund name');
                }

                $accountType = AccountTypeMapper::toEnum(
                    trim((string) ($fields[$columnIndex[self::COLUMN_ACCOUNT_TYPE]] ?? ''))
                );

                $rows[] = new ParsedCsvRow(
                    code: $fundName,
                    name: $fundName,
                    market: 'mutual_fund',
                    instrumentType: 'mutual_fund',
                    quantity: CsvNumberParser::parse((string) ($fields[$columnIndex[self::COLUMN_QUANTITY]] ?? '')),
                    averageCost: CsvNumberParser::parse((string) ($fields[$columnIndex[self::COLUMN_AVERAGE_COST]] ?? '')),
                    currentPrice: CsvNumberParser::parse((string) ($fields[$columnIndex[self::COLUMN_BASE_PRICE]] ?? '')),
                    accountType: $accountType,
                );
            } catch (InvalidArgumentException) {
                $errorCount++;
            }
        }

        if (! $headerFound) {
            throw new CsvStructureException(
                '投資信託CSVの形式を確認してください（文字コード・列構成が想定と異なる可能性があります）'
            );
        }

        return new ParsedCsvFile($rows, $errorCount);
    }
}
