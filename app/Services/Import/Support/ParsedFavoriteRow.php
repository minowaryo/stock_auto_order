<?php

namespace App\Services\Import\Support;

/**
 * A single watchlist entry parsed from a 楽天証券「お気に入り銘柄リスト」CSV
 * (F-012 / UC-012 / ADR-0013).
 *
 * Unlike App\Services\Import\Support\ParsedCsvRow (the 保有CSV row), this
 * carries no quantity / cost / account type — the favorites CSV only lists
 * the symbol plus the user's folder classification.
 */
final class ParsedFavoriteRow
{
    public function __construct(
        public readonly string $code,
        public readonly string $market,
        public readonly string $instrumentType,
        public readonly string $name,
        public readonly ?string $folderName,
        public readonly ?string $exchangeLabel,
    ) {}
}
