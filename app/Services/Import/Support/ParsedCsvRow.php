<?php

namespace App\Services\Import\Support;

/**
 * A single successfully parsed data row from a 楽天証券 CSV, already
 * converted to numeric values (and, for US stock rows, already converted to
 * JPY using the CSV's reference FX rate).
 */
final class ParsedCsvRow
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $market,
        public readonly string $instrumentType,
        public readonly float $quantity,
        public readonly float $averageCost,
        public readonly float $currentPrice,
        public readonly string $accountType,
        public readonly ?float $fxRateUsed = null,
    ) {}
}
