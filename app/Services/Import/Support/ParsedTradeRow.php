<?php

namespace App\Services\Import\Support;

/**
 * A single successfully parsed data row from a 楽天証券 trade history CSV
 * (UC-017, ADR-0027 D1). "-" cells are null, never 0.
 */
final class ParsedTradeRow
{
    /**
     * @param  'jp'|'us'  $market
     * @param  'buy'|'sell'|'transfer_in'|'transfer_out'|'tsumitate'|'split_in'  $kind
     * @param  'jpy'|'usd'  $priceCurrency
     * @param  'jpy'|'usd'|null  $settlementCurrency
     * @param  array<string, string>  $sourceRow  column name => original cell text
     */
    public function __construct(
        public readonly string $market,
        public readonly string $symbolCode,
        public readonly string $symbolName,
        public readonly string $tradeDate,
        public readonly ?string $settlementDate,
        public readonly string $accountType,
        public readonly string $kind,
        public readonly bool $isRoutine,
        public readonly float $quantity,
        public readonly ?float $unitPrice,
        public readonly string $priceCurrency,
        public readonly ?string $settlementCurrency,
        public readonly ?float $settlementAmountJpy,
        public readonly ?float $settlementAmountUsd,
        public readonly ?float $fxRate,
        public readonly ?float $feeAmount,
        public readonly string $contentHash,
        public readonly int $occurrenceIndex,
        public readonly array $sourceRow,
        public readonly int $lineNumber,
    ) {}
}
