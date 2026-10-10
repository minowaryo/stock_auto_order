<?php

namespace App\Actions\PriceTracking\Support;

/**
 * Outcome counts of one weekly price tracking run (UC-018, ADR-0024 D2).
 */
final class PriceTrackingSummary
{
    public function __construct(
        public readonly int $targets,
        public readonly int $savedAlready = 0,
        public readonly int $refetchedForSplits = 0,
        public readonly int $ok = 0,
        public readonly int $notFound = 0,
        public readonly int $empty = 0,
        public readonly int $failed = 0,
        public readonly int $completed = 0,
        public readonly int $indicesOk = 0,
        public readonly int $indicesFailed = 0,
        public readonly bool $aborted = false,
    ) {}
}
