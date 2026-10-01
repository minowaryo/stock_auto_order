<?php

namespace App\Services\Sector;

use App\Models\SectorClassification;

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
}
