<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\PriceTrackingTarget;
use App\Services\MarketData\PriceHistory;
use App\Services\PriceTracking\PriceTrackingTargetUpdater;
use App\Services\SignalOutcome\WeeklyPriceRecorder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| UC-018 価格追跡の対象と状態（price_tracking_targets） — Red phase
| (CHG-0033 Cycle 6b)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0024-trade-and-signal-price-tracking.md D1（期限）・D2
|     （保存の確認）・D5（初回補完）
|   - docs/architecture/data-model.md `price_tracking_targets`
|   - docs/adr/ADR-0027-trade-history-storage.md D7
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - Two schema changes from the approved Gate 3 definition (approve them
|     with these tests; data-model.md is updated in the same commit):
|       * price_tracking_targets.track_until_week is NULLABLE. A holding that
|         is only backfilled (still held, never sold, no signal) has no
|         tracking deadline; null means "covered by the holdings / watchlist
|         refresh, nothing more to track".
|       * price_tracking_targets.splits_incomplete boolean NOT NULL default
|         false: the last fetch dropped a split (not whole-number /
|         unreadable), so the split data is "分割情報欠損" and a result that
|         needs it must be shown as 算出不可.
|   - App\Services\PriceTracking\PriceTrackingTargetUpdater:
|       register(Holding, ?string $lastSellDate, ?string $lastSignalWeek)
|           : PriceTrackingTarget
|         upsert the one row of the holding. Dates are normalized to their
|         Monday-start week (WeekDateNormalizer::weekStart). A new date only
|         replaces the stored one when it is later (a deadline is extended,
|         never shortened). track_until_week = latest(last_sell_week,
|         last_signal_week) + 26 weeks, or null when both are null. A
|         completed target whose deadline moves beyond latest_saved_week
|         becomes active again.
|       applyFetch(Holding, PriceHistory $history, bool $backfill = false,
|           bool $splitsFetched = true): PriceTrackingTarget
|         Call it AFTER the caller saved the rows with
|         WeeklyPriceRecorder::recordHolding(), which swallows DB errors, so
|         "it returned" proves nothing. Creates the row (no deadline) when
|         the holding has none. A week is confirmed once the next Monday has
|         started: week + 7 days <= today (UTC date of now()).
|           ok     verify that every confirmed week of the history exists in
|                  weekly_prices; if not → counted as a failure with
|                  last_error '保存を確認できませんでした'. Otherwise
|                  latest_saved_week = latest confirmed week; with $backfill,
|                  backfilled_from_week = the oldest week of the history;
|                  consecutive_failures = 0; last_error = null; status
|                  'completed' when track_until_week is null or <=
|                  latest_saved_week, else 'active'.
|           failed | not_found | empty
|                  consecutive_failures + 1, last_error = the reason (or the
|                  status), status 'unavailable' when consecutive_failures >= 3
|                  AND this result is not_found or empty (the source answered
|                  "no such data"); repeated 'failed' (rate limit, server
|                  error) never makes a symbol unavailable.
|         Always: last_attempted_at = now(); splits_incomplete =
|         $history->splitsIncomplete (only updated by an ok fetch that
|         asked for splits, i.e. $splitsFetched; a fetch without splits
|         leaves the marker as it is).
|         A later ok fetch resets an 'unavailable' target to active/completed.
|
| Expected Red: the table, the model and the service do not exist yet.
|
*/

function ptHolding(string $code = '7203'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => 'jp', 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

/**
 * @param  list<string>  $dates  Monday-stamped weekly bars
 */
function ptOk(array $dates, bool $splitsIncomplete = false): PriceHistory
{
    return new PriceHistory(
        PriceHistory::OK,
        array_map(fn (string $d, int $i) => ['date' => $d, 'close' => 100.0 + $i, 'volume' => 1000], $dates, array_keys($dates)),
        [],
        $splitsIncomplete,
    );
}

/**
 * Saves the history the way the real caller does, then applies the fetch.
 */
function ptSaveAndApply(Holding $holding, PriceHistory $history, bool $backfill = false): PriceTrackingTarget
{
    app(WeeklyPriceRecorder::class)->recordHolding($holding, $history->rows);

    return app(PriceTrackingTargetUpdater::class)->applyFetch($holding, $history, $backfill);
}

function ptUpdater(): PriceTrackingTargetUpdater
{
    return app(PriceTrackingTargetUpdater::class);
}

afterEach(fn () => Carbon::setTestNow());

describe('UC-018 追跡の期限（register）', function () {
    test('最終売却の週から26週後が期限になり、日付はその週の月曜に揃えられる', function () {
        // Arrange: Wednesday 2026-03-04 → week starts Monday 2026-03-02 → +26 weeks = 2026-08-31
        $holding = ptHolding();

        // Act
        $target = ptUpdater()->register($holding, '2026-03-04', null);

        // Assert
        expect($target->last_sell_week->toDateString())->toBe('2026-03-02');
        expect($target->last_signal_week)->toBeNull();
        expect($target->track_until_week->toDateString())->toBe('2026-08-31');
        expect($target->status)->toBe('active');
    });

    test('シグナル発生の週だけでも期限になり、両方ある場合は遅いほうから26週後になる', function () {
        // Arrange
        $signalOnly = ptHolding('1111');
        $both = ptHolding('2222');

        // Act
        $a = ptUpdater()->register($signalOnly, null, '2026-04-06');
        $b = ptUpdater()->register($both, '2026-03-02', '2026-04-06');

        // Assert: the later of the two + 26 weeks (2026-04-06 + 182 days = 2026-10-05)
        expect($a->track_until_week->toDateString())->toBe('2026-10-05');
        expect($b->track_until_week->toDateString())->toBe('2026-10-05');
    });

    test('新しい売却やシグナルで期限は延び、古い日付では縮まない', function () {
        // Arrange
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-03-02', null);

        // Act
        $extended = ptUpdater()->register($holding, '2026-05-04', null);
        $notShortened = ptUpdater()->register($holding, '2026-01-05', null);

        // Assert
        expect($extended->last_sell_week->toDateString())->toBe('2026-05-04');
        expect($extended->track_until_week->toDateString())->toBe('2026-11-02');
        expect($notShortened->last_sell_week->toDateString())->toBe('2026-05-04');
        expect($notShortened->track_until_week->toDateString())->toBe('2026-11-02');
        expect(PriceTrackingTarget::where('holding_id', $holding->id)->count())->toBe(1);
    });

    test('売却もシグナルもない銘柄は期限なしで登録される', function () {
        // Act
        $target = ptUpdater()->register(ptHolding(), null, null);

        // Assert
        expect($target->track_until_week)->toBeNull();
        expect($target->splits_incomplete)->toBeFalse();
    });

    test('追跡が完了していた銘柄に新しい売却があり、期限が保存済みの週より先に延びたら、追跡中に戻る', function () {
        // Arrange: completed with latest_saved_week 2026-09-28
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-03-02', null);
        $done = ptSaveAndApply($holding, ptOk(['2026-08-24', '2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28']));
        expect($done->status)->toBe('completed');

        // Act: a new sale on 2026-09-28 moves the deadline to 2027-03-29, beyond the saved week
        $target = ptUpdater()->register($holding, '2026-09-28', null);

        // Assert
        expect($target->track_until_week->toDateString())->toBe('2027-03-29');
        expect($target->status)->toBe('active');
    });
});

describe('UC-018 取得結果の反映（applyFetch）: 成功', function () {
    test('保存を確認できた週のうち、確定した最新週が記録され、未確定の今週は含まれない', function () {
        // Arrange: today Wed 2026-10-07; the week of 2026-10-05 is still in progress
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-03-02', null);

        // Act
        $target = ptSaveAndApply($holding, ptOk(['2026-09-21', '2026-09-28', '2026-10-05']));

        // Assert
        expect($target->latest_saved_week->toDateString())->toBe('2026-09-28');
        expect($target->consecutive_failures)->toBe(0);
        expect($target->last_error)->toBeNull();
        expect($target->last_attempted_at->toDateTimeString())->toBe('2026-10-07 10:00:00');
    });

    test('初回補完では、取得した最古の週が補完済みの起点として記録される', function () {
        // Arrange
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();

        // Act
        $target = ptSaveAndApply($holding, ptOk(['2016-10-03', '2016-10-10', '2026-09-28']), backfill: true);

        // Assert
        expect($target->backfilled_from_week->toDateString())->toBe('2016-10-03');
    });

    test('補完でない通常の取得では、補完済みの起点は変わらない', function () {
        // Arrange
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();
        ptSaveAndApply($holding, ptOk(['2016-10-03', '2026-09-28']), backfill: true);

        // Act
        $target = ptSaveAndApply($holding, ptOk(['2024-10-07', '2026-09-28']));

        // Assert
        expect($target->backfilled_from_week->toDateString())->toBe('2016-10-03');
    });

    test('期限が終点の週より先なら追跡中のまま、26週目の途中の取得では完了にならない', function () {
        // Arrange: deadline 2026-08-31 (the 26th week); fetched on Wed 2026-09-02, the 08-31 week is still open
        Carbon::setTestNow('2026-09-02 10:00:00');
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-03-02', null);

        // Act
        $target = ptSaveAndApply($holding, ptOk(['2026-08-17', '2026-08-24', '2026-08-31']));

        // Assert
        expect($target->latest_saved_week->toDateString())->toBe('2026-08-24');
        expect($target->status)->toBe('active');
    });

    test('終点の週が確定したあとの取得で、その週の保存を確認できたら完了になる', function () {
        // Arrange: Tue 2026-09-08, the week of 2026-08-31 has ended
        Carbon::setTestNow('2026-09-08 10:00:00');
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-03-02', null);

        // Act
        $target = ptSaveAndApply($holding, ptOk(['2026-08-17', '2026-08-24', '2026-08-31', '2026-09-07']));

        // Assert
        expect($target->latest_saved_week->toDateString())->toBe('2026-08-31');
        expect($target->status)->toBe('completed');
    });

    test('期限のない銘柄は、取得に成功したらすぐ完了になる（以後の取得は保有・ウォッチリストの更新が担う）', function () {
        // Arrange
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();

        // Act
        $target = ptSaveAndApply($holding, ptOk(['2026-09-21', '2026-09-28']), backfill: true);

        // Assert
        expect($target->track_until_week)->toBeNull();
        expect($target->status)->toBe('completed');
    });

    test('保存できていない週がある場合は、成功とみなさず、失敗として数え、保存済みの最新週を進めない', function () {
        // Arrange: first a good fetch, then a fetch whose rows were never saved (deadline 2026-11-02, still open)
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-05-04', null);
        ptSaveAndApply($holding, ptOk(['2026-09-14', '2026-09-21']));

        // Act: the caller "forgot" (or the DB swallowed) the save of the newer weeks
        $target = ptUpdater()->applyFetch($holding, ptOk(['2026-09-14', '2026-09-21', '2026-09-28']));

        // Assert
        expect($target->latest_saved_week->toDateString())->toBe('2026-09-21');
        expect($target->consecutive_failures)->toBe(1);
        expect($target->last_error)->toBe('保存を確認できませんでした');
        expect($target->status)->toBe('active');
    });

    test('分割情報の欠損は、取得のたびにその結果で更新される', function () {
        // Arrange
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();

        // Act
        $incomplete = ptSaveAndApply($holding, ptOk(['2026-09-21', '2026-09-28'], splitsIncomplete: true));
        $complete = ptSaveAndApply($holding, ptOk(['2026-09-21', '2026-09-28'], splitsIncomplete: false));

        // Assert
        expect($incomplete->splits_incomplete)->toBeTrue();
        expect($complete->fresh()->splits_incomplete)->toBeFalse();
    });

    test('分割を取得しなかった取得（週次の追跡など）では、分割情報の欠損の記録を変えない', function () {
        // Arrange: the backfill dropped a split, so the holding is marked incomplete
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();
        ptSaveAndApply($holding, ptOk(['2026-09-21'], splitsIncomplete: true), backfill: true);

        // Act: a later fetch that did not ask Yahoo for splits (its history always reads "not incomplete")
        $history = ptOk(['2026-09-21', '2026-09-28'], splitsIncomplete: false);
        app(WeeklyPriceRecorder::class)->recordHolding($holding, $history->rows);
        $target = app(PriceTrackingTargetUpdater::class)->applyFetch($holding, $history, splitsFetched: false);

        // Assert: the marker survives, and the rest of the fetch is still applied
        expect($target->fresh()->splits_incomplete)->toBeTrue();
        expect($target->fresh()->latest_saved_week->toDateString())->toBe('2026-09-28');
    });

    test('分割を取得しなかった取得でも、欠損の記録がない銘柄は欠損なしのままである', function () {
        // Arrange
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();

        // Act
        $history = ptOk(['2026-09-28']);
        app(WeeklyPriceRecorder::class)->recordHolding($holding, $history->rows);
        $target = app(PriceTrackingTargetUpdater::class)->applyFetch($holding, $history, splitsFetched: false);

        // Assert
        expect($target->fresh()->splits_incomplete)->toBeFalse();
    });
});

describe('UC-018 取得結果の反映（applyFetch）: 失敗と取得不能', function () {
    test('取得失敗（レート制限など）は何回続いても取得不能にならず、失敗回数と理由だけが残る', function () {
        // Arrange
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-03-02', null);

        // Act
        for ($i = 0; $i < 5; $i++) {
            $target = ptUpdater()->applyFetch($holding, new PriceHistory(PriceHistory::FAILED, message: 'HTTP 429'));
        }

        // Assert
        expect($target->consecutive_failures)->toBe(5);
        expect($target->last_error)->toBe('HTTP 429');
        expect($target->status)->toBe('active');
    });

    test('「銘柄なし」が3回連続すると取得不能になり、2回までは追跡中のまま', function () {
        // Arrange
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-03-02', null);
        $notFound = new PriceHistory(PriceHistory::NOT_FOUND, message: 'HTTP 404');

        // Act & Assert
        expect(ptUpdater()->applyFetch($holding, $notFound)->status)->toBe('active');
        expect(ptUpdater()->applyFetch($holding, $notFound)->status)->toBe('active');
        $third = ptUpdater()->applyFetch($holding, $notFound);
        expect($third->status)->toBe('unavailable');
        expect($third->consecutive_failures)->toBe(3);
        expect($third->last_error)->toBe('HTTP 404');
    });

    test('「データなし」も「銘柄なし」と同じく数え、失敗が混ざっても3回続いて最後が銘柄なし／データなしなら取得不能になる', function () {
        // Arrange
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-03-02', null);

        // Act
        ptUpdater()->applyFetch($holding, new PriceHistory(PriceHistory::FAILED, message: 'HTTP 503'));
        ptUpdater()->applyFetch($holding, new PriceHistory(PriceHistory::FAILED, message: 'HTTP 503'));
        $target = ptUpdater()->applyFetch($holding, new PriceHistory(PriceHistory::EMPTY));

        // Assert
        expect($target->status)->toBe('unavailable');
        expect($target->last_error)->toBe('empty');
    });

    test('成功を挟むと連続失敗の数え直しになり、取得不能だった銘柄も取得できれば追跡に戻る', function () {
        // Arrange (deadline 2026-11-02, so a successful fetch leaves the target active)
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();
        ptUpdater()->register($holding, '2026-05-04', null);
        $notFound = new PriceHistory(PriceHistory::NOT_FOUND, message: 'HTTP 404');
        ptUpdater()->applyFetch($holding, $notFound);
        ptUpdater()->applyFetch($holding, $notFound);
        expect(ptUpdater()->applyFetch($holding, $notFound)->status)->toBe('unavailable');

        // Act
        $recovered = ptSaveAndApply($holding, ptOk(['2026-09-21', '2026-09-28']));
        $afterOneMore = ptUpdater()->applyFetch($holding, $notFound);

        // Assert
        expect($recovered->status)->toBe('active');
        expect($recovered->consecutive_failures)->toBe(0);
        expect($recovered->last_error)->toBeNull();
        expect($afterOneMore->consecutive_failures)->toBe(1);
        expect($afterOneMore->status)->toBe('active');
    });

    test('失敗した取得では、保存済みの最新週・補完済みの起点・分割情報の欠損フラグは変わらない', function () {
        // Arrange
        Carbon::setTestNow('2026-10-07 10:00:00');
        $holding = ptHolding();
        ptSaveAndApply($holding, ptOk(['2016-10-03', '2026-09-28'], splitsIncomplete: true), backfill: true);

        // Act
        $target = ptUpdater()->applyFetch($holding, new PriceHistory(PriceHistory::FAILED, message: 'HTTP 500'));

        // Assert
        expect($target->latest_saved_week->toDateString())->toBe('2026-09-28');
        expect($target->backfilled_from_week->toDateString())->toBe('2016-10-03');
        expect($target->splits_incomplete)->toBeTrue();
    });

    test('取得結果を反映しても、銘柄ごとに行は1つのまま増えない', function () {
        // Arrange
        $holding = ptHolding();

        // Act
        ptUpdater()->register($holding, '2026-03-02', null);
        ptUpdater()->applyFetch($holding, new PriceHistory(PriceHistory::FAILED, message: 'HTTP 500'));
        ptUpdater()->register($holding, null, '2026-05-04');

        // Assert
        expect(PriceTrackingTarget::count())->toBe(1);
    });
});
