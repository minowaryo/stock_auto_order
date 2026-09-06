<?php

namespace App\Services\Portfolio;

/**
 * Estimates how many consecutive weekly snapshots a holding has appeared in,
 * counting back from the most recent one (UC-011 / F-011,
 * continuous_holding_weeks / continuous_holding_weeks_is_truncated).
 *
 * "Truncated" means the consecutive run reaches the oldest snapshot we have,
 * i.e. observation began mid-hold and the real holding period is at least
 * this long (probably longer).
 *
 * Pure calculation logic only — the snapshot/holding_snapshots queries live
 * in the calling Action.
 */
final class ContinuousHoldingWeeksCalculator
{
    /**
     * @param  array<int>  $allSnapshotIdsNewestFirst  every snapshot id, ordered snapshotted_at desc, id desc
     * @param  array<int>  $holdingPresentSnapshotIds  snapshot ids where this holding has a holding_snapshots row (order irrelevant)
     * @return array{weeks: int, is_truncated: bool}
     */
    public function calculate(array $allSnapshotIdsNewestFirst, array $holdingPresentSnapshotIds): array
    {
        if ($allSnapshotIdsNewestFirst === []) {
            return ['weeks' => 0, 'is_truncated' => false];
        }

        $present = array_flip($holdingPresentSnapshotIds);

        $weeks = 0;

        foreach ($allSnapshotIdsNewestFirst as $snapshotId) {
            if (! isset($present[$snapshotId])) {
                break;
            }

            $weeks++;
        }

        return [
            'weeks' => $weeks,
            'is_truncated' => $weeks > 0 && $weeks === count($allSnapshotIdsNewestFirst),
        ];
    }
}
