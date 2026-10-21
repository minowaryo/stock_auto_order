<?php

namespace App\Services\Research;

use App\Support\ResearchSymbolCode;
use Carbon\CarbonImmutable;
use Normalizer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;
use ZipArchive;

/** Reads a complete, bounded official snapshot before any row is published. */
final class ResearchListingCatalogParser
{
    public static function normalizeName(string $name): string
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);
        if ($normalized === false) {
            throw new RuntimeException('catalog_invalid_name');
        }

        return trim(preg_replace('/\s+/u', ' ', $normalized));
    }

    public function parse(string $key, string $body, string $url): array
    {
        return $key === 'jpx_tse' ? $this->jpx($body, $url) : $this->sec($body, $url);
    }

    private function sec(string $body, string $url): array
    {
        $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $fields = $input['fields'] ?? [];
        if (! is_array($fields) || count($fields) !== count(array_unique($fields))
            || array_diff(['cik', 'name', 'ticker', 'exchange'], $fields) !== []
            || ! is_array($input['data'] ?? null) || count($input['data']) < 1 || count($input['data']) > 50000) {
            throw new RuntimeException('catalog_invalid_structure');
        }
        $entries = [];
        $symbols = [];
        foreach ($input['data'] as $row) {
            if (! is_array($row) || count($row) !== count($fields)) {
                throw new RuntimeException('catalog_invalid_row');
            }
            $row = array_combine($fields, $row);
            $cik = $row['cik'];
            if ((! is_int($cik) && ! is_string($cik)) || ! preg_match('/^[0-9]{1,10}$/D', (string) $cik) || (int) $cik < 1
                || ! is_string($row['name']) || ! is_string($row['ticker'])
                || ($row['exchange'] !== null && ! is_string($row['exchange']))) {
                throw new RuntimeException('catalog_invalid_row');
            }
            $exchange = $row['exchange'] ?? 'unknown';
            $entry = $this->entry('us', $row['ticker'], $row['name'], (string) (int) $cik, $exchange, 'equity', $url);
            if (isset($symbols[$entry['symbol_code']])) {
                throw new RuntimeException('catalog_duplicate_symbol');
            }
            $symbols[$entry['symbol_code']] = true;
            $entries[] = $entry;
        }

        return ['as_of_on' => null, 'entries' => $entries];
    }

    private function jpx(string $body, string $url): array
    {
        $path = tempnam(sys_get_temp_dir(), 'research-jpx-');
        if ($path === false) {
            throw new RuntimeException('catalog_temporary_file_failed');
        }
        $book = null;
        try {
            if (file_put_contents($path, $body) !== strlen($body)) {
                throw new RuntimeException('catalog_temporary_file_failed');
            }
            $this->validateZip($path);
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $info = $reader->listWorksheetInfo($path);
            if (count($info) !== 1 || $info[0]['totalRows'] > 50000 || $info[0]['totalColumns'] > 16) {
                throw new RuntimeException('catalog_workbook_limit');
            }
            $reader->setLoadSheetsOnly($info[0]['worksheetName']);
            $book = $reader->load($path);
            $sheet = $book->getSheet(0);
            foreach (['A1' => '日付', 'B1' => 'コード', 'C1' => '銘柄名', 'D1' => '市場・商品区分'] as $cell => $expected) {
                if ($sheet->getCell($cell)->getDataType() === DataType::TYPE_FORMULA
                    || $sheet->getCell($cell)->getValue() !== $expected) {
                    throw new RuntimeException('catalog_invalid_header');
                }
            }
            $entries = [];
            $asOf = null;
            $symbols = [];
            for ($row = 2; $row <= $info[0]['totalRows']; $row++) {
                $values = [];
                foreach (['A', 'B', 'C', 'D'] as $column) {
                    $cell = $sheet->getCell($column.$row);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        throw new RuntimeException('catalog_formula');
                    }
                    $values[] = $cell->getValue();
                }
                if (array_filter($values, fn ($value) => $value !== null && $value !== '') === []) {
                    continue;
                }
                [$date, $symbol, $name, $market] = $values;
                if (! is_string($name) || ! is_string($market) || ! is_scalar($date) || ! is_scalar($symbol)
                    || ! preg_match('/^[0-9]{8}$/D', (string) $date)) {
                    throw new RuntimeException('catalog_invalid_row');
                }
                $parsedDate = CarbonImmutable::createFromFormat('!Ymd', (string) $date, 'UTC');
                if (! $parsedDate || $parsedDate->format('Ymd') !== (string) $date || $parsedDate->isAfter(CarbonImmutable::now('UTC')->startOfDay())) {
                    throw new RuntimeException('catalog_invalid_date');
                }
                $currentDate = $parsedDate->toDateString();
                if ($asOf !== null && $asOf !== $currentDate) {
                    throw new RuntimeException('catalog_inconsistent_date');
                }
                $asOf = $currentDate;
                $issuerKey = hash('sha256', $currentDate.'|jp|'.$symbol.'|'.$name);
                $entry = $this->entry('jp', (string) $symbol, $name, $issuerKey, 'TSE', $market, $url);
                if (isset($symbols[$entry['symbol_code']])) {
                    throw new RuntimeException('catalog_duplicate_symbol');
                }
                $symbols[$entry['symbol_code']] = true;
                $entries[] = $entry;
            }
            if ($entries === []) {
                throw new RuntimeException('catalog_empty');
            }

            return ['as_of_on' => $asOf, 'entries' => $entries];
        } finally {
            $book?->disconnectWorksheets();
            unlink($path);
        }
    }

    private function validateZip(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('catalog_invalid_zip');
        }
        try {
            if ($zip->numFiles > 256) {
                throw new RuntimeException('catalog_zip_limit');
            }
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false || str_contains($stat['name'], 'externalLinks/') || ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new RuntimeException('catalog_invalid_zip');
                }
                $expanded += $stat['size'];
                if ($expanded > 64 * 1024 * 1024) {
                    throw new RuntimeException('catalog_zip_limit');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function entry(string $market, string $symbol, string $name, string $issuer, string $exchange, string $type, string $url): array
    {
        $code = ResearchSymbolCode::normalize($market, $symbol);
        // JPX formally retains a nonzero reserve digit for another security;
        // preserve that five-character code instead of turning it into its ordinary share.
        if ($code === null && $market === 'jp' && preg_match('/^([0-9A-Z]{4})[1-9]$/D', $symbol, $parts)
            && ResearchSymbolCode::normalize('jp', $parts[1]) !== null) {
            $code = $symbol;
        }
        $normalized = self::normalizeName($name);
        if ($code === null || $normalized === '' || mb_strlen($name) > 255 || mb_strlen($normalized) > 255
            || $exchange === '' || mb_strlen($exchange) > 80 || $type === '' || mb_strlen($type) > 80) {
            throw new RuntimeException('catalog_invalid_row');
        }

        return [
            'market' => $market, 'symbol_code' => $code, 'issuer_key' => $issuer,
            'legal_name' => $name, 'normalized_name' => $normalized, 'name_hash' => hash('sha256', $normalized),
            'exchange' => $exchange, 'security_type' => $type, 'reference_url' => $url,
        ];
    }
}
