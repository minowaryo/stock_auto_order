<?php

namespace App\Services\MarketData;

/**
 * Result of one Yahoo chart fetch for the one-time price backfill
 * (ADR-0024 D5): weekly bars, stock splits and a status that tells apart
 * "no such symbol", "no usable data" and "the fetch itself failed".
 */
final class PriceHistory
{
    public const OK = 'ok';

    public const EMPTY = 'empty';

    public const NOT_FOUND = 'not_found';

    public const FAILED = 'failed';

    /**
     * @param  'ok'|'empty'|'not_found'|'failed'  $status
     * @param  list<array{date: string, close: float, volume: int}>  $rows
     * @param  list<array{date: string, numerator: int, denominator: int}>  $splits  ascending by date
     * @param  bool  $splitsIncomplete  a split was dropped (not whole-number or unreadable)
     */
    public function __construct(
        public readonly string $status,
        public readonly array $rows = [],
        public readonly array $splits = [],
        public readonly bool $splitsIncomplete = false,
        public readonly ?string $message = null,
    ) {}
}
