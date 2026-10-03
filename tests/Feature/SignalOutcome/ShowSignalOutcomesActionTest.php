<?php

namespace Tests\Feature\SignalOutcome;

use App\Actions\SignalOutcome\ShowSignalOutcomesAction;
use App\Models\Holding;
use App\Models\IndexWeeklyPrice;
use App\Models\SignalOccurrence;
use App\Models\WeeklyPrice;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| UC-014: ShowSignalOutcomesAction — Red phase Feature Test (CHG-0020 Cycle4)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-014 基本フロー（集計の閲覧）・出力・業務ルール・エラーケース
|   - docs/adr/ADR-0017-signal-outcome-tracking.md D4 / D7 / D8
|   - docs/architecture/data-model.md weekly_prices / index_weekly_prices / signal_occurrences
|
| Contract assumed (flag at Gate 4):
|   - app(ShowSignalOutcomesAction::class)->execute(?string $source = null, ?string $market = null): array
|     read-only; uses now() for the as-of week.
|   - as_of_week = WeekDateNormalizer::weekStart(today in config('app.display_timezone')).
|   - horizons 4 / 13 / 26; excess return via ExcessReturnCalculator with the
|     holding's weekly_prices and index_weekly_prices `nikkei225` (jp) / `sp500` (us).
|   - source ∈ take_profit|buy|watchlist_buy|null, market ∈ jp|us|null, otherwise
|     InvalidArgumentException.
|   - shape: has_occurrences (ignores filters) / as_of_week / groups[] ordered by
|     source (take_profit, buy, watchlist_buy) then signal_type ascending; each group
|     has occurrence_count, horizons[4|13|26] (matured/pending/unavailable counts,
|     mean, median, t_value, hit_rate, verdict, provisional) and occurrences[]
|     (newest observed_week first) with excess_returns / statuses keyed by horizon.
|
| No factories exist for these models (only UserFactory) and adding HasFactory
| would require editing app/, so test data is created with Model::create()
| helpers, following the other tests in this directory.
|
| Expected Red: "Target class [App\Actions\SignalOutcome\ShowSignalOutcomesAction]
| does not exist." (BindingResolutionException) for every test.
|
*/

function ssoaHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code,
        'market' => $market,
        'instrument_type' => 'stock',
        'symbol_name' => 'テスト銘柄'.$code,
        'first_detected_at' => now(),
    ]);
}

/**
 * @param  array<string, float>  $closes  week_date => close
 */
function ssoaPrices(Holding $holding, array $closes): void
{
    foreach ($closes as $week => $close) {
        WeeklyPrice::create([
            'holding_id' => $holding->id,
            'week_date' => $week,
            'close' => $close,
            'volume' => 1000,
        ]);
    }
}

/**
 * @param  array<string, float>  $closes  week_date => close
 */
function ssoaIndex(string $indexName, array $closes): void
{
    foreach ($closes as $week => $close) {
        IndexWeeklyPrice::create([
            'index_name' => $indexName,
            'week_date' => $week,
            'close' => $close,
        ]);
    }
}

/**
 * @param  array<string, mixed>|null  $metrics
 */
function ssoaOccurrence(
    Holding $holding,
    string $source,
    string $signalType,
    string $observedWeek,
    ?array $metrics = ['close' => 1000.0, 'rsi' => 35.2],
): SignalOccurrence {
    return SignalOccurrence::create([
        'holding_id' => $holding->id,
        'source' => $source,
        'signal_type' => $signalType,
        'observed_week' => $observedWeek,
        'snapshot_id' => null,
        'metrics' => $metrics,
    ]);
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>|null
 */
function ssoaGroup(array $result, string $source, string $signalType): ?array
{
    foreach ($result['groups'] as $group) {
        if ($group['source'] === $source && $group['signal_type'] === $signalType) {
            return $group;
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $group
 * @return array<string, mixed>|null
 */
function ssoaOccurrenceRow(array $group, string $symbolCode): ?array
{
    foreach ($group['occurrences'] as $row) {
        if ($row['symbol_code'] === $symbolCode) {
            return $row;
        }
    }

    return null;
}

function ssoaAction(): ShowSignalOutcomesAction
{
    return app(ShowSignalOutcomesAction::class);
}

beforeEach(function () {
    // Manila Wed 2026-10-07 11:00 → as_of_week 2026-10-05.
    // observed 2026-08-31: +4w target 2026-09-28 (matured), +13w 2026-11-30 / +26w 2027-03-01 (pending).
    Carbon::setTestNow(Carbon::parse('2026-10-07 03:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('UC-014: シグナル結果の集計（ShowSignalOutcomesAction）', function () {
    test('UC-014: 発生記録が0件のときは has_occurrences が false で集計行は空', function () {
        // Act
        $result = ssoaAction()->execute();

        // Assert
        expect($result['has_occurrences'])->toBeFalse();
        expect($result['groups'])->toBe([]);
        expect($result['as_of_week'])->toBe('2026-10-05');
    });

    test('UC-014: JP株の買い増しシグナルは日経平均との超過リターンで+4週が結果到来、+13週・+26週は結果待ちとして集計される', function () {
        // Arrange: stock +10%, nikkei225 +5% over 4 weeks ⇒ excess +5.0
        $holding = ssoaHolding('7203', 'jp');
        ssoaPrices($holding, ['2026-08-31' => 1000.0, '2026-09-28' => 1100.0]);
        ssoaIndex('nikkei225', ['2026-08-31' => 30000.0, '2026-09-28' => 31500.0]);
        $metrics = ['close' => 1000.0, 'rsi' => 35.2];
        ssoaOccurrence($holding, 'buy', 'pullback', '2026-08-31', $metrics);

        // Act
        $result = ssoaAction()->execute();

        // Assert
        expect($result['has_occurrences'])->toBeTrue();
        expect($result['as_of_week'])->toBe('2026-10-05');
        expect($result['groups'])->toHaveCount(1);

        $group = $result['groups'][0];
        expect($group['source'])->toBe('buy');
        expect($group['signal_type'])->toBe('pullback');
        expect($group['occurrence_count'])->toBe(1);
        expect(array_keys($group['horizons']))->toBe([4, 13, 26]);

        $h4 = $group['horizons'][4];
        expect($h4['matured_count'])->toBe(1);
        expect($h4['pending_count'])->toBe(0);
        expect($h4['unavailable_count'])->toBe(0);
        expect($h4['mean'])->toEqualWithDelta(5.0, 1e-9);
        expect($h4['median'])->toEqualWithDelta(5.0, 1e-9);
        expect($h4['t_value'])->toBeNull();
        expect($h4['hit_rate'])->toEqualWithDelta(100.0, 1e-9);
        expect($h4['verdict'])->toBe('pending');
        expect($h4['provisional'])->toBeFalse();

        foreach ([13, 26] as $horizon) {
            $h = $group['horizons'][$horizon];
            expect($h['matured_count'])->toBe(0);
            expect($h['pending_count'])->toBe(1);
            expect($h['unavailable_count'])->toBe(0);
            expect($h['mean'])->toBeNull();
            expect($h['median'])->toBeNull();
            expect($h['t_value'])->toBeNull();
            expect($h['hit_rate'])->toBeNull();
            expect($h['verdict'])->toBe('pending');
        }

        expect($group['occurrences'])->toHaveCount(1);
        $row = $group['occurrences'][0];
        expect($row['symbol_code'])->toBe('7203');
        expect($row['symbol_name'])->toBe('テスト銘柄7203');
        expect($row['market'])->toBe('jp');
        expect($row['observed_week'])->toBe('2026-08-31');
        expect($row['metrics'])->toEqual($metrics);
        expect($row['excess_returns'][4])->toEqualWithDelta(5.0, 1e-9);
        expect($row['excess_returns'][13])->toBeNull();
        expect($row['excess_returns'][26])->toBeNull();
        expect($row['statuses'])->toBe([4 => 'matured', 13 => 'pending', 26 => 'pending']);
    });

    test('UC-014: US株の超過リターンは日経平均ではなくS&P500を基準に算出される', function () {
        // Arrange: stock +10%, sp500 +2% ⇒ +8.0 (nikkei225 +10% would give 0.0)
        $holding = ssoaHolding('MSFT', 'us');
        ssoaPrices($holding, ['2026-08-31' => 100.0, '2026-09-28' => 110.0]);
        ssoaIndex('sp500', ['2026-08-31' => 5000.0, '2026-09-28' => 5100.0]);
        ssoaIndex('nikkei225', ['2026-08-31' => 30000.0, '2026-09-28' => 33000.0]);
        ssoaOccurrence($holding, 'buy', 'pullback', '2026-08-31');

        // Act
        $result = ssoaAction()->execute();

        // Assert
        $group = ssoaGroup($result, 'buy', 'pullback');
        expect($group['horizons'][4]['matured_count'])->toBe(1);
        expect($group['horizons'][4]['mean'])->toEqualWithDelta(8.0, 1e-9);
        expect($group['occurrences'][0]['market'])->toBe('us');
        expect($group['occurrences'][0]['excess_returns'][4])->toEqualWithDelta(8.0, 1e-9);
    });

    test('UC-014: 日経平均の週次価格履歴が無いとJP株の発生は算出不可になり、同じ種別のUS株の発生は結果到来のまま集計される', function () {
        // Arrange: no nikkei225 rows at all
        $jp = ssoaHolding('7203', 'jp');
        ssoaPrices($jp, ['2026-08-31' => 1000.0, '2026-09-28' => 1100.0]);
        $us = ssoaHolding('MSFT', 'us');
        ssoaPrices($us, ['2026-08-31' => 100.0, '2026-09-28' => 110.0]);
        ssoaIndex('sp500', ['2026-08-31' => 5000.0, '2026-09-28' => 5100.0]);
        ssoaOccurrence($jp, 'buy', 'pullback', '2026-08-31');
        ssoaOccurrence($us, 'buy', 'pullback', '2026-08-31');

        // Act
        $result = ssoaAction()->execute();

        // Assert
        $group = ssoaGroup($result, 'buy', 'pullback');
        expect($group['occurrence_count'])->toBe(2);
        expect($group['horizons'][4]['matured_count'])->toBe(1);
        expect($group['horizons'][4]['unavailable_count'])->toBe(1);
        expect($group['horizons'][4]['mean'])->toEqualWithDelta(8.0, 1e-9);

        $jpRow = ssoaOccurrenceRow($group, '7203');
        expect($jpRow['statuses'][4])->toBe('unavailable');
        expect($jpRow['excess_returns'][4])->toBeNull();

        $usRow = ssoaOccurrenceRow($group, 'MSFT');
        expect($usRow['statuses'][4])->toBe('matured');
        expect($usRow['excess_returns'][4])->toEqualWithDelta(8.0, 1e-9);
    });

    test('UC-014: 評価期間後の週の株価が欠けている発生は「算出不可」として集計から除かれる', function () {
        // Arrange: stock has no 2026-09-28 row (e.g. delisted)
        $holding = ssoaHolding('7203', 'jp');
        ssoaPrices($holding, ['2026-08-31' => 1000.0]);
        ssoaIndex('nikkei225', ['2026-08-31' => 30000.0, '2026-09-28' => 31500.0]);
        ssoaOccurrence($holding, 'buy', 'pullback', '2026-08-31');

        // Act
        $result = ssoaAction()->execute();

        // Assert
        $h4 = ssoaGroup($result, 'buy', 'pullback')['horizons'][4];
        expect($h4['unavailable_count'])->toBe(1);
        expect($h4['matured_count'])->toBe(0);
        expect($h4['pending_count'])->toBe(0);
        expect($h4['mean'])->toBeNull();
        expect($h4['verdict'])->toBe('pending');
    });

    test('UC-014: 集計行は発生元（利確検討→買い増し→ウォッチリスト押し目買い）、シグナル種別の昇順に並び、同じ発生元×種別は銘柄・週をまたいで1行に合算される', function () {
        // Arrange: inserted in shuffled order
        $a = ssoaHolding('7203', 'jp');
        $b = ssoaHolding('6758', 'jp');
        ssoaOccurrence($a, 'watchlist_buy', 'pullback', '2026-09-07');
        ssoaOccurrence($a, 'buy', 'pullback', '2026-08-31');
        ssoaOccurrence($a, 'take_profit', 'rsi_overbought', '2026-08-31');
        ssoaOccurrence($b, 'buy', 'pullback', '2026-08-31');
        ssoaOccurrence($a, 'buy', 'peg_undervalued', '2026-09-07');
        ssoaOccurrence($b, 'take_profit', 'high_deviation', '2026-09-07');
        ssoaOccurrence($a, 'buy', 'pullback', '2026-09-07');

        // Act
        $result = ssoaAction()->execute();

        // Assert
        $keys = array_map(fn (array $g) => $g['source'].'/'.$g['signal_type'], $result['groups']);
        expect($keys)->toBe([
            'take_profit/high_deviation',
            'take_profit/rsi_overbought',
            'buy/peg_undervalued',
            'buy/pullback',
            'watchlist_buy/pullback',
        ]);
        expect(ssoaGroup($result, 'buy', 'pullback')['occurrence_count'])->toBe(3);
        expect(ssoaGroup($result, 'buy', 'pullback')['occurrences'])->toHaveCount(3);
        expect(ssoaGroup($result, 'watchlist_buy', 'pullback')['occurrence_count'])->toBe(1);
        expect(ssoaGroup($result, 'take_profit', 'rsi_overbought')['occurrence_count'])->toBe(1);
    });

    test('UC-014: source で絞り込むと指定した発生元の集計行だけが返る', function () {
        // Arrange
        $a = ssoaHolding('7203', 'jp');
        ssoaOccurrence($a, 'take_profit', 'rsi_overbought', '2026-08-31');
        ssoaOccurrence($a, 'buy', 'pullback', '2026-08-31');
        ssoaOccurrence($a, 'watchlist_buy', 'pullback', '2026-08-31');

        // Act
        $result = ssoaAction()->execute('buy');

        // Assert
        expect(array_column($result['groups'], 'source'))->toBe(['buy']);
        expect($result['has_occurrences'])->toBeTrue();
    });

    test('UC-014: market=us で絞り込むとJP株の発生が除かれ、JP株だけの集計行は消える（has_occurrences は絞り込みに関係なく true）', function () {
        // Arrange
        $jp = ssoaHolding('7203', 'jp');
        $us = ssoaHolding('MSFT', 'us');
        ssoaOccurrence($jp, 'take_profit', 'rsi_overbought', '2026-08-31');
        ssoaOccurrence($jp, 'buy', 'pullback', '2026-08-31');
        ssoaOccurrence($us, 'buy', 'pullback', '2026-08-31');

        // Act
        $result = ssoaAction()->execute(null, 'us');

        // Assert
        expect($result['has_occurrences'])->toBeTrue();
        expect($result['groups'])->toHaveCount(1);
        $group = ssoaGroup($result, 'buy', 'pullback');
        expect($group['occurrence_count'])->toBe(1);
        expect(array_column($group['occurrences'], 'symbol_code'))->toBe(['MSFT']);

        $onlyJpFilteredOut = ssoaAction()->execute('take_profit', 'us');
        expect($onlyJpFilteredOut['has_occurrences'])->toBeTrue();
        expect($onlyJpFilteredOut['groups'])->toBe([]);
    });

    test('UC-014: source に想定外の値を渡すと InvalidArgumentException', function () {
        $action = ssoaAction();

        expect(fn () => $action->execute('foo'))->toThrow(InvalidArgumentException::class);
    });

    test('UC-014: market に想定外の値を渡すと InvalidArgumentException', function () {
        $action = ssoaAction();

        expect(fn () => $action->execute(null, 'eu'))->toThrow(InvalidArgumentException::class);
    });

    test('UC-014: 基準週はUTCではなくマニラ時間の日付で決まり、評価期間後の週が基準週より前なら結果到来・基準週と同じなら結果待ち', function () {
        // Arrange: 2026-10-10 17:00 UTC = Sun 2026-10-11 01:00 Manila.
        //   Manila date 2026-10-11 → weekStart = 2026-10-12 (UTC date 2026-10-10 would give 2026-10-05).
        Carbon::setTestNow(Carbon::parse('2026-10-10 17:00:00', 'UTC'));
        $early = ssoaHolding('7203', 'jp');
        ssoaPrices($early, ['2026-09-07' => 1000.0, '2026-10-05' => 1100.0]);
        $late = ssoaHolding('6758', 'jp');
        ssoaPrices($late, ['2026-09-14' => 1000.0, '2026-10-12' => 1100.0]);
        ssoaIndex('nikkei225', [
            '2026-09-07' => 30000.0,
            '2026-09-14' => 30000.0,
            '2026-10-05' => 31500.0,
            '2026-10-12' => 31500.0,
        ]);
        ssoaOccurrence($early, 'buy', 'pullback', '2026-09-07'); // +4w target 2026-10-05
        ssoaOccurrence($late, 'buy', 'pullback', '2026-09-14');  // +4w target 2026-10-12

        // Act
        $result = ssoaAction()->execute();

        // Assert
        expect($result['as_of_week'])->toBe('2026-10-12');
        $group = ssoaGroup($result, 'buy', 'pullback');
        expect(ssoaOccurrenceRow($group, '7203')['statuses'][4])->toBe('matured');
        expect(ssoaOccurrenceRow($group, '6758')['statuses'][4])->toBe('pending');
        expect($group['horizons'][4]['matured_count'])->toBe(1);
        expect($group['horizons'][4]['pending_count'])->toBe(1);
    });

    test('UC-014: 個別発生は発生週の新しい順に並び、移送分（根拠値なし）は metrics が null のまま返る', function () {
        // Arrange
        $a = ssoaHolding('7203', 'jp');
        $b = ssoaHolding('6758', 'jp');
        ssoaOccurrence($a, 'buy', 'pullback', '2026-08-24', null);
        ssoaOccurrence($b, 'buy', 'pullback', '2026-09-07', ['close' => 2000.0]);
        ssoaOccurrence($a, 'buy', 'pullback', '2026-08-31', ['close' => 1000.0]);

        // Act
        $result = ssoaAction()->execute();

        // Assert
        $rows = ssoaGroup($result, 'buy', 'pullback')['occurrences'];
        expect(array_column($rows, 'observed_week'))->toBe(['2026-09-07', '2026-08-31', '2026-08-24']);
        expect($rows[2]['metrics'])->toBeNull();
        expect($rows[1]['metrics'])->toEqual(['close' => 1000.0]);
    });

    test('UC-014: 結果到来30件以上で一貫してプラスの買い増しシグナルは、蓄積3年未満のため「機能している（暫定）」と判定される', function () {
        // Arrange: 32 holdings, nikkei225 flat ⇒ excess = stock return 1.50%〜2.43% (all > 0, finite t)
        ssoaIndex('nikkei225', ['2026-08-31' => 30000.0, '2026-09-28' => 30000.0]);
        for ($i = 0; $i < 32; $i++) {
            $holding = ssoaHolding((string) (1000 + $i), 'jp');
            $return = 1.5 + $i * 0.03;
            ssoaPrices($holding, ['2026-08-31' => 1000.0, '2026-09-28' => 1000.0 * (1 + $return / 100)]);
            ssoaOccurrence($holding, 'buy', 'pullback', '2026-08-31');
        }

        // Act
        $result = ssoaAction()->execute();

        // Assert
        $h4 = ssoaGroup($result, 'buy', 'pullback')['horizons'][4];
        expect($h4['matured_count'])->toBe(32);
        expect($h4['mean'])->toEqualWithDelta(1.965, 1e-3);
        expect($h4['t_value'])->toBeGreaterThan(2.0);
        expect($h4['hit_rate'])->toEqualWithDelta(100.0, 1e-9);
        expect($h4['verdict'])->toBe('working');
        expect($h4['provisional'])->toBeTrue();
    });

    test('UC-014: 集計は読み取り専用で、発生記録・週次価格履歴を書き換えない', function () {
        // Arrange
        $holding = ssoaHolding('7203', 'jp');
        ssoaPrices($holding, ['2026-08-31' => 1000.0, '2026-09-28' => 1100.0]);
        ssoaIndex('nikkei225', ['2026-08-31' => 30000.0, '2026-09-28' => 31500.0]);
        ssoaOccurrence($holding, 'buy', 'pullback', '2026-08-31');
        $before = [
            SignalOccurrence::count(),
            WeeklyPrice::count(),
            IndexWeeklyPrice::count(),
            Holding::count(),
            SignalOccurrence::max('id'),
        ];

        // Act
        ssoaAction()->execute();
        ssoaAction()->execute('buy', 'jp');

        // Assert
        expect([
            SignalOccurrence::count(),
            WeeklyPrice::count(),
            IndexWeeklyPrice::count(),
            Holding::count(),
            SignalOccurrence::max('id'),
        ])->toBe($before);
    });
});
