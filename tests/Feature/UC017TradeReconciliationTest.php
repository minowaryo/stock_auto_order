<?php

namespace Tests\Feature;

use App\Actions\Import\ImportTradeHistoryAction;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\HoldingSnapshotAccount;
use App\Models\ImportBatch;
use App\Models\Snapshot;
use App\Models\TradeReconciliationItem;
use Illuminate\Http\UploadedFile;

/*
|--------------------------------------------------------------------------
| UC-017 売買履歴と保有CSVの照合 — Red phase (CHG-0033 Cycle 4)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-017 基本フロー4〜5・出力・異常時
|   - docs/architecture/data-model.md `trade_reconciliation_items`
|   - docs/product/trade-decision-effect-proposal.md 8章（実データでは国内112件・
|     米国47件の保有数量が売買履歴の積み上げと全件一致）
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - ImportTradeHistoryAction::execute() reconciles inside the same
|     transaction, right after saving, against the LATEST holdings snapshot
|     (snapshots.snapshotted_at, id as tiebreaker — same rule as UC-001).
|   - Reconstructed quantity per (holding, account_type) =
|       + buy + transfer_in + tsumitate + split_in − sell − transfer_out
|     over trade_executions with review_status = ok and
|     trades executed before the holdings CSV was imported. The CSV has no
|     execution time, so a trade dated D counts only when the snapshot's
|     snapshotted_at is at or after that market's close on D:
|     jp 15:30 Asia/Tokyo, us 16:00 America/New_York (2026-10-04 本人承認。
|     実データで日付のみの判定は国内16件の不一致、取引終了時刻での判定は
|     過去8回で計2件).
|     Rows flagged missing_in_latest are excluded.
|   - Compared with holding_snapshot_accounts (per account type) for stock /
|     ETF holdings of markets jp / us. Mutual funds are out of scope.
|   - One trade_reconciliation_items row per (holding, account_type) where
|     either side is non-zero:
|       matched        both sides equal (|diff| < 0.0001)
|       snapshot_only  holdings CSV has shares, history reconstructs none
|                      (history_quantity null when the holding/account has no
|                      history row at all, 0 when it nets to zero)
|       history_only   history has shares, holdings CSV has none
|                      (snapshot_quantity null)
|       needs_review   both non-zero but different; reason = '数量不一致'
|     A holding fully sold in history and absent from the CSV gets no row.
|   - Append-only: each import adds its own rows; earlier rows remain.
|   - The import summary exposes ->reconciliation: null when there is no
|     holdings snapshot yet, otherwise an object with snapshotId and counts
|     ['matched' => n, 'snapshot_only' => n, 'history_only' => n,
|      'needs_review' => n]. No snapshot is not an error.
|
| Expected Red: TradeReconciliationItem / its table do not exist and the
| import does not reconcile.
|
*/

const TRC_JP_HEADER = '約定日,受渡日,銘柄コード,銘柄名,市場名称,口座区分,取引区分,売買区分,信用区分,弁済期限,数量［株］,単価［円］,手数料［円］,税金等［円］,諸費用［円］,税区分,受渡金額［円］,建約定日,建単価［円］,建手数料［円］,建手数料消費税［円］,金利（支払）〔円〕,金利（受取）〔円〕,逆日歩／特別空売り料（支払）〔円〕,逆日歩（受取）〔円〕,貸株料,事務管理費〔円〕（税抜）,名義書換料〔円〕（税抜）';

const TRC_US_HEADER = '約定日,受渡日,ティッカー,銘柄名,口座,取引区分,売買区分,信用区分,弁済期限,決済通貨,数量［株］,単価［USドル］,約定代金［USドル］,為替レート,手数料［USドル］,税金［USドル］,受渡金額［USドル］,受渡金額［円］';

/**
 * @param  array<string, string>  $over
 */
function trcJp(array $over = []): string
{
    $row = array_merge([
        '約定日' => '2026/9/1', '受渡日' => '2026/9/3', '銘柄コード' => '1234', '銘柄名' => 'テスト工業',
        '市場名称' => '東証', '口座区分' => '特定', '取引区分' => '現物', '売買区分' => '買付',
        '信用区分' => '-', '弁済期限' => '-', '数量［株］' => '100', '単価［円］' => '1,000.0',
        '手数料［円］' => '0', '税金等［円］' => '0', '諸費用［円］' => '0', '税区分' => '-',
        '受渡金額［円］' => '100,000',
        '建約定日' => '-', '建単価［円］' => '0.0', '建手数料［円］' => '0', '建手数料消費税［円］' => '0',
        '金利（支払）〔円〕' => '0', '金利（受取）〔円〕' => '0', '逆日歩／特別空売り料（支払）〔円〕' => '0',
        '逆日歩（受取）〔円〕' => '0', '貸株料' => '0', '事務管理費〔円〕（税抜）' => '0', '名義書換料〔円〕（税抜）' => '0',
    ], $over);

    return implode(',', array_map(fn (string $c) => str_contains($c, ',') ? '"'.$c.'"' : $c, array_values($row)));
}

/**
 * @param  array<string, string>  $over
 */
function trcUs(array $over = []): string
{
    $row = array_merge([
        '約定日' => '2026/9/1', '受渡日' => '2026/9/3', 'ティッカー' => 'TEST', '銘柄名' => 'Test Corp',
        '口座' => '特定', '取引区分' => '現物', '売買区分' => '買付', '信用区分' => '-', '弁済期限' => '-',
        '決済通貨' => '円', '数量［株］' => '10', '単価［USドル］' => '100.0000', '約定代金［USドル］' => '1,000.00',
        '為替レート' => '150.000', '手数料［USドル］' => '-', '税金［USドル］' => '-',
        '受渡金額［USドル］' => '-', '受渡金額［円］' => '150,000.00',
    ], $over);

    return implode(',', array_map(fn (string $c) => str_contains($c, ',') ? '"'.$c.'"' : $c, array_values($row)));
}

/**
 * @param  array<int, string>  $lines
 */
function trcFile(string $name, string $header, array $lines): UploadedFile
{
    $utf8 = implode("\n", [$header, ...$lines])."\n";

    return UploadedFile::fake()->createWithContent($name, mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8'));
}

/**
 * @param  array<int, string>  $jpLines
 * @param  array<int, string>  $usLines
 */
function trcImport(array $jpLines, array $usLines = []): object
{
    return app(ImportTradeHistoryAction::class)->execute(
        trcFile('tradehistory(JP).csv', TRC_JP_HEADER, $jpLines),
        trcFile('tradehistory(US).csv', TRC_US_HEADER, $usLines),
    );
}

/**
 * Creates a holdings snapshot (UC-001 output) at $at with the given
 * per-account quantities.
 *
 * @param  array<int, array{code: string, market: string, type?: string, accounts: array<string, float|int>}>  $positions
 */
function trcSnapshot(string $at, array $positions): Snapshot
{
    $batch = ImportBatch::create([
        'status' => 'completed', 'jp_stock_filename' => 'jp.csv', 'us_stock_filename' => 'us.csv',
        'mutual_fund_filename' => null, 'imported_count' => count($positions), 'error_count' => 0, 'imported_at' => $at,
    ]);
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => $at]);

    foreach ($positions as $p) {
        $holding = Holding::firstOrCreate(
            ['symbol_code' => $p['code'], 'market' => $p['market']],
            ['instrument_type' => $p['type'] ?? 'stock', 'symbol_name' => '銘柄'.$p['code'], 'first_detected_at' => $at],
        );
        $total = array_sum($p['accounts']);
        $hs = HoldingSnapshot::create([
            'snapshot_id' => $snapshot->id, 'holding_id' => $holding->id, 'quantity' => $total,
            'average_cost' => 1000, 'current_price' => 1000, 'fx_rate_used' => null,
            'unrealized_gain_amount' => 0, 'unrealized_gain_rate' => 0, 'is_newly_detected' => false,
        ]);

        foreach ($p['accounts'] as $accountType => $quantity) {
            HoldingSnapshotAccount::create([
                'holding_snapshot_id' => $hs->id, 'account_type' => $accountType, 'quantity' => $quantity, 'average_cost' => 1000,
            ]);
        }
    }

    return $snapshot;
}

function trcItem(string $code, string $market, ?string $accountType): TradeReconciliationItem
{
    $holdingId = Holding::where('symbol_code', $code)->where('market', $market)->value('id');

    return TradeReconciliationItem::where('holding_id', $holdingId)->where('account_type', $accountType)->sole();
}

describe('UC-017 照合: 一致', function () {
    test('買付・売付・入庫・出庫・積立・分割入庫を積み上げた株数が保有CSVと一致すれば、照合済みになる', function () {
        // Arrange: JP 100 buy − 30 sell + 20 transfer-in − 10 transfer-out = 80; US 10 buy + 1 tsumitate + 3 split = 14
        $snapshot = trcSnapshot('2026-10-03 12:00:00', [
            ['code' => '1234', 'market' => 'jp', 'accounts' => ['specific' => 80]],
            ['code' => 'TEST', 'market' => 'us', 'accounts' => ['specific' => 14]],
        ]);

        // Act
        $result = trcImport([
            trcJp(),
            trcJp(['約定日' => '2026/9/10', '受渡日' => '2026/9/12', '売買区分' => '売付', '数量［株］' => '30']),
            trcJp(['約定日' => '2026/9/11', '取引区分' => '', '売買区分' => '入庫', '数量［株］' => '20', '受渡金額［円］' => '-']),
            trcJp(['約定日' => '2026/9/12', '取引区分' => '', '売買区分' => '出庫', '数量［株］' => '10', '受渡金額［円］' => '-']),
        ], [
            trcUs(),
            trcUs(['約定日' => '2026/9/5', '取引区分' => '積立', '売買区分' => '', '数量［株］' => '1']),
            trcUs(['約定日' => '2026/9/20', '取引区分' => '入庫（分割）', '売買区分' => '', '決済通貨' => '-', '数量［株］' => '3',
                '単価［USドル］' => '-', '約定代金［USドル］' => '-', '為替レート' => '-', '受渡金額［円］' => '-']),
        ]);

        // Assert: items
        $jp = trcItem('1234', 'jp', 'specific');
        expect($jp->status)->toBe('matched');
        expect((float) $jp->history_quantity)->toBe(80.0);
        expect((float) $jp->snapshot_quantity)->toBe(80.0);
        expect($jp->snapshot_id)->toBe($snapshot->id);
        expect($jp->trade_import_batch_id)->toBe($result->batchId);
        expect($jp->reason)->toBeNull();
        expect(trcItem('TEST', 'us', 'specific')->status)->toBe('matched');

        // Assert: summary
        expect($result->reconciliation->snapshotId)->toBe($snapshot->id);
        expect($result->reconciliation->counts)->toBe(['matched' => 2, 'snapshot_only' => 0, 'history_only' => 0, 'needs_review' => 0]);
    });

    test('口座区分ごとに照合し、特定口座とNISAは別の照合結果になる', function () {
        // Arrange
        trcSnapshot('2026-10-03 12:00:00', [
            ['code' => '1234', 'market' => 'jp', 'accounts' => ['specific' => 100, 'nisa_growth' => 50]],
        ]);

        // Act
        trcImport([trcJp(), trcJp(['口座区分' => 'NISA成長投資枠', '数量［株］' => '40'])]);

        // Assert
        expect(trcItem('1234', 'jp', 'specific')->status)->toBe('matched');
        $nisa = trcItem('1234', 'jp', 'nisa_growth');
        expect($nisa->status)->toBe('needs_review');
        expect((float) $nisa->history_quantity)->toBe(40.0);
        expect((float) $nisa->snapshot_quantity)->toBe(50.0);
        expect($nisa->reason)->toBe('数量不一致');
    });
});

describe('UC-017 照合: 不一致の区分', function () {
    test('保有CSVにだけある株は「保有側のみ」、履歴にだけ残る株は「履歴側のみ」になる', function () {
        // Arrange
        trcSnapshot('2026-10-03 12:00:00', [
            ['code' => '7777', 'market' => 'jp', 'accounts' => ['specific' => 200]],
        ]);

        // Act
        $result = trcImport([trcJp(['銘柄コード' => '5555'])]);

        // Assert
        $snapshotOnly = trcItem('7777', 'jp', 'specific');
        expect($snapshotOnly->status)->toBe('snapshot_only');
        expect($snapshotOnly->history_quantity)->toBeNull();
        expect((float) $snapshotOnly->snapshot_quantity)->toBe(200.0);

        $historyOnly = trcItem('5555', 'jp', 'specific');
        expect($historyOnly->status)->toBe('history_only');
        expect((float) $historyOnly->history_quantity)->toBe(100.0);
        expect($historyOnly->snapshot_quantity)->toBeNull();

        expect($result->reconciliation->counts)->toBe(['matched' => 0, 'snapshot_only' => 1, 'history_only' => 1, 'needs_review' => 0]);
    });

    test('履歴で全部売却済みの銘柄が保有CSVにもない場合は、照合結果を作らない', function () {
        // Arrange
        trcSnapshot('2026-10-03 12:00:00', []);

        // Act
        trcImport([trcJp(), trcJp(['約定日' => '2026/9/10', '受渡日' => '2026/9/12', '売買区分' => '売付'])]);

        // Assert
        expect(TradeReconciliationItem::count())->toBe(0);
    });

    test('履歴で売り切った銘柄が保有CSVに残っている場合は、履歴の株数0として「保有側のみ」になる', function () {
        // Arrange
        trcSnapshot('2026-10-03 12:00:00', [
            ['code' => '1234', 'market' => 'jp', 'accounts' => ['specific' => 100]],
        ]);

        // Act
        trcImport([trcJp(), trcJp(['約定日' => '2026/9/10', '受渡日' => '2026/9/12', '売買区分' => '売付'])]);

        // Assert
        $item = trcItem('1234', 'jp', 'specific');
        expect($item->status)->toBe('snapshot_only');
        expect((float) $item->history_quantity)->toBe(0.0);
    });
});

describe('UC-017 照合: 対象と時点', function () {
    test('照合相手は最新の保有スナップショットで、その日付より後の約定は積み上げに含めない', function () {
        // Arrange
        trcSnapshot('2026-09-01 12:00:00', [
            ['code' => '1234', 'market' => 'jp', 'accounts' => ['specific' => 999]],
        ]);
        $latest = trcSnapshot('2026-09-15 12:00:00', [
            ['code' => '1234', 'market' => 'jp', 'accounts' => ['specific' => 100]],
        ]);

        // Act: the 9/20 buy happens after the latest snapshot
        $result = trcImport([trcJp(), trcJp(['約定日' => '2026/9/20', '受渡日' => '2026/9/24', '数量［株］' => '50'])]);

        // Assert
        $item = trcItem('1234', 'jp', 'specific');
        expect($item->snapshot_id)->toBe($latest->id);
        expect($item->status)->toBe('matched');
        expect((float) $item->history_quantity)->toBe(100.0);
        expect($result->reconciliation->snapshotId)->toBe($latest->id);
    });

    test('国内株は、保有CSVの取込が約定日の15:30（東京）より前ならその日の約定を含めず、15:30以降なら含める', function () {
        // Arrange: 9/29 buy 100, 9/30 buy 50
        $jp = [trcJp(['約定日' => '2026/9/29', '受渡日' => '2026/10/1']), trcJp(['約定日' => '2026/9/30', '受渡日' => '2026/10/2', '数量［株］' => '50'])];
        trcSnapshot('2026-09-30 05:00:00', [['code' => '1234', 'market' => 'jp', 'accounts' => ['specific' => 100]]]); // 14:00 JST

        // Act 1: before the close
        $before = trcImport($jp);

        // Assert 1
        $item = TradeReconciliationItem::where('trade_import_batch_id', $before->batchId)->sole();
        expect($item->status)->toBe('matched');
        expect((float) $item->history_quantity)->toBe(100.0);

        // Act 2: a later snapshot taken after the close
        trcSnapshot('2026-09-30 07:00:00', [['code' => '1234', 'market' => 'jp', 'accounts' => ['specific' => 150]]]); // 16:00 JST
        $after = trcImport($jp);

        // Assert 2
        $item = TradeReconciliationItem::where('trade_import_batch_id', $after->batchId)->sole();
        expect($item->status)->toBe('matched');
        expect((float) $item->history_quantity)->toBe(150.0);
    });

    test('米国株は、保有CSVの取込が約定日の16:00（ニューヨーク）より前ならその日の約定を含めず、日本時間の翌朝でも以降なら含める', function () {
        // Arrange: 9/30 buy 10, 10/1 buy 5
        $us = [trcUs(['約定日' => '2026/9/30', '受渡日' => '2026/10/2']), trcUs(['約定日' => '2026/10/1', '受渡日' => '2026/10/3', '数量［株］' => '5'])];
        trcSnapshot('2026-10-01 19:00:00', [['code' => 'TEST', 'market' => 'us', 'accounts' => ['specific' => 10]]]); // 15:00 ET = 10/2 04:00 JST

        // Act 1: before the New York close
        $before = trcImport([], $us);

        // Assert 1
        $item = TradeReconciliationItem::where('trade_import_batch_id', $before->batchId)->sole();
        expect($item->status)->toBe('matched');
        expect((float) $item->history_quantity)->toBe(10.0);

        // Act 2: after the New York close
        trcSnapshot('2026-10-01 21:00:00', [['code' => 'TEST', 'market' => 'us', 'accounts' => ['specific' => 15]]]); // 17:00 ET
        $after = trcImport([], $us);

        // Assert 2
        $item = TradeReconciliationItem::where('trade_import_batch_id', $after->batchId)->sole();
        expect($item->status)->toBe('matched');
        expect((float) $item->history_quantity)->toBe(15.0);
    });

    test('米国株の取引終了の判定は冬時間（EST）でも正しく、16:00 ESTは21:00 UTCになる', function () {
        // Arrange: 12/1 buy 10 (EST: close = 21:00 UTC)
        $us = [trcUs(['約定日' => '2026/12/1', '受渡日' => '2026/12/3'])];
        trcSnapshot('2026-12-01 20:30:00', [['code' => 'TEST', 'market' => 'us', 'accounts' => ['specific' => 5]]]); // 15:30 EST: before the close

        // Act 1
        $before = trcImport([], $us);

        // Assert 1: the 12/1 buy is not counted yet (a summer-time rule would count it at 20:30 UTC)
        $item = TradeReconciliationItem::where('trade_import_batch_id', $before->batchId)->sole();
        expect($item->status)->toBe('snapshot_only');
        expect($item->history_quantity)->toBeNull();

        // Act 2
        trcSnapshot('2026-12-01 21:30:00', [['code' => 'TEST', 'market' => 'us', 'accounts' => ['specific' => 10]]]); // 16:30 EST
        $after = trcImport([], $us);

        // Assert 2
        $item = TradeReconciliationItem::where('trade_import_batch_id', $after->batchId)->sole();
        expect($item->status)->toBe('matched');
    });

    test('最新の履歴から消えて確認待ちになった約定は、積み上げに含めない', function () {
        // Arrange
        trcSnapshot('2026-10-03 12:00:00', [
            ['code' => '1234', 'market' => 'jp', 'accounts' => ['specific' => 100]],
        ]);
        trcImport([trcJp(), trcJp(['約定日' => '2026/9/2', '受渡日' => '2026/9/4', '数量［株］' => '50'])]);

        // Act: the 9/2 buy vanished from the latest full history
        $result = trcImport([trcJp()]);

        // Assert
        $item = TradeReconciliationItem::where('trade_import_batch_id', $result->batchId)->sole();
        expect($item->status)->toBe('matched');
        expect((float) $item->history_quantity)->toBe(100.0);
    });

    test('投資信託は照合の対象外', function () {
        // Arrange
        trcSnapshot('2026-10-03 12:00:00', [
            ['code' => 'テスト・インデックス・ファンド', 'market' => 'mutual_fund', 'type' => 'mutual_fund', 'accounts' => ['nisa_tsumitate' => 1000]],
        ]);

        // Act
        $result = trcImport([]);

        // Assert
        expect(TradeReconciliationItem::count())->toBe(0);
        expect($result->reconciliation->counts)->toBe(['matched' => 0, 'snapshot_only' => 0, 'history_only' => 0, 'needs_review' => 0]);
    });

    test('保有スナップショットがまだない場合は、取込は成功し、照合は行わない', function () {
        // Act
        $result = trcImport([trcJp()]);

        // Assert
        expect($result->success)->toBeTrue();
        expect($result->reconciliation)->toBeNull();
        expect(TradeReconciliationItem::count())->toBe(0);
    });

    test('取込のたびに照合結果が追記され、前回の照合結果は残る', function () {
        // Arrange
        trcSnapshot('2026-10-03 12:00:00', [
            ['code' => '1234', 'market' => 'jp', 'accounts' => ['specific' => 100]],
        ]);
        $first = trcImport([trcJp()]);

        // Act
        $second = trcImport([trcJp()]);

        // Assert
        expect(TradeReconciliationItem::where('trade_import_batch_id', $first->batchId)->count())->toBe(1);
        expect(TradeReconciliationItem::where('trade_import_batch_id', $second->batchId)->count())->toBe(1);
    });
});
