<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches sector info and financial statements from the J-Quants API V2
 * (APIキー方式 / x-api-keyヘッダー)。
 *
 * docs/adr/ADR-0005-jquants-api-v2-migration.md
 */
final class JQuantsClient implements JQuantsClientInterface
{
    private const BASE_URL = 'https://api.jquants.com/v2';

    /** @var array<string, array{code: string, name: string}>|null */
    private ?array $sectorInfoBySymbolCode = null;

    private ?RequestException $sectorMasterException = null;

    public function fetchSectorInfo(string $symbolCode): ?array
    {
        if ($this->sectorMasterException !== null) {
            throw $this->sectorMasterException;
        }

        if ($this->sectorInfoBySymbolCode === null) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => config('services.jquants.api_key'),
                ])->get(self::BASE_URL.'/equities/master')->throw();
            } catch (RequestException $exception) {
                $this->sectorMasterException = $exception;

                throw $exception;
            }

            $this->sectorInfoBySymbolCode = [];

            foreach ($response->json('data') ?? [] as $row) {
                $this->sectorInfoBySymbolCode[$row['Code']] = [
                    'code' => $row['S17'],
                    'name' => $row['S17Nm'],
                ];
            }
        }

        $jQuantsCode = strlen($symbolCode) === 4
            ? $symbolCode.'0'
            : $symbolCode;

        return $this->sectorInfoBySymbolCode[$jQuantsCode] ?? null;
    }

    public function fetchStatements(string $symbolCode, int $periods = 16): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.jquants.api_key'),
        ])->get(self::BASE_URL.'/fins/summary', [
            'code' => $symbolCode,
        ])->throw();

        $data = $response->json('data') ?? [];

        if (empty($data)) {
            return [];
        }

        // ADR-0030 D1: forecast-revision rows carry CurPerType/CurFYEn but no
        // actuals, so drop them before anything treats them as results.
        $data = array_values(array_filter(
            $data,
            fn (array $row) => ! str_starts_with($row['DocType'] ?? '', 'EarnForecastRevision')
                && ! str_starts_with($row['DocType'] ?? '', 'DividendForecastRevision'),
        ));

        usort($data, fn (array $a, array $b) => strcmp($b['DiscDate'], $a['DiscDate']));

        $data = array_slice($data, 0, $periods);

        return array_map(fn (array $row) => [
            'disclosed_date' => $row['DiscDate'],
            // ADR-0012: CurPerType (1Q/2Q/3Q/FY) and CurFYEn (fiscal year end)
            // are used to compare the two most recent full-year filings.
            'period_type' => $this->toStringOrNull($row['CurPerType'] ?? null),
            'fiscal_year_end' => $this->toStringOrNull($row['CurFYEn'] ?? null),
            'net_sales' => $this->toFloatOrNull($row['Sales'] ?? null),
            'operating_profit' => $this->toFloatOrNull($row['OP'] ?? null),
            'profit' => $this->toFloatOrNull($row['NP'] ?? null),
            'eps' => $this->toFloatOrNull($row['EPS'] ?? null),
            'book_value_per_share' => $this->toFloatOrNull($row['BPS'] ?? null),
            'equity_to_asset_ratio' => $this->toFloatOrNull($row['EqAR'] ?? null),
            'roe' => $this->toFloatOrNull($row['ROE'] ?? null),
            'dividend_per_share_annual' => $this->toFloatOrNull($row['DivAnn'] ?? null),
            'payout_ratio_annual' => $this->toFloatOrNull($row['PayoutRatioAnn'] ?? null),
        ], $data);
    }

    private function toFloatOrNull(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function toStringOrNull(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
