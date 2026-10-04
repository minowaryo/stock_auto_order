<?php

namespace App\Services\Import;

use App\Exceptions\Import\CsvStructureException;
use App\Services\Import\Support\AccountTypeMapper;
use App\Services\Import\Support\CsvNumberParser;
use App\Services\Import\Support\ParsedTradeHistory;
use App\Services\Import\Support\ParsedTradeRow;
use InvalidArgumentException;

/**
 * Parses a 楽天証券 trade history CSV (国内株式 / 米国株式) into normalized
 * rows (UC-017, ADR-0027 D1).
 *
 * The files carry no trade ID or execution time, and identical rows do
 * occur, so each row gets a content hash over its normalized business
 * columns plus its occurrence number within the file. The hash never
 * includes the file name or line number, so re-submitting the full history
 * yields the same keys.
 */
final class TradeHistoryCsvParser
{
    private const JP_COLUMNS = [
        '約定日', '受渡日', '銘柄コード', '銘柄名', '口座区分', '取引区分', '売買区分',
        '数量［株］', '単価［円］', '手数料［円］', '受渡金額［円］',
    ];

    private const US_COLUMNS = [
        '約定日', '受渡日', 'ティッカー', '銘柄名', '口座', '取引区分', '売買区分', '決済通貨',
        '数量［株］', '単価［USドル］', '為替レート', '手数料［USドル］', '受渡金額［USドル］', '受渡金額［円］',
    ];

    private const JP_KINDS = [
        '買付' => 'buy',
        '売付' => 'sell',
        '入庫' => 'transfer_in',
        '出庫' => 'transfer_out',
    ];

    private const US_SETTLEMENT_CURRENCIES = [
        '円' => 'jpy',
        'ＵＳドル' => 'usd',
        'USドル' => 'usd',
    ];

    public function parseJp(string $utf8Content): ParsedTradeHistory
    {
        return $this->parse($utf8Content, 'jp', self::JP_COLUMNS);
    }

    public function parseUs(string $utf8Content): ParsedTradeHistory
    {
        return $this->parse($utf8Content, 'us', self::US_COLUMNS);
    }

    /**
     * @param  array<int, string>  $requiredColumns
     */
    private function parse(string $utf8Content, string $market, array $requiredColumns): ParsedTradeHistory
    {
        $lines = preg_split('/\r\n|\r|\n/', $utf8Content) ?: [];
        $header = null;
        $rows = [];
        $errorCount = 0;
        $occurrences = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            // Empty escape = RFC 4180 quoting (and no PHP 8.4+ deprecation notice).
            $cells = array_map('trim', str_getcsv($line, ',', '"', ''));

            if ($header === null) {
                $header = $cells;
                $missing = array_diff($requiredColumns, $header);

                if ($missing !== []) {
                    throw new CsvStructureException('Trade history CSV is missing columns: '.implode(', ', $missing));
                }

                continue;
            }

            try {
                $source = $this->sourceRow($header, $cells);
                $fields = $market === 'jp' ? $this->jpFields($source) : $this->usFields($source);
                $hash = $this->contentHash($market, $fields);
                $occurrences[$hash] = ($occurrences[$hash] ?? 0) + 1;

                $rows[] = new ParsedTradeRow(
                    market: $market,
                    symbolCode: $fields['symbolCode'],
                    symbolName: $fields['symbolName'],
                    tradeDate: $fields['tradeDate'],
                    settlementDate: $fields['settlementDate'],
                    accountType: $fields['accountType'],
                    kind: $fields['kind'],
                    isRoutine: $fields['isRoutine'],
                    quantity: $fields['quantity'],
                    unitPrice: $fields['unitPrice'],
                    priceCurrency: $market === 'jp' ? 'jpy' : 'usd',
                    settlementCurrency: $fields['settlementCurrency'],
                    settlementAmountJpy: $fields['settlementAmountJpy'],
                    settlementAmountUsd: $fields['settlementAmountUsd'],
                    fxRate: $fields['fxRate'],
                    feeAmount: $fields['feeAmount'],
                    contentHash: $hash,
                    occurrenceIndex: $occurrences[$hash],
                    sourceRow: $source,
                    lineNumber: $index + 1,
                );
            } catch (InvalidArgumentException) {
                $errorCount++;
            }
        }

        if ($header === null) {
            throw new CsvStructureException('Trade history CSV has no header row.');
        }

        return new ParsedTradeHistory($rows, $errorCount);
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string>  $cells
     * @return array<string, string>
     */
    private function sourceRow(array $header, array $cells): array
    {
        if (count($cells) < count($header)) {
            throw new InvalidArgumentException('Row has fewer cells than the header.');
        }

        return array_combine($header, array_slice($cells, 0, count($header)));
    }

    /**
     * @param  array<string, string>  $s
     * @return array<string, mixed>
     */
    private function jpFields(array $s): array
    {
        $kind = self::JP_KINDS[$s['売買区分']] ?? throw new InvalidArgumentException('Unknown 売買区分: '.$s['売買区分']);
        $amountJpy = $this->nullableNumber($s['受渡金額［円］']);

        return [
            'symbolCode' => $this->requireText($s['銘柄コード']),
            'symbolName' => $s['銘柄名'],
            'tradeDate' => $this->date($s['約定日']),
            'settlementDate' => $this->nullableDate($s['受渡日']),
            'accountType' => $this->accountType($s['口座区分']),
            'kind' => $kind,
            'isRoutine' => false,
            'quantity' => CsvNumberParser::parse($s['数量［株］']),
            'unitPrice' => $this->nullableNumber($s['単価［円］']),
            'settlementCurrency' => $amountJpy !== null ? 'jpy' : null,
            'settlementAmountJpy' => $amountJpy,
            'settlementAmountUsd' => null,
            'fxRate' => null,
            'feeAmount' => $this->nullableNumber($s['手数料［円］']),
            'category' => $s['取引区分'],
        ];
    }

    /**
     * @param  array<string, string>  $s
     * @return array<string, mixed>
     */
    private function usFields(array $s): array
    {
        $category = $s['取引区分'];

        if ($category === '積立') {
            $kind = 'tsumitate';
        } elseif ($category === '入庫（分割）') {
            $kind = 'split_in';
        } else {
            $kind = ['買付' => 'buy', '売付' => 'sell'][$s['売買区分']]
                ?? throw new InvalidArgumentException('Unknown 売買区分: '.$s['売買区分']);
        }

        $currencyLabel = $s['決済通貨'];
        $settlementCurrency = $currencyLabel === '-' || $currencyLabel === ''
            ? null
            : (self::US_SETTLEMENT_CURRENCIES[$currencyLabel] ?? throw new InvalidArgumentException('Unknown 決済通貨: '.$currencyLabel));

        return [
            'symbolCode' => $this->requireText($s['ティッカー']),
            'symbolName' => $s['銘柄名'],
            'tradeDate' => $this->date($s['約定日']),
            'settlementDate' => $this->nullableDate($s['受渡日']),
            'accountType' => $this->accountType($s['口座']),
            'kind' => $kind,
            'isRoutine' => $kind === 'tsumitate',
            'quantity' => CsvNumberParser::parse($s['数量［株］']),
            'unitPrice' => $this->nullableNumber($s['単価［USドル］']),
            'settlementCurrency' => $settlementCurrency,
            'settlementAmountJpy' => $this->nullableNumber($s['受渡金額［円］']),
            'settlementAmountUsd' => $this->nullableNumber($s['受渡金額［USドル］']),
            'fxRate' => $this->nullableNumber($s['為替レート']),
            'feeAmount' => $this->nullableNumber($s['手数料［USドル］']),
            'category' => $category,
        ];
    }

    private function requireText(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('Empty required text cell.');
        }

        return $value;
    }

    /**
     * The trade history CSVs label the general account "一般" (the holdings
     * CSV uses "一般口座"), so that alias is handled here instead of changing
     * AccountTypeMapper's behavior for the holdings import.
     */
    private function accountType(string $label): string
    {
        return $label === '一般' ? 'general' : AccountTypeMapper::toEnum($label);
    }

    /**
     * Dates arrive un-padded ("2022/7/5"); returns Y-m-d.
     */
    private function date(string $value): string
    {
        if (! preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("Invalid date: {$value}");
        }

        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }

    private function nullableDate(string $value): ?string
    {
        return $value === '' || $value === '-' ? null : $this->date($value);
    }

    private function nullableNumber(string $value): ?float
    {
        return $value === '' || $value === '-' ? null : CsvNumberParser::parse($value);
    }

    /**
     * SHA-256 over the normalized business columns. Numbers are rendered
     * without separators or trailing zeros and dates as Y-m-d, so formatting
     * jitter in the export does not change the hash.
     *
     * @param  array<string, mixed>  $f
     */
    private function contentHash(string $market, array $f): string
    {
        $parts = [
            $market,
            $f['tradeDate'],
            $f['settlementDate'] ?? '-',
            $f['symbolCode'],
            $f['accountType'],
            $f['kind'],
            $f['category'],
            $this->normalizedNumber($f['quantity']),
            $this->normalizedNumber($f['unitPrice']),
            $f['settlementCurrency'] ?? '-',
            $this->normalizedNumber($f['settlementAmountJpy']),
            $this->normalizedNumber($f['settlementAmountUsd']),
            $this->normalizedNumber($f['fxRate']),
            $this->normalizedNumber($f['feeAmount']),
        ];

        return hash('sha256', implode("\x1f", $parts));
    }

    private function normalizedNumber(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
