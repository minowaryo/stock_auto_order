<?php

namespace Tests\Feature;

use App\Actions\Analysis\FetchExternalMarketDataAction;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Services\MarketData\JpStockPriceClientInterface;
use App\Services\MarketData\JQuantsClientInterface;
use App\Services\MarketData\MarketIndexClientInterface;
use App\Services\MarketData\UsStockPriceClientInterface;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fakes\FakeJpStockPriceClient;
use Tests\Support\Fakes\FakeJQuantsClient;
use Tests\Support\Fakes\FakeMarketIndexClient;
use Tests\Support\Fakes\FakeUsStockPriceClient;

/*
|--------------------------------------------------------------------------
| FetchExternalMarketDataAction — buy_signals persistence (UC-010, Red phase)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/architecture/data-model.md (`buy_signals` table: "含み益率による
|     対象銘柄の絞り込みは行わない"、`(holding_snapshot_id, signal_type)`
|     unique制約、"7種いずれのシグナルも...全シグナル共通の前提条件"、変更履歴
|     2026-08-23エントリ「FetchExternalMarketDataAction」)
|   - docs/product/use-cases.md (UC-001 フロー9: 「買い増しシグナルを判定し保存
|     する。利確シグナル（フロー8）とは判定対象・保存先ともに独立しており、含み益率
|     による対象銘柄の絞り込みは行わない」)
|   - docs/adr/ADR-0007-existing-holding-add-on-buy-recommendation.md
|     (D2/D3: buy_signals新テーブル・BuySignalDeterminationService新設は
|     FetchExternalMarketDataActionの既存signals永続化ロジックを変更せず統合する
|     想定)
|   - tests/Feature/FetchExternalMarketDataActionTest.php (このAction自体の
|     既存Red/Green済みFeature Test。584行目付近・639行目付近の signals
|     永続化・再判定時削除・per-holding例外分離のテストと等価な買い側テストを
|     ここに新規ファイルとして追加する。既存ファイルは一切編集しない）
|
| This is a NEW, separate file (per task instructions: do not edit the
| existing 1000+ line FetchExternalMarketDataActionTest.php). Helper
| functions below are prefixed `fmb` (FetchMarketData Buy-signal) — distinct
| names from that file's `femd*` helpers — so both files can coexist safely
| within the shared `Tests\Feature` namespace regardless of whether a full
| suite run or a single-file run causes both files' functions to be
| registered simultaneously (mimics, rather than reuses, that file's
| DI-mock/fixture-builder pattern, per the task instructions).
|
| Expected Red cause (verified before writing this file):
|   App\Actions\Analysis\FetchExternalMarketDataAction ALREADY EXISTS and is
|   already Green for the take-profit (`signals`) side (verified by reading
|   the file: it only ever touches `App\Models\Signal`/the `signals` table,
|   gated by `unrealized_gain_rate > 20`; it does not reference
|   BuySignalDeterminationService or a `buy_signals` table anywhere). So
|   `execute()` itself runs to completion without error for every fixture
|   below — it silently does nothing buy-signal-related yet. Every test's Red
|   cause is therefore NOT an exception from `execute()` itself, but a
|   `PDOException`/`QueryException` ("Base table or column not found: ...
|   'buy_signals' doesn't exist") raised by this file's own assertion helper
|   (`fmbBuySignalRows()`) when it queries `DB::table('buy_signals')`
|   afterward — the `buy_signals` migration does not exist yet (verified via
|   Glob against database/migrations/ before writing this file). This is the
|   intentional Red state: it demonstrates the CURRENT implementation gap
|   (no buy-signal persistence wired in at all) rather than a typo, and
|   mirrors this suite's established "assert on the real DB row" convention
|   (`femdAssertTechnicalIndicatorMatches` style) rather than depending on an
|   `App\Models\BuySignal` Eloquent model that also does not exist yet.
|
| Fixture note: the JP holding's price history below is the EXACT same
| 52-week `rsi_oversold_rebound` fixture already independently verified via
| the Docker/Sail PHP `TechnicalIndicatorCalculator` script for
| BuySignalDeterminationServiceTest.php (see that file's top-of-file
| docblock for the verification methodology) — 39-week build-up (100..138)
| + 13-week tail ending in a rebound — paired with a 14-point nikkei225
| history whose own 13-week return is exactly -35.0% (by construction: first
| close 30000, last close 19500 → (19500-30000)/30000*100 = -35.0 exactly),
| identical to the `marketReturn13w: -35.0` argument used in that Unit
| Test's fire case. This reproduces the same verified
| relative_strength_vs_market ≈ +3.84 (>= -5.0, precondition B satisfied)
| and the same precondition-A-satisfying week52_high proximity, so once
| BuySignalDeterminationService/the persistence wiring exist, this fixture
| is expected to produce a `rsi_oversold_rebound` buy_signals row.
|
*/

/**
 * @return array<int, float>
 */
function fmbCloses(float $start, float $step, int $count): array
{
    $closes = [];

    for ($i = 0; $i < $count; $i++) {
        $closes[] = $start + $step * $i;
    }

    return $closes;
}

/**
 * @param  array<int, float>  $closes
 * @param  int|array<int, int>  $volumes
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function fmbPriceHistory(array $closes, int|array $volumes = 100000, string $startDate = '2024-01-01'): array
{
    $history = [];
    $date = new \DateTimeImmutable($startDate);

    foreach ($closes as $i => $close) {
        $volume = is_array($volumes) ? $volumes[$i] : $volumes;

        $history[] = [
            'date' => $date->modify("+{$i} weeks")->format('Y-m-d'),
            'close' => (float) $close,
            'volume' => (int) $volume,
        ];
    }

    return $history;
}

/**
 * The verified rsi_oversold_rebound fixture: 39-week build-up (100..138)
 * + 13-week tail [134,130,126,122,118,114,110,106,102,98,94,90,95] (previous
 * rsi=4.0, current rsi≈11.111; week52_high=138 with a recent-13-week close
 * >= 117.3; verified via the Docker PHP script — see file docblock).
 *
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function fmbRsiReboundPriceHistory(): array
{
    $closes = array_merge(
        range(100, 138),
        [134, 130, 126, 122, 118, 114, 110, 106, 102, 98, 94, 90, 95],
    );

    return fmbPriceHistory(array_map(fn (int|float $v) => (float) $v, $closes));
}

/**
 * A calm, monotonically-rising 52-week fixture verified to satisfy neither
 * this fixture's own RSI/MACD/BB/week52_low/MA20-deviation/volume
 * conditions (reused from BuySignalDeterminationServiceTest.php's PEG
 * isolation fixture) — used for the re-determination/deletion test's second
 * run, where no buy signal should fire at all.
 *
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function fmbCalmPriceHistory(): array
{
    return fmbPriceHistory(array_map(fn (int $v) => (float) $v, range(100, 151)));
}

/**
 * 14-point nikkei225 fixture whose own 13-week return is exactly -35.0%
 * (first close 30000, last close 19500), matching the
 * `marketReturn13w: -35.0` argument verified for the rsi_oversold_rebound
 * fixture above.
 *
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function fmbNikkeiHistory(): array
{
    // step = (19500 - 30000) / 13 = -807.6923076923077, so index13 (the 14th
    // and last point) lands on exactly 19500: (19500-30000)/30000*100 = -35.0%.
    return fmbPriceHistory(fmbCloses(30000.0, -807.6923076923077, 14), 1_000_000, '2023-01-02');
}

/**
 * @return array<int, array{date: string, close: float, volume: int}>
 */
function fmbSp500History(): array
{
    return fmbPriceHistory(fmbCloses(4500.0, 20.0, 14), 1_000_000, '2023-01-02');
}

/**
 * @return array{0: ImportBatch, 1: Snapshot}
 */
function fmbImportBatch(?\DateTimeInterface $snapshottedAt = null): array
{
    $snapshottedAt ??= now();

    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => $snapshottedAt,
    ]);

    $snapshot = Snapshot::create([
        'import_batch_id' => $batch->id,
        'snapshotted_at' => $snapshottedAt,
    ]);

    return [$batch, $snapshot];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function fmbHolding(array $attributes = []): Holding
{
    return Holding::create(array_merge([
        'symbol_code' => '7203',
        'market' => 'jp',
        'instrument_type' => 'stock',
        'symbol_name' => 'トヨタ自動車',
        'sector_classification_id' => null,
        'first_detected_at' => now(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function fmbHoldingSnapshot(Snapshot $snapshot, Holding $holding, array $attributes = []): HoldingSnapshot
{
    return HoldingSnapshot::create(array_merge([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 100,
        'average_cost' => 1200,
        'current_price' => 950,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => -25000,
        'unrealized_gain_rate' => -20.83, // 20%超えていない/マイナス: 利確シグナル対象外だが買いシグナルは対象内であるはず
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ], $attributes));
}

/**
 * Binds the 4 MarketData Fakes into the container and resolves the Action
 * under test (mirrors FetchExternalMarketDataActionTest.php's femdAction(),
 * duplicated with a distinct name per the file-level docblock).
 */
function fmbAction(
    FakeJpStockPriceClient $jpStockPriceClient,
    FakeUsStockPriceClient $usStockPriceClient,
    FakeMarketIndexClient $marketIndexClient,
    FakeJQuantsClient $jQuantsClient,
): FetchExternalMarketDataAction {
    app()->instance(JpStockPriceClientInterface::class, $jpStockPriceClient);
    app()->instance(UsStockPriceClientInterface::class, $usStockPriceClient);
    app()->instance(MarketIndexClientInterface::class, $marketIndexClient);
    app()->instance(JQuantsClientInterface::class, $jQuantsClient);

    return app(FetchExternalMarketDataAction::class);
}

/**
 * Queries the `buy_signals` table directly (no Eloquent model dependency —
 * see file docblock: App\Models\BuySignal does not exist yet either, and
 * this file intentionally isolates the Red cause to the missing table/
 * migration rather than a missing model class).
 *
 * @return array<int, object>
 */
function fmbBuySignalRows(int $holdingSnapshotId): array
{
    return DB::table('buy_signals')->where('holding_snapshot_id', $holdingSnapshotId)->get()->all();
}

describe('FetchExternalMarketDataAction: buy_signals永続化（UC-010）', function () {
    test('買いシグナル条件を満たす銘柄について、execute()実行後にbuy_signalsへ該当行が保存される', function () {
        [$batch, $snapshot] = fmbImportBatch();
        $holding = fmbHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
        $holdingSnapshot = fmbHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 10.0]);

        $action = fmbAction(
            new FakeJpStockPriceClient(['7203' => fmbRsiReboundPriceHistory()]),
            new FakeUsStockPriceClient,
            new FakeMarketIndexClient(['nikkei225' => fmbNikkeiHistory(), 'sp500' => fmbSp500History()]),
            new FakeJQuantsClient(['7203' => null]),
        );

        $action->execute($batch);

        $rows = fmbBuySignalRows($holdingSnapshot->id);
        expect($rows)->not->toBeEmpty();
        $signalTypes = array_map(fn ($row) => $row->signal_type, $rows);
        expect($signalTypes)->toContain('rsi_oversold_rebound');
    });

    test('含み益率にかかわらず（+20%を超えていなくても、マイナスでも）買いシグナル判定自体は実行される', function () {
        // UC-004の利確判定は含み益+20%ゲートの内側でのみ動くが、data-model.mdの
        // buy_signals節が明記する通り「含み益率による対象銘柄の絞り込みは行わない」ため、
        // 含み損（マイナスの含み益率）の銘柄でも買いシグナル判定は実行されるはず。
        [$batch, $snapshot] = fmbImportBatch();
        $holding = fmbHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
        $holdingSnapshot = fmbHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => -20.83]);

        $action = fmbAction(
            new FakeJpStockPriceClient(['7203' => fmbRsiReboundPriceHistory()]),
            new FakeUsStockPriceClient,
            new FakeMarketIndexClient(['nikkei225' => fmbNikkeiHistory(), 'sp500' => fmbSp500History()]),
            new FakeJQuantsClient(['7203' => null]),
        );

        $action->execute($batch);

        $rows = fmbBuySignalRows($holdingSnapshot->id);
        expect($rows)->not->toBeEmpty();
    });

    test('再実行（execute()を2回呼ぶ）で、シグナルが発生しなくなった銘柄の古いbuy_signals行が削除される', function () {
        [$batch, $snapshot] = fmbImportBatch();
        $holding = fmbHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
        $holdingSnapshot = fmbHoldingSnapshot($snapshot, $holding, ['unrealized_gain_rate' => 10.0]);

        // 1回目: シグナルが発生する価格推移
        $firstAction = fmbAction(
            new FakeJpStockPriceClient(['7203' => fmbRsiReboundPriceHistory()]),
            new FakeUsStockPriceClient,
            new FakeMarketIndexClient(['nikkei225' => fmbNikkeiHistory(), 'sp500' => fmbSp500History()]),
            new FakeJQuantsClient(['7203' => null]),
        );
        $firstAction->execute($batch);
        expect(fmbBuySignalRows($holdingSnapshot->id))->not->toBeEmpty();

        // 2回目: シグナルが一切発生しない「穏やかな52週上昇」価格推移に差し替えて再実行
        // （BuySignalDeterminationServiceTest.phpのPEG単独発生フィクスチャと同一で、
        // pegRatioを渡さない限りいずれのシグナルも発生しないことを検証済み）
        $secondAction = fmbAction(
            new FakeJpStockPriceClient(['7203' => fmbCalmPriceHistory()]),
            new FakeUsStockPriceClient,
            new FakeMarketIndexClient(['nikkei225' => fmbNikkeiHistory(), 'sp500' => fmbSp500History()]),
            new FakeJQuantsClient(['7203' => null]),
        );
        $secondAction->execute($batch);

        expect(fmbBuySignalRows($holdingSnapshot->id))->toBeEmpty();
    });

    test('1銘柄の処理失敗が他銘柄のbuy_signals処理を巻き込まない', function () {
        [$batch, $snapshot] = fmbImportBatch();

        $failingHolding = fmbHolding(['symbol_code' => '9999', 'market' => 'jp', 'symbol_name' => '取得失敗銘柄']);
        $failingSnapshot = fmbHoldingSnapshot($snapshot, $failingHolding, ['unrealized_gain_rate' => 5.0]);

        $okHolding = fmbHolding(['symbol_code' => '7203', 'market' => 'jp', 'symbol_name' => 'トヨタ自動車']);
        $okSnapshot = fmbHoldingSnapshot($snapshot, $okHolding, ['unrealized_gain_rate' => 10.0]);

        $action = fmbAction(
            new FakeJpStockPriceClient(
                ['7203' => fmbRsiReboundPriceHistory()],
                throwsFor: ['9999'],
            ),
            new FakeUsStockPriceClient,
            new FakeMarketIndexClient(['nikkei225' => fmbNikkeiHistory(), 'sp500' => fmbSp500History()]),
            new FakeJQuantsClient(['7203' => null, '9999' => null]),
        );

        $action->execute($batch);

        expect(fmbBuySignalRows($failingSnapshot->id))->toBeEmpty();
        $okRows = fmbBuySignalRows($okSnapshot->id);
        expect($okRows)->not->toBeEmpty();
        $signalTypes = array_map(fn ($row) => $row->signal_type, $okRows);
        expect($signalTypes)->toContain('rsi_oversold_rebound');
    });
});
