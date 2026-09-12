<?php

namespace App\Actions\Watchlist\Support;

use App\Actions\Watchlist\ImportFavoriteCsvAction;

/**
 * Outcome of {@see ImportFavoriteCsvAction}: lets the caller (Livewire
 * component / controller) decide the response without reaching into models.
 */
final class FavoriteImportResult
{
    private function __construct(
        public readonly bool $success,
        public readonly int $registeredCount = 0,
        public readonly int $skippedCount = 0,
        public readonly ?string $failureReason = null,
    ) {}

    public static function success(int $registeredCount, int $skippedCount): self
    {
        return new self(success: true, registeredCount: $registeredCount, skippedCount: $skippedCount);
    }

    public static function failure(string $failureReason): self
    {
        return new self(success: false, failureReason: $failureReason);
    }
}
