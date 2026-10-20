<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use App\Services\TradeReview\Support\SituationAggregation;
use App\Services\TradeReview\Support\SituationCell;
use App\Services\TradeReview\Support\TradeComparison;
use App\Services\TradeReview\Support\TradeSituation;
use App\Services\TradeReview\TradeClusterAggregator;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| UC-019 状況別の集計（クラスタ単位）— Red phase (CHG-0033 Cycle B2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-019（2026-10-19 Gate 2承認）基本フロー1〜4:
|     定型は除外件数として別表示、状況・市場・評価期間ごとに対ガチホ差の件数・
|     中央値・分布、件数はクラスタ単位（同日・同銘柄・同方向の約定は1件、同じ
|     銘柄で+26週の評価期間が重なる売買は連鎖的に1クラスタ、クラスタ内は中央値
|     で代表）、成功法則・売買ルールを表示・生成しない
|   - docs/product/trade-decision-effect-gate2-uc-draft.md UC-019 基本フロー3
|     （クラスタの定義、2026-10-04 本人承認）、UC-018: +4／+13／+26週を合算しない
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Services\TradeReview\TradeClusterAggregator::aggregate(
|       Illuminate\Support\Collection<int, TradeExecution> $trades,
|       array<int, TradeSituation> $situations,   // keyed by trade id
|       list<TradeComparison> $comparisons,
|     ): App\Services\TradeReview\Support\SituationAggregation
|     A pure aggregation: no DB reads/writes beyond the given models.
|   - SituationAggregation (readonly): excludedRoutine (int) = the number of
|     input trades with is_routine = true; cells (list<SituationCell>).
|   - SituationCell (readonly), key: axis 'high_ratio' | 'relative_strength'
|     | 'holding'; band = the TradeSituation band on that axis (incl.
|     'unknown'); market 'jp' | 'us' (TradeExecution::market); type 'sell' |
|     'switch' | 'buy' (TradeComparison::type); horizon 4 | 13 | 26.
|     Values: clusters, okClusters, pendingClusters, unavailableClusters,
|     median, p25, p75 (?float, null when okClusters = 0), betterClusters,
|     recordedTrades.
|   - Only cells with at least one trade are output (no empty cells for every
|     key combination). The cells hold counts and distributions only — no
|     recommendation / verdict / rule field (成功法則・売買ルールを生成しない).
|   - Which trades are in a cell: a non-routine trade whose TradeSituation
|     has that band on that axis, whose market is that market, and that has a
|     TradeComparison of that type and horizon (switch / sell comparisons
|     carry the sale's id, buy comparisons the buy's id). A trade without a
|     comparison of that type and horizon (e.g. a buy with no new money) is
|     not in the cell — it counts neither in the clusters nor in
|     recordedTrades, and does not bridge a chain. Routine trades are ignored
|     even if a situation / comparisons are given for them.
|   - Clusters are built per cell from that cell's trades only: same
|     holding_id and same direction (kind buy / sell; within one cell the
|     type already fixes the direction), sorted by trade_date; a trade joins
|     the current cluster when its trade_date ≤ the latest (trade_date + 182
|     days) seen so far in it, otherwise it starts a new cluster. Same-day
|     trades therefore always share a cluster. The 182-day (+26週) window is
|     used for every horizon cell, including +4 and +13 weeks.
|   - A cluster's representative value = the median of diffRate over ALL its
|     trades whose comparison is 'ok' (flat — same-day trades are not
|     pre-merged into one value; even count → mean of the middle two).
|     ≥ 1 ok → ok cluster; else ≥ 1 pending → pending cluster; else →
|     unavailable cluster.
|   - median / p25 / p75 over the ok clusters' representative values, linear
|     interpolation between closest ranks (PERCENTILE.INC / numpy 'linear'):
|     sort ascending x[0..n−1], h = (n − 1) × p, value = x[⌊h⌋] + (h − ⌊h⌋)
|     × (x[⌊h⌋+1] − x[⌊h⌋]). p = 0.5 gives the median rule above.
|   - betterClusters = ok clusters whose representative value > 0 (0 is not
|     better). recordedTrades = the cell's trades (not clusters) whose
|     TradeSituation source is 'recorded'.
|   - (Added 2026-10-19, approved by the owner before Gate 4)
|     * A comparison with status 'ok' but diffRate = null (e.g. baseJpy ≤ 0)
|       counts as unavailable in that cell: it is not in the representative
|       value and does not make the cluster an ok cluster (no other ok and
|       no pending → unavailable cluster). The trade is still in the cell.
|     * A non-routine trade with no TradeSituation in $situations is left
|       out of the aggregation: in no cell's counts, does not bridge a
|       chain, and is not counted in excludedRoutine.
|     * SituationCell::tradeIds (list<int>) = the ids of the trades in that
|       cell (any status), newest trade_date first, same trade_date → id
|       descending. Trades without a comparison of that type and horizon,
|       without a situation, and routine trades are not in it.
|
| Expected Red: TradeClusterAggregator, SituationAggregation and
| SituationCell do not exist yet.
|
*/

const TCA_BASE_DATE = '2025-01-06';

function tcaHolding(string $code, string $market = 'jp'): Holding
{
    return Holding::create([
        'symbol_code' => $code, 'market' => $market, 'instrument_type' => 'stock',
        'symbol_name' => '銘柄'.$code, 'sector_classification_id' => null, 'first_detected_at' => now(),
    ]);
}

/**
 * A trade $day days after TCA_BASE_DATE.
 */
function tcaTrade(Holding $holding, string $kind, int $day, bool $routine = false): TradeExecution
{
    static $seq = 0;
    $batch = TradeImportBatch::query()->first()
        ?? TradeImportBatch::create(['status' => 'completed', 'jp_filename' => 'jp.csv', 'us_filename' => 'us.csv']);
    $seq++;

    return TradeExecution::create([
        'holding_id' => $holding->id, 'market' => $holding->market,
        'trade_date' => Carbon::parse(TCA_BASE_DATE)->addDays($day)->toDateString(),
        'account_type' => 'specific', 'kind' => $kind, 'is_routine' => $routine,
        'quantity' => 10, 'unit_price' => 100,
        'price_currency' => $holding->market === 'us' ? 'usd' : 'jpy', 'settlement_currency' => 'jpy',
        'settlement_amount_jpy' => 1000, 'fx_rate' => $holding->market === 'us' ? 100.0 : null,
        'content_hash' => str_pad((string) (7000 + $seq), 64, '0', STR_PAD_LEFT), 'occurrence_index' => 0,
        'source_row' => [], 'first_import_batch_id' => $batch->id, 'last_seen_import_batch_id' => $batch->id,
    ]);
}

function tcaSituation(
    TradeExecution $trade,
    string $high = 'near_high',
    string $strength = 'strong',
    string $holding = 'none',
    string $source = 'recomputed',
): TradeSituation {
    return new TradeSituation(
        tradeId: $trade->id,
        highRatioBand: $high,
        relativeStrengthBand: $strength,
        holdingBand: $holding,
        source: $source,
    );
}

/**
 * @param  'sell'|'switch'|'buy'  $type
 * @param  'ok'|'pending'|'unavailable'  $status
 */
function tcaCmp(TradeExecution $trade, string $type, int $horizon, string $status, ?float $diffRate = null): TradeComparison
{
    $ok = $status === 'ok';

    return new TradeComparison(
        tradeId: $trade->id,
        type: $type,
        horizon: $horizon,
        evaluationWeek: '2025-12-29',
        status: $status,
        reason: $status === 'unavailable' ? 'no_price' : null,
        baseJpy: 1000.0,
        actualJpy: $ok ? 1000.0 + 1000.0 * $diffRate : null,
        holdJpy: $ok ? 1000.0 : null,
        diffJpy: $ok ? 1000.0 * $diffRate : null,
        diffRate: $ok ? $diffRate : null,
    );
}

/**
 * An 'ok' comparison without a rate (base ≤ 0, so the rate cannot be computed).
 *
 * @param  'sell'|'switch'|'buy'  $type
 */
function tcaCmpOkWithoutRate(TradeExecution $trade, string $type, int $horizon): TradeComparison
{
    return new TradeComparison(
        tradeId: $trade->id,
        type: $type,
        horizon: $horizon,
        evaluationWeek: '2025-12-29',
        status: 'ok',
        reason: null,
        baseJpy: 0.0,
        actualJpy: 500.0,
        holdJpy: 0.0,
        diffJpy: 500.0,
        diffRate: null,
    );
}

/**
 * Aggregates every trade in the DB (newest first, so the aggregator has to
 * sort by trade date itself).
 *
 * @param  list<TradeSituation>  $situations
 * @param  list<TradeComparison>  $comparisons
 */
function tcaAggregate(array $situations, array $comparisons): SituationAggregation
{
    $keyed = [];

    foreach ($situations as $situation) {
        $keyed[$situation->tradeId] = $situation;
    }

    $trades = TradeExecution::query()->orderByDesc('trade_date')->orderByDesc('id')->get();

    return app(TradeClusterAggregator::class)->aggregate($trades, $keyed, $comparisons);
}

/**
 * The one cell with this key, or null when absent.
 */
function tcaCell(SituationAggregation $result, string $axis, string $band, string $market, string $type, int $horizon): ?SituationCell
{
    $found = array_values(array_filter(
        $result->cells,
        fn (SituationCell $c) => [$c->axis, $c->band, $c->market, $c->type, $c->horizon] === [$axis, $band, $market, $type, $horizon],
    ));

    expect(count($found))->toBeLessThanOrEqual(1);

    return $found[0] ?? null;
}

/**
 * @return array{0: int, 1: int, 2: int, 3: int} clusters, ok, pending, unavailable
 */
function tcaCounts(SituationCell $cell): array
{
    return [$cell->clusters, $cell->okClusters, $cell->pendingClusters, $cell->unavailableClusters];
}

/**
 * @param  array{0: float, 1: float, 2: float}  $expected  p25, median, p75
 */
function tcaExpectDistribution(SituationCell $cell, array $expected): void
{
    expect($cell->p25)->toEqualWithDelta($expected[0], 1e-9);
    expect($cell->median)->toEqualWithDelta($expected[1], 1e-9);
    expect($cell->p75)->toEqualWithDelta($expected[2], 1e-9);
}

describe('UC-019 状況別の集計: 対象の売買とセル', function () {
    test('定型の売買は状況・比較が渡されても集計に入れず、除外件数として数える', function () {
        // Arrange: stock A — one discretionary buy (+10%) and two routine buys (+90%, +80%)
        $a = tcaHolding('1111');
        $buy = tcaTrade($a, 'buy', 0);
        $routine1 = tcaTrade($a, 'buy', 0, routine: true);
        $routine2 = tcaTrade($a, 'buy', 10, routine: true);

        // Act
        $result = tcaAggregate(
            [tcaSituation($buy, source: 'recorded'), tcaSituation($routine1, source: 'recorded'), tcaSituation($routine2, source: 'recorded')],
            [tcaCmp($buy, 'buy', 26, 'ok', 0.1), tcaCmp($routine1, 'buy', 26, 'ok', 0.9), tcaCmp($routine2, 'buy', 26, 'ok', 0.8)],
        );

        // Assert
        expect($result->excludedRoutine)->toBe(2);
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'buy', 26);
        expect(tcaCounts($cell))->toBe([1, 1, 0, 0]);
        expect($cell->median)->toEqualWithDelta(0.1, 1e-9);
        expect($cell->recordedTrades)->toBe(1);
    });

    test('1件の売却と1つの比較からは3つの軸それぞれの区分に1セルずつ作り、売買のないセルは出力しない', function () {
        // Arrange: a jp sell in pullback / weak / gt5, sell +13週 −5%
        $a = tcaHolding('1111');
        $sell = tcaTrade($a, 'sell', 0);

        // Act
        $result = tcaAggregate(
            [tcaSituation($sell, high: 'pullback', strength: 'weak', holding: 'gt5')],
            [tcaCmp($sell, 'sell', 13, 'ok', -0.05)],
        );

        // Assert
        expect($result->excludedRoutine)->toBe(0);
        expect(array_map(fn (SituationCell $c) => [$c->axis, $c->band, $c->market, $c->type, $c->horizon], $result->cells))
            ->toEqualCanonicalizing([
                ['high_ratio', 'pullback', 'jp', 'sell', 13],
                ['relative_strength', 'weak', 'jp', 'sell', 13],
                ['holding', 'gt5', 'jp', 'sell', 13],
            ]);

        foreach ($result->cells as $cell) {
            expect(tcaCounts($cell))->toBe([1, 1, 0, 0]);
            tcaExpectDistribution($cell, [-0.05, -0.05, -0.05]);
            expect([$cell->betterClusters, $cell->recordedTrades])->toBe([0, 0]);
        }
    });

    test('不明の区分も1つの区分としてセルを作る', function () {
        // Arrange: both price axes unknown
        $a = tcaHolding('1111');
        $buy = tcaTrade($a, 'buy', 0);

        // Act
        $result = tcaAggregate(
            [tcaSituation($buy, high: 'unknown', strength: 'unknown', holding: 'lt1')],
            [tcaCmp($buy, 'buy', 4, 'ok', 0.02)],
        );

        // Assert
        expect(tcaCounts(tcaCell($result, 'high_ratio', 'unknown', 'jp', 'buy', 4)))->toBe([1, 1, 0, 0]);
        expect(tcaCounts(tcaCell($result, 'relative_strength', 'unknown', 'jp', 'buy', 4)))->toBe([1, 1, 0, 0]);
    });

    test('国内株と米国株は別のセルにする', function () {
        // Arrange: same bands, jp +10%, us +30%
        $jp = tcaHolding('1111');
        $us = tcaHolding('NVDA', 'us');
        $jpSell = tcaTrade($jp, 'sell', 0);
        $usSell = tcaTrade($us, 'sell', 0);

        // Act
        $result = tcaAggregate(
            [tcaSituation($jpSell), tcaSituation($usSell)],
            [tcaCmp($jpSell, 'sell', 26, 'ok', 0.1), tcaCmp($usSell, 'sell', 26, 'ok', 0.3)],
        );

        // Assert
        $jpCell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26);
        $usCell = tcaCell($result, 'high_ratio', 'near_high', 'us', 'sell', 26);
        expect([tcaCounts($jpCell), tcaCounts($usCell)])->toBe([[1, 1, 0, 0], [1, 1, 0, 0]]);
        expect($jpCell->median)->toEqualWithDelta(0.1, 1e-9);
        expect($usCell->median)->toEqualWithDelta(0.3, 1e-9);
    });

    test('比較が存在しない売買はそのセルのクラスタにも当時記録の件数にも入れず、連鎖のつなぎにもしない', function () {
        // Arrange: stock A buys on day 0 (+20%), day 100 (no buy comparison: no new money),
        // day 200 (+40%), all recorded. Without day 100, 0 → 200 is 200 > 182 days → 2 clusters.
        $a = tcaHolding('1111');
        $first = tcaTrade($a, 'buy', 0);
        $noNewMoney = tcaTrade($a, 'buy', 100);
        $third = tcaTrade($a, 'buy', 200);

        // Act
        $result = tcaAggregate(
            [tcaSituation($first, source: 'recorded'), tcaSituation($noNewMoney, source: 'recorded'), tcaSituation($third, source: 'recorded')],
            [tcaCmp($first, 'buy', 26, 'ok', 0.2), tcaCmp($third, 'buy', 26, 'ok', 0.4)],
        );

        // Assert: reps {0.2, 0.4} → p25 0.2 + 0.25 × 0.2 = 0.25, median 0.3, p75 0.35
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'buy', 26);
        expect(tcaCounts($cell))->toBe([2, 2, 0, 0]);
        expect($cell->recordedTrades)->toBe(2);
        tcaExpectDistribution($cell, [0.25, 0.3, 0.35]);
    });
});

describe('UC-019 状況別の集計: クラスタの作り方', function () {
    test('同じ銘柄・同じ方向・同じ約定日の売買は1クラスタにまとめ、異なる銘柄は別クラスタにする', function () {
        // Arrange: stock A sells twice on day 0 (+10%, +30% → rep 0.2); stock B sells on day 0 (+50%)
        $a = tcaHolding('1111');
        $b = tcaHolding('2222');
        $a1 = tcaTrade($a, 'sell', 0);
        $a2 = tcaTrade($a, 'sell', 0);
        $b1 = tcaTrade($b, 'sell', 0);

        // Act
        $result = tcaAggregate(
            [tcaSituation($a1), tcaSituation($a2), tcaSituation($b1)],
            [tcaCmp($a1, 'sell', 26, 'ok', 0.1), tcaCmp($a2, 'sell', 26, 'ok', 0.3), tcaCmp($b1, 'sell', 26, 'ok', 0.5)],
        );

        // Assert: reps {0.2, 0.5} → p25 0.2 + 0.25 × 0.3 = 0.275, median 0.35, p75 0.425
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26);
        expect(tcaCounts($cell))->toBe([2, 2, 0, 0]);
        tcaExpectDistribution($cell, [0.275, 0.35, 0.425]);
    });

    test('次の売買が約定日の182日後（+26週ちょうど）までなら同じクラスタ、183日後なら別クラスタにする', function (int $gap, int $clusters, int $better) {
        // Arrange: stock A sells on day 0 (+10%) and day $gap (−30%)
        $a = tcaHolding('1111');
        $first = tcaTrade($a, 'sell', 0);
        $second = tcaTrade($a, 'sell', $gap);

        // Act
        $result = tcaAggregate(
            [tcaSituation($first), tcaSituation($second)],
            [tcaCmp($first, 'sell', 26, 'ok', 0.1), tcaCmp($second, 'sell', 26, 'ok', -0.3)],
        );

        // Assert
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26);
        expect(tcaCounts($cell))->toBe([$clusters, $clusters, 0, 0]);
        expect($cell->betterClusters)->toBe($better);
    })->with([
        // 1 cluster, rep = (0.1 − 0.3) / 2 = −0.1 → not better
        '182日後は同じクラスタ' => [182, 1, 0],
        // 2 clusters, reps 0.1 and −0.3 → one better
        '183日後は別クラスタ' => [183, 2, 1],
    ]);

    test('+26週の期間が重なる売買は連鎖して1クラスタにし、最後の期間を過ぎた売買から新しいクラスタにする', function () {
        // Arrange: stock A sells on day 0 (+10%), 150 (+20%), 300 (+60%) — 300 − 0 > 182 but each
        // step ≤ 182 → one cluster, rep = median(0.1, 0.2, 0.6) = 0.2.
        // Day 483 = 300 + 183 (−40%) is past the chain's end (300 + 182) → a second cluster.
        $a = tcaHolding('1111');
        $trades = [tcaTrade($a, 'sell', 0), tcaTrade($a, 'sell', 150), tcaTrade($a, 'sell', 300), tcaTrade($a, 'sell', 483)];
        $rates = [0.1, 0.2, 0.6, -0.4];

        // Act
        $result = tcaAggregate(
            array_map(fn (TradeExecution $t) => tcaSituation($t), $trades),
            array_map(fn (TradeExecution $t, float $r) => tcaCmp($t, 'sell', 26, 'ok', $r), $trades, $rates),
        );

        // Assert: reps {−0.4, 0.2} → p25 −0.4 + 0.25 × 0.6 = −0.25, median −0.1, p75 0.05
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26);
        expect(tcaCounts($cell))->toBe([2, 2, 0, 0]);
        tcaExpectDistribution($cell, [-0.25, -0.1, 0.05]);
        expect($cell->betterClusters)->toBe(1);
    });

    test('+4週のセルでもクラスタは+26週の期間の重なりで作る', function () {
        // Arrange: stock A sells on day 0 (+10%) and day 100 (+30%), +4週 comparisons only.
        // 100 days > 4 weeks but ≤ 182 days → one cluster, rep 0.2
        $a = tcaHolding('1111');
        $first = tcaTrade($a, 'sell', 0);
        $second = tcaTrade($a, 'sell', 100);

        // Act
        $result = tcaAggregate(
            [tcaSituation($first), tcaSituation($second)],
            [tcaCmp($first, 'sell', 4, 'ok', 0.1), tcaCmp($second, 'sell', 4, 'ok', 0.3)],
        );

        // Assert
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 4);
        expect(tcaCounts($cell))->toBe([1, 1, 0, 0]);
        expect($cell->median)->toEqualWithDelta(0.2, 1e-9);
    });

    test('同じ銘柄・同じ日でも買付と売却は別クラスタとして、それぞれの比較の種類のセルに入れる', function () {
        // Arrange: stock A buys (+40% vs 比例買増し) and sells (−20% vs 保有継続) on day 0
        $a = tcaHolding('1111');
        $buy = tcaTrade($a, 'buy', 0);
        $sell = tcaTrade($a, 'sell', 0);

        // Act
        $result = tcaAggregate(
            [tcaSituation($buy), tcaSituation($sell)],
            [tcaCmp($buy, 'buy', 26, 'ok', 0.4), tcaCmp($sell, 'sell', 26, 'ok', -0.2)],
        );

        // Assert
        $buyCell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'buy', 26);
        $sellCell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26);
        expect([tcaCounts($buyCell), tcaCounts($sellCell)])->toBe([[1, 1, 0, 0], [1, 1, 0, 0]]);
        expect($buyCell->median)->toEqualWithDelta(0.4, 1e-9);
        expect($sellCell->median)->toEqualWithDelta(-0.2, 1e-9);
    });

    test('クラスタはそのセルに入る売買だけで作り、別の区分の売買を介して連鎖させない', function () {
        // Arrange: stock A sells on day 0 near_high (+10%), day 100 pullback (+20%),
        // day 200 near_high (+30%); all strong. In near_high, 0 → 200 > 182 → 2 clusters;
        // in strong, the day-100 sell links them → 1 cluster (rep 0.2).
        $a = tcaHolding('1111');
        $first = tcaTrade($a, 'sell', 0);
        $middle = tcaTrade($a, 'sell', 100);
        $last = tcaTrade($a, 'sell', 200);

        // Act
        $result = tcaAggregate(
            [tcaSituation($first, high: 'near_high'), tcaSituation($middle, high: 'pullback'), tcaSituation($last, high: 'near_high')],
            [tcaCmp($first, 'sell', 26, 'ok', 0.1), tcaCmp($middle, 'sell', 26, 'ok', 0.2), tcaCmp($last, 'sell', 26, 'ok', 0.3)],
        );

        // Assert
        $nearHigh = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26);
        $pullback = tcaCell($result, 'high_ratio', 'pullback', 'jp', 'sell', 26);
        $strong = tcaCell($result, 'relative_strength', 'strong', 'jp', 'sell', 26);
        expect(tcaCounts($nearHigh))->toBe([2, 2, 0, 0]);
        tcaExpectDistribution($nearHigh, [0.15, 0.2, 0.25]); // reps {0.1, 0.3}
        expect(tcaCounts($pullback))->toBe([1, 1, 0, 0]);
        expect(tcaCounts($strong))->toBe([1, 1, 0, 0]);
        expect($strong->median)->toEqualWithDelta(0.2, 1e-9);
    });
});

describe('UC-019 状況別の集計: クラスタの代表値と結果の状態', function () {
    test('クラスタの代表値は、同日分を先にまとめず、クラスタ内の結果ありの売買の差率の中央値にし、偶数件は中央2つの平均にする', function () {
        // Arrange (each cluster alone in its market's cell, so the cell median = the rep):
        //   jp stock A: day 0 +10%, day 0 +20%, day 50 +90% → flat median 0.2
        //     (a median of the per-day medians 0.15 and 0.9 would give 0.525)
        //   us stock B: day 0 +10%, day 50 +50% → even count, (0.1 + 0.5) / 2 = 0.3
        $a = tcaHolding('1111');
        $b = tcaHolding('NVDA', 'us');
        $jp = [tcaTrade($a, 'sell', 0), tcaTrade($a, 'sell', 0), tcaTrade($a, 'sell', 50)];
        $us = [tcaTrade($b, 'sell', 0), tcaTrade($b, 'sell', 50)];
        $all = [...$jp, ...$us];
        $rates = [0.1, 0.2, 0.9, 0.1, 0.5];

        // Act
        $result = tcaAggregate(
            array_map(fn (TradeExecution $t) => tcaSituation($t), $all),
            array_map(fn (TradeExecution $t, float $r) => tcaCmp($t, 'sell', 26, 'ok', $r), $all, $rates),
        );

        // Assert
        $jpCell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26);
        $usCell = tcaCell($result, 'high_ratio', 'near_high', 'us', 'sell', 26);
        expect([tcaCounts($jpCell), tcaCounts($usCell)])->toBe([[1, 1, 0, 0], [1, 1, 0, 0]]);
        expect($jpCell->median)->toEqualWithDelta(0.2, 1e-9);
        expect($usCell->median)->toEqualWithDelta(0.3, 1e-9);
    });

    test('結果ありの売買が1件でもあるクラスタは結果ありとし、結果待ち・算出不可の売買は代表値に入れない', function () {
        // Arrange: stock A sells on day 0 (ok +40%), day 10 (pending), day 20 (unavailable)
        $a = tcaHolding('1111');
        $ok = tcaTrade($a, 'sell', 0);
        $pending = tcaTrade($a, 'sell', 10);
        $unavailable = tcaTrade($a, 'sell', 20);

        // Act
        $result = tcaAggregate(
            [tcaSituation($ok), tcaSituation($pending), tcaSituation($unavailable)],
            [tcaCmp($ok, 'sell', 26, 'ok', 0.4), tcaCmp($pending, 'sell', 26, 'pending'), tcaCmp($unavailable, 'sell', 26, 'unavailable')],
        );

        // Assert
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26);
        expect(tcaCounts($cell))->toBe([1, 1, 0, 0]);
        tcaExpectDistribution($cell, [0.4, 0.4, 0.4]);
        expect($cell->betterClusters)->toBe(1);
    });

    test('結果ありがなく結果待ちがあるクラスタは結果待ち、どちらもないクラスタは算出不可として数え、分布は出さない', function () {
        // Arrange: A = pending + unavailable → pending; B = unavailable only → unavailable;
        // C = pending only → pending
        $a = tcaHolding('1111');
        $b = tcaHolding('2222');
        $c = tcaHolding('3333');
        $a1 = tcaTrade($a, 'buy', 0);
        $a2 = tcaTrade($a, 'buy', 30);
        $b1 = tcaTrade($b, 'buy', 0);
        $c1 = tcaTrade($c, 'buy', 0);

        // Act
        $result = tcaAggregate(
            [tcaSituation($a1), tcaSituation($a2), tcaSituation($b1), tcaSituation($c1)],
            [
                tcaCmp($a1, 'buy', 13, 'pending'), tcaCmp($a2, 'buy', 13, 'unavailable'),
                tcaCmp($b1, 'buy', 13, 'unavailable'), tcaCmp($c1, 'buy', 13, 'pending'),
            ],
        );

        // Assert
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'buy', 13);
        expect(tcaCounts($cell))->toBe([3, 0, 2, 1]);
        expect([$cell->p25, $cell->median, $cell->p75, $cell->betterClusters])->toBe([null, null, null, 0]);
    });

    test('25％点・中央値・75％点は結果ありクラスタの代表値から線形補間で求め、代表値が0より大きいクラスタを数える', function () {
        // Arrange: 4 ok clusters with reps 0.5, −0.2, 0.1, 0.0 and 1 pending cluster.
        // Sorted {−0.2, 0.0, 0.1, 0.5}, n = 4:
        //   p25: h = 0.75 → −0.2 + 0.75 × 0.2 = −0.05
        //   p50: h = 1.5  → 0.0 + 0.5 × 0.1 = 0.05
        //   p75: h = 2.25 → 0.1 + 0.25 × 0.4 = 0.2
        //   > 0: 0.1, 0.5 → 2 (0.0 is not better)
        $rates = ['1111' => 0.5, '2222' => -0.2, '3333' => 0.1, '4444' => 0.0];
        $situations = [];
        $comparisons = [];

        foreach ($rates as $code => $rate) {
            $trade = tcaTrade(tcaHolding((string) $code), 'sell', 0); // switch comparisons carry the sale's id
            $situations[] = tcaSituation($trade);
            $comparisons[] = tcaCmp($trade, 'switch', 26, 'ok', $rate);
        }

        $waiting = tcaTrade(tcaHolding('5555'), 'sell', 0);
        $situations[] = tcaSituation($waiting);
        $comparisons[] = tcaCmp($waiting, 'switch', 26, 'pending');

        // Act
        $result = tcaAggregate($situations, $comparisons);

        // Assert
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'switch', 26);
        expect(tcaCounts($cell))->toBe([5, 4, 1, 0]);
        tcaExpectDistribution($cell, [-0.05, 0.05, 0.2]);
        expect($cell->betterClusters)->toBe(2);
    });
});

describe('UC-019 状況別の集計: 評価期間・比較の種類を合算しない', function () {
    test('+4週・+13週と売却単独・推定乗換えの結果はそれぞれ別のセルにし、比較のない組み合わせのセルは作らない', function () {
        // Arrange: one sell with sell +4週 +10%, sell +13週 +20%, switch +4週 −30%
        $a = tcaHolding('1111');
        $sell = tcaTrade($a, 'sell', 0);

        // Act
        $result = tcaAggregate(
            [tcaSituation($sell)],
            [tcaCmp($sell, 'sell', 4, 'ok', 0.1), tcaCmp($sell, 'sell', 13, 'ok', 0.2), tcaCmp($sell, 'switch', 4, 'ok', -0.3)],
        );

        // Assert: 3 (type, horizon) × 3 axes
        expect($result->cells)->toHaveCount(9);
        expect(tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 4)->median)->toEqualWithDelta(0.1, 1e-9);
        expect(tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 13)->median)->toEqualWithDelta(0.2, 1e-9);
        expect(tcaCell($result, 'high_ratio', 'near_high', 'jp', 'switch', 4)->median)->toEqualWithDelta(-0.3, 1e-9);
        expect(tcaCell($result, 'high_ratio', 'near_high', 'jp', 'switch', 13))->toBeNull();
        expect(tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26))->toBeNull();
    });
});

describe('UC-019 状況別の集計: 当時記録の件数', function () {
    test('当時記録の件数はクラスタ数ではなく、そのセルの売買のうち出所が当時記録のものの件数で数える', function () {
        // Arrange: stock A sells on day 0 (recorded), day 0 (recorded), day 50 (recomputed) → 1 cluster;
        // stock B sells on day 0 (recorded) → 1 cluster
        $a = tcaHolding('1111');
        $b = tcaHolding('2222');
        $a1 = tcaTrade($a, 'sell', 0);
        $a2 = tcaTrade($a, 'sell', 0);
        $a3 = tcaTrade($a, 'sell', 50);
        $b1 = tcaTrade($b, 'sell', 0);

        // Act
        $result = tcaAggregate(
            [
                tcaSituation($a1, source: 'recorded'), tcaSituation($a2, source: 'recorded'),
                tcaSituation($a3, source: 'recomputed'), tcaSituation($b1, source: 'recorded'),
            ],
            [
                tcaCmp($a1, 'sell', 26, 'ok', 0.1), tcaCmp($a2, 'sell', 26, 'ok', 0.1),
                tcaCmp($a3, 'sell', 26, 'ok', 0.1), tcaCmp($b1, 'sell', 26, 'ok', 0.1),
            ],
        );

        // Assert
        $cell = tcaCell($result, 'holding', 'none', 'jp', 'sell', 26);
        expect($cell->clusters)->toBe(2);
        expect($cell->recordedTrades)->toBe(3);
    });
});

describe('UC-019 状況別の集計: 差率を算出できない結果あり', function () {
    test('結果ありでも差率がない売買は代表値に入れず、他に差率のある結果ありがあればその値だけで代表する', function () {
        // Arrange: stock A sells on day 0 (ok +40%) and day 10 (ok, no rate) → 1 cluster, rep 0.4
        // (treating the missing rate as 0 would give 0.2)
        $a = tcaHolding('1111');
        $rated = tcaTrade($a, 'sell', 0);
        $unrated = tcaTrade($a, 'sell', 10);

        // Act
        $result = tcaAggregate(
            [tcaSituation($rated), tcaSituation($unrated)],
            [tcaCmp($rated, 'sell', 26, 'ok', 0.4), tcaCmpOkWithoutRate($unrated, 'sell', 26)],
        );

        // Assert
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26);
        expect(tcaCounts($cell))->toBe([1, 1, 0, 0]);
        tcaExpectDistribution($cell, [0.4, 0.4, 0.4]);
        expect($cell->betterClusters)->toBe(1);
    });

    test('差率のない結果ありしかないクラスタは、結果待ちがあれば結果待ち、なければ算出不可として数え、分布は出さない', function () {
        // Arrange: A = ok without rate only → unavailable; B = ok without rate + pending → pending
        $a = tcaHolding('1111');
        $b = tcaHolding('2222');
        $a1 = tcaTrade($a, 'buy', 0);
        $b1 = tcaTrade($b, 'buy', 0);
        $b2 = tcaTrade($b, 'buy', 30);

        // Act
        $result = tcaAggregate(
            [tcaSituation($a1), tcaSituation($b1), tcaSituation($b2)],
            [tcaCmpOkWithoutRate($a1, 'buy', 26), tcaCmpOkWithoutRate($b1, 'buy', 26), tcaCmp($b2, 'buy', 26, 'pending')],
        );

        // Assert
        $cell = tcaCell($result, 'high_ratio', 'near_high', 'jp', 'buy', 26);
        expect(tcaCounts($cell))->toBe([2, 0, 1, 1]);
        expect([$cell->p25, $cell->median, $cell->p75, $cell->betterClusters])->toBe([null, null, null, 0]);
    });
});

describe('UC-019 状況別の集計: 状況のない売買', function () {
    test('定型でないのに状況が渡されない売買は、どのセルにも入れず、連鎖のつなぎにもせず、定型の除外件数にも数えない', function () {
        // Arrange: stock A sells on day 0 (+10%), day 100 (+90%, no situation), day 200 (+30%).
        // Without day 100, 0 → 200 > 182 days → 2 clusters, reps {0.1, 0.3}
        $a = tcaHolding('1111');
        $first = tcaTrade($a, 'sell', 0);
        $noSituation = tcaTrade($a, 'sell', 100);
        $last = tcaTrade($a, 'sell', 200);

        // Act
        $result = tcaAggregate(
            [tcaSituation($first, source: 'recorded'), tcaSituation($last, source: 'recorded')],
            [tcaCmp($first, 'sell', 26, 'ok', 0.1), tcaCmp($noSituation, 'sell', 26, 'ok', 0.9), tcaCmp($last, 'sell', 26, 'ok', 0.3)],
        );

        // Assert: only the given bands' cells (no 'unknown' cell for the situationless sale)
        expect($result->excludedRoutine)->toBe(0);
        expect(array_map(fn (SituationCell $c) => [$c->axis, $c->band, $c->market, $c->type, $c->horizon], $result->cells))
            ->toEqualCanonicalizing([
                ['high_ratio', 'near_high', 'jp', 'sell', 26],
                ['relative_strength', 'strong', 'jp', 'sell', 26],
                ['holding', 'none', 'jp', 'sell', 26],
            ]);

        foreach ($result->cells as $cell) {
            expect(tcaCounts($cell))->toBe([2, 2, 0, 0]);
            tcaExpectDistribution($cell, [0.15, 0.2, 0.25]);
            expect($cell->recordedTrades)->toBe(2);
        }
    });
});

describe('UC-019 状況別の集計: セルに入った売買の一覧', function () {
    test('セルはそのセルに入った売買のIDを約定日の新しい順・同日はIDの大きい順に持ち、比較のない売買・状況のない売買・定型は含めない', function () {
        // Arrange (created so that id order ≠ date order):
        //   $a3 stock A day 50 ok, $a1 / $a2 stock A day 0 ok / unavailable, $b1 stock B day 20 pending
        //   excluded: $c1 only a +13週 comparison, $d1 no situation, $r1 routine
        $a = tcaHolding('1111');
        $b = tcaHolding('2222');
        $c = tcaHolding('3333');
        $d = tcaHolding('4444');
        $a3 = tcaTrade($a, 'sell', 50);
        $a1 = tcaTrade($a, 'sell', 0);
        $a2 = tcaTrade($a, 'sell', 0);
        $b1 = tcaTrade($b, 'sell', 20);
        $c1 = tcaTrade($c, 'sell', 30);
        $d1 = tcaTrade($d, 'sell', 30);
        $r1 = tcaTrade($a, 'sell', 10, routine: true);

        $situations = [];

        foreach ([$a3, $a1, $a2, $b1, $c1, $r1] as $trade) {
            $situations[$trade->id] = tcaSituation($trade);
        }

        $comparisons = [
            tcaCmp($a3, 'sell', 26, 'ok', 0.1), tcaCmp($a1, 'sell', 26, 'ok', 0.2),
            tcaCmp($a2, 'sell', 26, 'unavailable'), tcaCmp($b1, 'sell', 26, 'pending'),
            tcaCmp($c1, 'sell', 13, 'ok', 0.3), tcaCmp($d1, 'sell', 26, 'ok', 0.4),
            tcaCmp($r1, 'sell', 26, 'ok', 0.5),
        ];

        // Oldest first, so neither keeping the input order nor sorting for clustering passes
        $trades = TradeExecution::query()->orderBy('trade_date')->orderBy('id')->get();

        // Act
        $result = app(TradeClusterAggregator::class)->aggregate($trades, $situations, $comparisons);

        // Assert
        $expected = [$a3->id, $b1->id, $a2->id, $a1->id];
        expect(tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 26)->tradeIds)->toBe($expected);
        expect(tcaCell($result, 'relative_strength', 'strong', 'jp', 'sell', 26)->tradeIds)->toBe($expected);
        expect(tcaCell($result, 'holding', 'none', 'jp', 'sell', 26)->tradeIds)->toBe($expected);
        expect(tcaCell($result, 'high_ratio', 'near_high', 'jp', 'sell', 13)->tradeIds)->toBe([$c1->id]);
    });
});
