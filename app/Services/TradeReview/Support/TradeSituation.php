<?php

namespace App\Services\TradeReview\Support;

/**
 * The situation a discretionary trade was made in (UC-019 基本フロー1・2・4):
 * the band on each of the three axes, and whether both price axes came from
 * the indicators recorded at the time or were recomputed from weekly closes.
 */
final class TradeSituation
{
    /**
     * @param  'near_high'|'pullback'|'unknown'  $highRatioBand
     * @param  'strong'|'weak'|'unknown'  $relativeStrengthBand
     * @param  'none'|'lt1'|'1to5'|'gt5'  $holdingBand
     * @param  'recorded'|'recomputed'  $source
     */
    public function __construct(
        public readonly int $tradeId,
        public readonly string $highRatioBand,
        public readonly string $relativeStrengthBand,
        public readonly string $holdingBand,
        public readonly string $source,
    ) {}
}
