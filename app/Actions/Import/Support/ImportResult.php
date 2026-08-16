<?php

namespace App\Actions\Import\Support;

use App\Actions\Import\ImportCsvAction;
use Illuminate\Support\Carbon;

/**
 * Outcome of {@see ImportCsvAction}, letting the
 * Controller decide the HTTP response without reaching back into models.
 */
final class ImportResult
{
    private function __construct(
        public readonly bool $success,
        public readonly int $importBatchId,
        public readonly int $importedCount = 0,
        public readonly int $errorCount = 0,
        public readonly ?Carbon $importedAt = null,
        /** @var array<int, string> */
        public readonly array $newlyDetectedSymbols = [],
        public readonly ?string $failureReason = null,
    ) {}

    public static function success(
        int $importBatchId,
        int $importedCount,
        int $errorCount,
        Carbon $importedAt,
        array $newlyDetectedSymbols,
    ): self {
        return new self(
            success: true,
            importBatchId: $importBatchId,
            importedCount: $importedCount,
            errorCount: $errorCount,
            importedAt: $importedAt,
            newlyDetectedSymbols: $newlyDetectedSymbols,
        );
    }

    public static function failure(int $importBatchId, string $failureReason): self
    {
        return new self(
            success: false,
            importBatchId: $importBatchId,
            failureReason: $failureReason,
        );
    }
}
