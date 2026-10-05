<?php

namespace App\Actions\Import\Support;

/**
 * Result of previewing or importing the trade history CSVs (UC-017).
 * batchId is null for a preview and for a failure before saving.
 */
final class TradeHistoryImportSummary
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $batchId,
        public readonly int $totalRows,
        public readonly int $newRows,
        public readonly int $existingRows,
        public readonly int $missingRows,
        public readonly int $errorCount,
        public readonly ?string $periodFrom,
        public readonly ?string $periodTo,
        public readonly ?string $failureReason = null,
        public readonly ?TradeReconciliationSummary $reconciliation = null,
    ) {}

    public static function failure(?int $batchId, string $reason): self
    {
        return new self(false, $batchId, 0, 0, 0, 0, 0, null, null, $reason);
    }
}
