<?php

namespace App\Services\Sector;

use App\Models\Holding;
use App\Models\SectorClassification;
use App\Services\MarketData\FinnhubClientInterface;
use App\Services\MarketData\JQuantsClientInterface;

/**
 * CHG-0029 / ADR-0020: single place that creates/looks up sector rows per
 * market, shared by FetchExternalMarketDataAction and sectors:backfill.
 */
class SectorClassificationResolver
{
    public const FUND_CATEGORY_NAME = '投資信託';

    /**
     * @param  array{code: string, name: string}  $sectorInfo  J-Quants 17業種
     */
    public function forJpSector(array $sectorInfo): SectorClassification
    {
        return SectorClassification::firstOrCreate(
            ['market' => 'jp', 'name' => $sectorInfo['name']],
            ['code' => $sectorInfo['code']],
        );
    }

    public function forUsIndustry(string $industry): SectorClassification
    {
        return SectorClassification::firstOrCreate(['market' => 'us', 'name' => $industry]);
    }

    public function forMutualFund(): SectorClassification
    {
        return SectorClassification::firstOrCreate(['market' => 'mutual_fund', 'name' => self::FUND_CATEGORY_NAME]);
    }

    /**
     * Resolves the sector for a holding from its market's source (J-Quants for
     * JP stocks, Finnhub industry for US stocks, the fixed category for funds).
     * Returns null when the source does not know the symbol. API failures are
     * not caught here — callers decide how to degrade (CHG-0044, ADR-0020 D6).
     */
    public function classify(Holding $holding, JQuantsClientInterface $jQuantsClient, FinnhubClientInterface $finnhubClient): ?SectorClassification
    {
        if ($holding->instrument_type === 'mutual_fund') {
            return $this->forMutualFund();
        }

        if ($holding->market === 'jp') {
            $info = $jQuantsClient->fetchSectorInfo($holding->symbol_code);

            return $info !== null ? $this->forJpSector($info) : null;
        }

        if ($holding->market === 'us') {
            $industry = $finnhubClient->fetchIndustry($holding->symbol_code);

            return $industry !== null ? $this->forUsIndustry($industry) : null;
        }

        return null;
    }
}
