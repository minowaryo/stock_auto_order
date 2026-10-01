<?php

namespace Tests\Feature;

use App\Actions\Concentration\ShowConcentrationDashboardAction;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\IndexWeeklyPrice;
use App\Models\Snapshot;
use App\Models\WeeklyPrice;
use App\Services\Concentration\CorrelationMatrixCalculator;
use App\Services\Concentration\EffectiveBetCalculator;
use App\Services\Concentration\HoldingWeightCalculator;
use App\Services\Concentration\PrincipalComponentAnalyzer;
use App\Services\Concentration\SoxBetaCalculator;
use App\Services\Concentration\WeeklyReturnMatrixBuilder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| UC-015: 集中度ダッシュボード — ShowConcentrationDashboardAction（Red phase, CHG-0026 Cycle4）
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0019-concentration-dashboard.md D1〜D7
|   - docs/product/use-cases.md UC-015 出力表・業務ルール・エラーケース
|   - 既にGreenの純粋サービス app/Services/Concentration/*（期待値はテスト内で
|     同じ固定データに対してこれらを呼んで算出する。本テストの目的は配線の検証）
|
| Contract (flag at Gate 4 if a different shape is preferred):
|   App\Actions\Concentration\ShowConcentrationDashboardAction::execute(): array
|   (non-final, real DB, read-only). 返却キーは次の13個ちょうど:
|   has_snapshot, window_start_week, window_end_week, included_count,
|   coverage_rate, pc1_share, effective_number_of_bets, portfolio_sox_beta,
|   top5_weight, correlation_matrix, hidden_count, sox_betas, excluded
|   （パーセント値は「%単位の数値」。例 14.7。0.147ではない）
|
| Expected Red: "Class App\Actions\Concentration\ShowConcentrationDashboardAction not found".
*/

const CONC_DASH_ACTION_TEST_END = '2026-09-28'; // Monday

/**
 * @return list<string> ascending Mondays ending at $end
 */
function concDashActionTestMondays(int $count, string $end = CONC_DASH_ACTION_TEST_END): array
{
    $last = new DateTimeImmutable($end, new DateTimeZone('UTC'));
    $dates = [];
    for ($i = $count - 1; $i >= 0; $i--) {
        $dates[] = $last->modify("-{$i} weeks")->format('Y-m-d');
    }

    return $dates;
}

/**
 * Deterministic weekly closes (4-decimal rounded, same as the DB column).
 * Each seed gets its own wiggle plus a shared component so series are
 * partially correlated.
 *
 * @return array<string, float> Monday Y-m-d => close
 */
function concDashActionTestSeries(int $seed, int $count = 53): array
{
    $dates = concDashActionTestMondays($count);
    $close = 100.0 + $seed * 10;
    $series = [];
    foreach ($dates as $k => $date) {
        if ($k > 0) {
            $r = 0.01 * sin($k * (0.7 + 0.13 * $seed) + $seed) + 0.006 * sin($k * 0.9);
            $close = round($close * (1 + $r), 4);
        }
        $series[$date] = round($close, 4);
    }

    return $series;
}

/**
 * @return array<string, float>
 */
function concDashActionTestSoxSeries(int $count = 53): array
{
    $dates = concDashActionTestMondays($count);
    $close = 5000.0;
    $series = [];
    foreach ($dates as $k => $date) {
        if ($k > 0) {
            $close = round($close * (1 + 0.012 * sin($k * 0.9) + 0.004 * cos($k * 1.7)), 4);
        }
        $series[$date] = round($close, 4);
    }

    return $series;
}

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function concDashActionTestSnapshot(?DateTimeImmutable $at = null): array
{
    $at ??= new DateTimeImmutable('now');
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => $at,
    ]);
    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => $at,
    ]);

    return [$batch, $snapshot];
}

/**
 * Creates a holding + holding snapshot (+ weekly closes when given).
 *
 * @param  array<string, float>|null  $closes
 * @return array{id: int, instrument_type: string, quantity: float, current_price: float, code: string, name: string}
 */
function concDashActionTestPosition(Snapshot $snapshot, string $code, string $type, float $quantity, float $price, ?array $closes = null): array
{
    $holding = Holding::create([
        'symbol_code' => $code,
        'market' => $type === 'mutual_fund' ? 'mutual_fund' : 'jp',
        'instrument_type' => $type,
        'symbol_name' => '名称'.$code,
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ]);
    HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => $quantity,
        'average_cost' => 1,
        'current_price' => $price,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 0,
        'unrealized_gain_rate' => 0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ]);
    if ($closes !== null) {
        $rows = [];
        foreach ($closes as $date => $close) {
            $rows[] = ['holding_id' => $holding->id, 'week_date' => $date, 'close' => $close, 'volume' => 1000];
        }
        WeeklyPrice::insert($rows);
    }

    return [
        'id' => $holding->id,
        'instrument_type' => $type,
        'quantity' => $quantity,
        'current_price' => $price,
        'code' => $code,
        'name' => '名称'.$code,
    ];
}

/**
 * @param  array<string, float>  $closes
 */
function concDashActionTestSeedSox(array $closes): void
{
    $rows = [];
    foreach ($closes as $date => $close) {
        $rows[] = ['index_name' => 'sox', 'week_date' => $date, 'close' => $close];
    }
    IndexWeeklyPrice::insert($rows);
}

/**
 * Expected values computed with the already-Green pure services.
 *
 * @param  list<array{id: int, instrument_type: string, quantity: float, current_price: float}>  $positions
 * @param  array<int, array<string, float>>  $closesById
 * @param  array<string, float>  $sox
 * @return array{included: list<int>, returns: array<int, list<float>>, weights: array<int, float>, pc1: ?float, enb: ?float, portfolio_beta: ?float, betas: array<int, ?float>, corr: array<int, array<int, ?float>>, market_values: array<int, float>}
 */
function concDashActionTestExpected(array $positions, array $closesById, array $sox): array
{
    $weightCalc = new HoldingWeightCalculator;
    $mvs = $weightCalc->marketValues(array_map(fn ($p) => [
        'holding_id' => $p['id'],
        'instrument_type' => $p['instrument_type'],
        'quantity' => $p['quantity'],
        'current_price' => $p['current_price'],
    ], $positions));

    $built = (new WeeklyReturnMatrixBuilder)->build(
        array_map(fn ($p) => ['holding_id' => $p['id'], 'instrument_type' => $p['instrument_type']], $positions),
        $closesById,
        $sox,
    );
    $returns = $built['returns'];
    $included = array_keys($returns);
    $weights = $weightCalc->normalize($mvs, $included);

    $enough = count($included) >= 2;
    $betas = $built['sox_returns'] === null
        ? array_fill_keys($included, null)
        : (new SoxBetaCalculator)->betas($returns, $built['sox_returns']);

    return [
        'included' => $included,
        'returns' => $returns,
        'weights' => $weights,
        'market_values' => $mvs,
        'pc1' => $enough ? (new PrincipalComponentAnalyzer)->firstComponentShare($returns) : null,
        'enb' => $enough ? (new EffectiveBetCalculator)->calculate($returns, $weights) : null,
        'portfolio_beta' => ($enough && $built['sox_returns'] !== null)
            ? (new SoxBetaCalculator)->portfolioBeta($betas, $weights)
            : null,
        'betas' => $betas,
        'corr' => $included === [] ? [] : (new CorrelationMatrixCalculator)->calculate($returns),
    ];
}

function concDashActionTestKeys(): array
{
    $keys = [
        'has_snapshot', 'window_start_week', 'window_end_week', 'included_count', 'coverage_rate',
        'pc1_share', 'effective_number_of_bets', 'portfolio_sox_beta', 'top5_weight',
        'correlation_matrix', 'hidden_count', 'sox_betas', 'excluded',
    ];
    sort($keys);

    return $keys;
}

describe('UC-015: 集中度ダッシュボード（ShowConcentrationDashboardAction）', function () {
    test('UC-015: スナップショットが無いときは has_snapshot=false で他の値は null/0/空になる', function () {
        // Arrange: no data

        // Act
        $result = app(ShowConcentrationDashboardAction::class)->execute();

        // Assert
        $keys = array_keys($result);
        sort($keys);
        expect($keys)->toBe(concDashActionTestKeys());
        expect($result['has_snapshot'])->toBeFalse();
        expect($result['window_start_week'])->toBeNull();
        expect($result['window_end_week'])->toBeNull();
        expect($result['included_count'])->toBe(0);
        expect($result['coverage_rate'])->toBeNull();
        expect($result['pc1_share'])->toBeNull();
        expect($result['effective_number_of_bets'])->toBeNull();
        expect($result['portfolio_sox_beta'])->toBeNull();
        expect($result['top5_weight'])->toBeNull();
        expect($result['correlation_matrix'])->toBe([]);
        expect($result['hidden_count'])->toBe(0);
        expect($result['sox_betas'])->toBe([]);
        expect($result['excluded'])->toBe([]);
    });

    test('UC-015: 個別株・週足不足株・ETF・投信の混在で5指標・窓・カバー率・除外一覧が算出される', function () {
        // Arrange: A(400) B(300) C(200) full history, D(50) 10 weeks, E ETF(30), F fund(20)
        [, $snapshot] = concDashActionTestSnapshot();
        $a = concDashActionTestPosition($snapshot, 'A001', 'stock', 100, 4.0, concDashActionTestSeries(1));
        $b = concDashActionTestPosition($snapshot, 'B002', 'stock', 100, 3.0, concDashActionTestSeries(2));
        $c = concDashActionTestPosition($snapshot, 'C003', 'stock', 100, 2.0, concDashActionTestSeries(3));
        $d = concDashActionTestPosition($snapshot, 'D004', 'stock', 10, 5.0, concDashActionTestSeries(4, 10));
        $e = concDashActionTestPosition($snapshot, 'E005', 'etf', 10, 3.0);
        $f = concDashActionTestPosition($snapshot, 'F006', 'mutual_fund', 200000, 1.0);
        $sox = concDashActionTestSoxSeries();
        concDashActionTestSeedSox($sox);

        $positions = [$a, $b, $c, $d, $e, $f];
        $closesById = [
            $a['id'] => concDashActionTestSeries(1),
            $b['id'] => concDashActionTestSeries(2),
            $c['id'] => concDashActionTestSeries(3),
            $d['id'] => concDashActionTestSeries(4, 10),
        ];
        $expected = concDashActionTestExpected($positions, $closesById, $sox);

        // Act
        $result = app(ShowConcentrationDashboardAction::class)->execute();

        // Assert: shape and window
        $keys = array_keys($result);
        sort($keys);
        expect($keys)->toBe(concDashActionTestKeys());
        expect($result['has_snapshot'])->toBeTrue();
        expect($result['window_end_week'])->toBe(CONC_DASH_ACTION_TEST_END);
        expect($result['window_start_week'])->toBe(
            (new DateTimeImmutable(CONC_DASH_ACTION_TEST_END))->modify('-364 days')->format('Y-m-d')
        );
        expect($result['included_count'])->toBe(3);
        expect($result['hidden_count'])->toBe(0);

        // Assert: coverage / top5 (all holdings incl. ETF and fund are the population)
        expect($result['coverage_rate'])->toEqualWithDelta(90.0, 1e-9);
        expect($result['top5_weight'])->toEqualWithDelta(98.0, 1e-9);

        // Assert: excluded, in holding-snapshot id order
        expect($result['excluded'])->toBe([
            ['symbol_code' => 'D004', 'symbol_name' => '名称D004', 'reason' => 'insufficient_history'],
            ['symbol_code' => 'E005', 'symbol_name' => '名称E005', 'reason' => 'not_stock'],
            ['symbol_code' => 'F006', 'symbol_name' => '名称F006', 'reason' => 'not_stock'],
        ]);

        // Assert: pc1 / ENB / beta wired to the pure services (weights normalized within included)
        expect($expected['pc1'])->not->toBeNull();
        expect($result['pc1_share'])->toEqualWithDelta($expected['pc1'] * 100, 1e-9);
        expect($result['effective_number_of_bets'])->toEqualWithDelta($expected['enb'], 1e-9);
        expect($result['portfolio_sox_beta'])->toEqualWithDelta($expected['portfolio_beta'], 1e-9);

        // Assert: correlation matrix rows ordered by market value desc, n x n aligned
        expect(array_column($result['correlation_matrix'], 'symbol_code'))->toBe(['A001', 'B002', 'C003']);
        $order = [$a['id'], $b['id'], $c['id']];
        foreach ($result['correlation_matrix'] as $i => $row) {
            expect(array_keys($row))->toBe(['holding_id', 'symbol_code', 'symbol_name', 'correlations']);
            expect($row['holding_id'])->toBe($order[$i]);
            expect($row['symbol_name'])->toBe('名称'.$row['symbol_code']);
            expect($row['correlations'])->toHaveCount(3);
            foreach ($row['correlations'] as $j => $value) {
                expect($value)->toEqualWithDelta($expected['corr'][$order[$i]][$order[$j]], 1e-9);
            }
            expect($row['correlations'][$i])->toEqualWithDelta(1.0, 1e-9);
        }

        // Assert: sox beta list for all included holdings, market value desc, weight in % within included
        expect($result['sox_betas'])->toHaveCount(3);
        foreach ($result['sox_betas'] as $i => $row) {
            expect(array_keys($row))->toBe(['symbol_code', 'symbol_name', 'weight', 'sox_beta']);
            expect($row['symbol_code'])->toBe(['A001', 'B002', 'C003'][$i]);
            expect($row['weight'])->toEqualWithDelta($expected['weights'][$order[$i]] * 100, 1e-9);
            expect($row['sox_beta'])->toEqualWithDelta($expected['betas'][$order[$i]], 1e-9);
        }
        expect($result['sox_betas'][0]['weight'])->toEqualWithDelta(400 / 900 * 100, 1e-9);
    });

    test('UC-015: 計算対象が22銘柄のとき相関行列は評価額上位20銘柄だけで、表示外2銘柄と全22銘柄のSOXベータ一覧が返る', function () {
        // Arrange: 22 stocks with distinct market values (created in ascending order of value)
        [, $snapshot] = concDashActionTestSnapshot();
        $sox = concDashActionTestSoxSeries();
        concDashActionTestSeedSox($sox);
        for ($i = 1; $i <= 22; $i++) {
            concDashActionTestPosition($snapshot, sprintf('S%02d', $i), 'stock', 100, 10.0 + $i, concDashActionTestSeries($i));
        }

        // Act
        $result = app(ShowConcentrationDashboardAction::class)->execute();

        // Assert
        expect($result['included_count'])->toBe(22);
        expect($result['correlation_matrix'])->toHaveCount(20);
        expect($result['hidden_count'])->toBe(2);
        $expectedCodes = array_map(fn ($i) => sprintf('S%02d', $i), range(22, 3));
        expect(array_column($result['correlation_matrix'], 'symbol_code'))->toBe($expectedCodes);
        foreach ($result['correlation_matrix'] as $row) {
            expect($row['correlations'])->toHaveCount(20);
        }
        expect($result['sox_betas'])->toHaveCount(22);
        expect(array_column($result['sox_betas'], 'symbol_code')[0])->toBe('S22');
        expect(array_column($result['sox_betas'], 'symbol_code')[21])->toBe('S01');
        expect($result['excluded'])->toBe([]);
    });

    test('UC-015: 最新スナップショットの保有だけが対象で、古いスナップショットの保有はどこにも現れない', function () {
        // Arrange: older snapshot holds OLD1 (huge value); latest holds A, B
        [, $older] = concDashActionTestSnapshot(new DateTimeImmutable('-7 days'));
        concDashActionTestPosition($older, 'OLD1', 'stock', 100000, 9999.0, concDashActionTestSeries(9));
        [, $latest] = concDashActionTestSnapshot(new DateTimeImmutable('now'));
        $a = concDashActionTestPosition($latest, 'A001', 'stock', 100, 4.0, concDashActionTestSeries(1));
        $b = concDashActionTestPosition($latest, 'B002', 'stock', 100, 3.0, concDashActionTestSeries(2));
        $etf = concDashActionTestPosition($latest, 'E005', 'etf', 10, 3.0);
        concDashActionTestSeedSox(concDashActionTestSoxSeries());

        // Act
        $result = app(ShowConcentrationDashboardAction::class)->execute();

        // Assert
        expect($result['included_count'])->toBe(2);
        expect($result['coverage_rate'])->toEqualWithDelta(700 / 730 * 100, 1e-9);
        expect($result['top5_weight'])->toEqualWithDelta(100.0, 1e-9);
        expect(json_encode($result, JSON_UNESCAPED_UNICODE))->not->toContain('OLD1');
        expect(array_column($result['correlation_matrix'], 'symbol_code'))->toBe(['A001', 'B002']);
        expect(array_column($result['sox_betas'], 'symbol_code'))->toBe(['A001', 'B002']);
        expect(array_column($result['excluded'], 'symbol_code'))->toBe(['E005']);
    });

    test('UC-015: SOXの週足が無い・窓を覆わないときは対SOXベータだけ null で、PC1寄与率と実効ベット数は算出される', function () {
        // Arrange: 3 stocks; SOX only covers the last 30 weeks (window needs 53)
        [, $snapshot] = concDashActionTestSnapshot();
        concDashActionTestPosition($snapshot, 'A001', 'stock', 100, 4.0, concDashActionTestSeries(1));
        concDashActionTestPosition($snapshot, 'B002', 'stock', 100, 3.0, concDashActionTestSeries(2));
        concDashActionTestPosition($snapshot, 'C003', 'stock', 100, 2.0, concDashActionTestSeries(3));
        concDashActionTestSeedSox(concDashActionTestSoxSeries(30));

        // Act: partial SOX coverage
        $partial = app(ShowConcentrationDashboardAction::class)->execute();

        // Assert
        expect($partial['portfolio_sox_beta'])->toBeNull();
        expect($partial['sox_betas'])->toHaveCount(3);
        foreach ($partial['sox_betas'] as $row) {
            expect($row['sox_beta'])->toBeNull();
        }
        expect($partial['pc1_share'])->not->toBeNull();
        expect($partial['effective_number_of_bets'])->not->toBeNull();
        expect($partial['correlation_matrix'])->toHaveCount(3);

        // Act: no SOX rows at all
        IndexWeeklyPrice::query()->delete();
        $none = app(ShowConcentrationDashboardAction::class)->execute();

        // Assert
        expect($none['portfolio_sox_beta'])->toBeNull();
        foreach ($none['sox_betas'] as $row) {
            expect($row['sox_beta'])->toBeNull();
        }
        expect($none['pc1_share'])->toEqualWithDelta($partial['pc1_share'], 1e-12);
        expect($none['effective_number_of_bets'])->toEqualWithDelta($partial['effective_number_of_bets'], 1e-12);
    });

    test('UC-015: 計算対象が2銘柄未満のときPC1・実効ベット数・ポートフォリオβは null、相関行列は空で、上位5銘柄ウェイトと除外一覧は残る', function () {
        // Arrange: one full-history stock + ETF + mutual fund + short-history stock
        [, $snapshot] = concDashActionTestSnapshot();
        $a = concDashActionTestPosition($snapshot, 'A001', 'stock', 100, 4.0, concDashActionTestSeries(1));
        concDashActionTestPosition($snapshot, 'E005', 'etf', 10, 3.0);
        concDashActionTestPosition($snapshot, 'F006', 'mutual_fund', 200000, 1.0);
        concDashActionTestPosition($snapshot, 'D004', 'stock', 10, 5.0, concDashActionTestSeries(4, 10));
        $sox = concDashActionTestSoxSeries();
        concDashActionTestSeedSox($sox);
        $expected = concDashActionTestExpected(
            [$a],
            [$a['id'] => concDashActionTestSeries(1)],
            $sox,
        );

        // Act
        $result = app(ShowConcentrationDashboardAction::class)->execute();

        // Assert
        expect($result['has_snapshot'])->toBeTrue();
        expect($result['included_count'])->toBe(1);
        expect($result['pc1_share'])->toBeNull();
        expect($result['effective_number_of_bets'])->toBeNull();
        expect($result['portfolio_sox_beta'])->toBeNull();
        expect($result['correlation_matrix'])->toBe([]);
        expect($result['hidden_count'])->toBe(1);
        expect($result['sox_betas'])->toHaveCount(1);
        expect($result['sox_betas'][0]['symbol_code'])->toBe('A001');
        expect($result['sox_betas'][0]['weight'])->toEqualWithDelta(100.0, 1e-9);
        expect($result['sox_betas'][0]['sox_beta'])->toEqualWithDelta($expected['betas'][$a['id']], 1e-9);
        // total 400 + 30 + 20 + 50 = 500; 4 holdings <= 5 so top5 = 100%
        expect($result['top5_weight'])->toEqualWithDelta(100.0, 1e-9);
        expect($result['coverage_rate'])->toEqualWithDelta(400 / 500 * 100, 1e-9);
        expect(array_column($result['excluded'], 'reason', 'symbol_code'))->toBe([
            'E005' => 'not_stock',
            'F006' => 'not_stock',
            'D004' => 'insufficient_history',
        ]);
    });

    test('UC-015: execute() は読み取り専用で、どのテーブルの行も増減・変更しない', function () {
        // Arrange
        [, $snapshot] = concDashActionTestSnapshot();
        concDashActionTestPosition($snapshot, 'A001', 'stock', 100, 4.0, concDashActionTestSeries(1));
        concDashActionTestPosition($snapshot, 'B002', 'stock', 100, 3.0, concDashActionTestSeries(2));
        concDashActionTestSeedSox(concDashActionTestSoxSeries());
        $tables = ['weekly_prices', 'index_weekly_prices', 'holdings', 'snapshots', 'holding_snapshots', 'import_batches', 'signals', 'technical_indicators'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $weeklyChecksum = DB::table('weekly_prices')->orderBy('id')->get()->toJson();

        // Act
        app(ShowConcentrationDashboardAction::class)->execute();
        app(ShowConcentrationDashboardAction::class)->execute();

        // Assert
        $after = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        expect($after)->toBe($before);
        expect(DB::table('weekly_prices')->orderBy('id')->get()->toJson())->toBe($weeklyChecksum);
    });
});
