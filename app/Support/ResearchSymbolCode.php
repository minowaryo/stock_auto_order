<?php

namespace App\Support;

/**
 * Normalizes a symbol code typed for a UC-016 listing so it matches the codes
 * stored from the Rakuten CSVs (jp: 4 characters such as 7203 / 523A,
 * us: plain ticker such as NVDA / BRK.B). Exchange suffixes like ".T" are
 * rejected because the price clients add them themselves.
 */
final class ResearchSymbolCode
{
    private const PATTERNS = [
        'jp' => '/^[0-9][0-9A-Z]{3}$/',
        'us' => '/^[A-Z][A-Z0-9.\-]{0,9}$/',
    ];

    /** Returns the normalized code, or null when it is not a valid code for the market. */
    public static function normalize(?string $market, ?string $code): ?string
    {
        $normalized = mb_strtoupper(trim((string) $code));
        $pattern = self::PATTERNS[$market] ?? null;

        return $pattern !== null && preg_match($pattern, $normalized) === 1 ? $normalized : null;
    }
}
