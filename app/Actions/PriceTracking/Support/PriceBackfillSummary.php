<?php

namespace App\Actions\PriceTracking\Support;

/**
 * Outcome counts of one price backfill run (UC-018, ADR-0024 D5).
 */
final class PriceBackfillSummary
{
    public function __construct(
        public readonly int $targets,
        public readonly int $skipped,
        public readonly int $ok = 0,
        public readonly int $notFound = 0,
        public readonly int $empty = 0,
        public readonly int $failed = 0,
        public readonly int $indicesOk = 0,
        public readonly int $indicesFailed = 0,
        public readonly bool $aborted = false,
    ) {}
}
