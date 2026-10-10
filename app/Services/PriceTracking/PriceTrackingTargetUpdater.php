<?php

namespace App\Services\PriceTracking;

use App\Models\Holding;
use App\Models\PriceTrackingTarget;
use App\Models\WeeklyPrice;
use App\Services\MarketData\PriceHistory;
use App\Services\MarketData\WeekDateNormalizer;
use Illuminate\Support\Carbon;

/**
 * Keeps price_tracking_targets: the tracking deadline of a holding and the
 * outcome of every fetch (UC-018, ADR-0024 D1・D2, ADR-0027 D7).
 *
 * applyFetch() must be called AFTER the caller saved the rows with
 * WeeklyPriceRecorder::recordHolding(). That method swallows DB errors, so
 * "it returned" proves nothing; applyFetch() checks that every confirmed
 * week of the history is really in weekly_prices before it counts a fetch
 * as a success.
 */
class PriceTrackingTargetUpdater
{
    private const TRACK_WEEKS = 26;

    /** A holding becomes unavailable after this many failures in a row, if the last one was "no such data". */
    private const UNAVAILABLE_AFTER = 3;

    private const MAX_FAILURES = 255;

    public function __construct(private readonly WeekDateNormalizer $weeks) {}

    /**
     * Registers / extends the deadline: the later of the last sale and the
     * last signal week plus 26 weeks. A deadline is extended, never shortened.
     */
    public function register(Holding $holding, ?string $lastSellDate, ?string $lastSignalWeek): PriceTrackingTarget
    {
        $target = $this->target($holding);

        $target->last_sell_week = $this->later($target->last_sell_week?->toDateString(), $lastSellDate);
        $target->last_signal_week = $this->later($target->last_signal_week?->toDateString(), $lastSignalWeek);

        $latest = $this->later($target->last_sell_week?->toDateString(), $target->last_signal_week?->toDateString());
        $target->track_until_week = $latest === null ? null : Carbon::parse($latest)->addWeeks(self::TRACK_WEEKS)->toDateString();

        // A completed target whose deadline moved beyond what is saved needs tracking again.
        if ($target->status === 'completed' && $target->track_until_week !== null
            && ($target->latest_saved_week === null || $target->track_until_week->gt($target->latest_saved_week))) {
            $target->status = 'active';
        }

        $target->save();

        return $target;
    }

    /**
     * Applies the outcome of one price fetch for the holding.
     *
     * $splitsFetched: whether the fetch asked Yahoo for splits. A fetch
     * without splits always reads "not incomplete", so it must not clear a
     * marker set by an earlier fetch that did ask (e.g. the backfill).
     */
    public function applyFetch(Holding $holding, PriceHistory $history, bool $backfill = false, bool $splitsFetched = true): PriceTrackingTarget
    {
        $target = $this->target($holding);
        $today = now()->toDateString();
        $target->last_attempted_at = now();

        if ($history->status !== PriceHistory::OK) {
            $this->fail($target, $history->message ?? $history->status, $history->status);
            $target->save();

            return $target;
        }

        $weeks = array_map(fn (array $row) => $this->weeks->weekStart($row['date']), $history->rows);
        $confirmed = array_values(array_filter($weeks, fn (string $week) => Carbon::parse($week)->addDays(7)->toDateString() <= $today));

        if ($confirmed !== [] && ! $this->allSaved($holding, $confirmed)) {
            $this->fail($target, '保存を確認できませんでした', PriceHistory::FAILED);
            $target->save();

            return $target;
        }

        if ($splitsFetched) {
            $target->splits_incomplete = $history->splitsIncomplete;
        }

        if ($backfill && $weeks !== []) {
            $oldest = min($weeks);
            $existing = $target->backfilled_from_week?->toDateString();
            $target->backfilled_from_week = $existing === null || $oldest < $existing ? $oldest : $existing;
        }

        $this->succeed($target, $confirmed === [] ? null : max($confirmed));

        return $target;
    }

    /**
     * Brings the state up to date from weekly_prices without a request, when
     * another path (the holdings / watchlist refresh) already saved the
     * holding after its last confirmed week ended: the row of that week was
     * written on or after the Monday that confirmed it, so its close is the
     * final one (a row written during the week holds a running close).
     *
     * Returns null when the saved rows do not prove that: the caller fetches.
     */
    public function applySavedPrices(Holding $holding): ?PriceTrackingTarget
    {
        $lastConfirmed = $this->weeks->weekStart(now()->subDays(7)->toDateString());
        $confirmedAt = Carbon::parse($lastConfirmed)->addDays(7)->startOfDay();

        $row = WeeklyPrice::query()
            ->where('holding_id', $holding->id)
            ->where('week_date', $lastConfirmed)
            ->first(['updated_at']);

        if ($row === null || $row->updated_at === null || $row->updated_at->lt($confirmedAt)) {
            return null;
        }

        $target = $this->target($holding);
        $this->succeed($target, $lastConfirmed);

        return $target;
    }

    /**
     * The common tail of a success: reset the failures, move the latest
     * saved week forward, and complete when the deadline is reached.
     */
    private function succeed(PriceTrackingTarget $target, ?string $latestConfirmedWeek): void
    {
        $target->consecutive_failures = 0;
        $target->last_error = null;

        if ($latestConfirmedWeek !== null) {
            $target->latest_saved_week = $this->later($target->latest_saved_week?->toDateString(), $latestConfirmedWeek);
        }

        $target->status = $target->track_until_week === null
            || ($target->latest_saved_week !== null && $target->latest_saved_week->gte($target->track_until_week))
            ? 'completed'
            : 'active';

        $target->save();
    }

    private function target(Holding $holding): PriceTrackingTarget
    {
        return PriceTrackingTarget::firstOrCreate(['holding_id' => $holding->id]);
    }

    /**
     * The later of two dates (as their Monday-start week); a null side is ignored.
     */
    private function later(?string $current, ?string $candidate): ?string
    {
        $candidate = $candidate === null ? null : $this->weeks->weekStart($candidate);
        $current = $current === null ? null : $this->weeks->weekStart($current);

        if ($current === null || $candidate === null) {
            return $current ?? $candidate;
        }

        return max($current, $candidate);
    }

    /**
     * @param  list<string>  $confirmedWeeks  Monday-start weeks
     */
    private function allSaved(Holding $holding, array $confirmedWeeks): bool
    {
        $saved = WeeklyPrice::query()
            ->where('holding_id', $holding->id)
            ->whereIn('week_date', $confirmedWeeks)
            ->count();

        return $saved >= count(array_unique($confirmedWeeks));
    }

    private function fail(PriceTrackingTarget $target, string $reason, string $status): void
    {
        $target->consecutive_failures = min(self::MAX_FAILURES, $target->consecutive_failures + 1);
        $target->last_error = mb_substr($reason, 0, 255);

        if ($target->consecutive_failures >= self::UNAVAILABLE_AFTER
            && in_array($status, [PriceHistory::NOT_FOUND, PriceHistory::EMPTY], true)) {
            $target->status = 'unavailable';
        }
    }
}
