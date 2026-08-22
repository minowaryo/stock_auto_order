<?php

namespace App\Services\MarketData;

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

    public function fetchSectorInfo(string $symbolCode): ?array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.jquants.api_key'),
        ])->get(self::BASE_URL.'/equities/master', [
            'code' => $symbolCode,
        ]);

        $data = $response->json('data') ?? [];

        if (empty($data)) {
            return null;
        }

        $row = $data[0];

        return [
            'code' => $row['S17'],
            'name' => $row['S17Nm'],
        ];
    }

    public function fetchStatements(string $symbolCode, int $periods = 5): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.jquants.api_key'),
        ])->get(self::BASE_URL.'/fins/summary', [
            'code' => $symbolCode,
        ]);

        $data = $response->json('data') ?? [];

        if (empty($data)) {
            return [];
        }

        usort($data, fn (array $a, array $b) => strcmp($b['DiscDate'], $a['DiscDate']));

        $data = array_slice($data, 0, $periods);

        return array_map(fn (array $row) => [
            'disclosed_date' => $row['DiscDate'],
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
}
