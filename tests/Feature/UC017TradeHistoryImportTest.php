<?php

namespace Tests\Feature;

use App\Actions\Import\ImportTradeHistoryAction;
use App\Models\Holding;
use App\Models\TradeExecution;
use App\Models\TradeImportBatch;
use Illuminate\Http\UploadedFile;

/*
|--------------------------------------------------------------------------
| UC-017 売買履歴の取込（保存・再取込・消えた行） — Red phase
| (CHG-0033 Cycle 3)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-017 基本フロー1〜4・異常時・状態変更
|   - docs/adr/ADR-0027-trade-history-storage.md D1
|   - docs/architecture/data-model.md `trade_import_batches` / `trade_executions`
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Actions\Import\ImportTradeHistoryAction with
|       preview(UploadedFile $jp, UploadedFile $us): TradeHistoryImportSummary
|       execute(UploadedFile $jp, UploadedFile $us): TradeHistoryImportSummary
|     Both files are required and CP932-encoded (same as the holdings import).
|     The summary exposes: success, batchId (null for preview / failure),
|     totalRows, newRows, existingRows, missingRows, errorCount,
|     periodFrom, periodTo (Y-m-d|null), failureReason.
|   - preview() never writes to the DB (UC-017「プレビューは状態を変えない」).
|   - execute() saves inside one transaction:
|       * a trade_import_batches row (status completed, counts, period)
|       * new trade_executions rows keyed by (market, content_hash,
|         occurrence_index); existing keys only get last_seen_import_batch_id
|         and review_status = ok
|       * keys previously stored for that market but absent from this file →
|         review_status = missing_in_latest (never deleted)
|       * holdings are found or created by (symbol_code, market); a new
|         holding gets instrument_type = stock; an existing one is unchanged
|   - A CsvStructureException in either file → batch status failed with a
|     failure_reason, and nothing else is written.
|   - totalRows counts every data row (including skipped ones); errorCount
|     counts skipped rows. The error count is returned, not persisted.
|   - This cycle does NOT reconcile with the holdings CSV and does NOT add a
|     screen; those are later cycles.
|
| Expected Red: the Action, the models and the tables do not exist yet.
|
*/

const TIS_JP_HEADER = '約定日,受渡日,銘柄コード,銘柄名,市場名称,口座区分,取引区分,売買区分,信用区分,弁済期限,数量［株］,単価［円］,手数料［円］,税金等［円］,諸費用［円］,税区分,受渡金額［円］,建約定日,建単価［円］,建手数料［円］,建手数料消費税［円］,金利（支払）〔円〕,金利（受取）〔円〕,逆日歩／特別空売り料（支払）〔円〕,逆日歩（受取）〔円〕,貸株料,事務管理費〔円〕（税抜）,名義書換料〔円〕（税抜）';

const TIS_US_HEADER = '約定日,受渡日,ティッカー,銘柄名,口座,取引区分,売買区分,信用区分,弁済期限,決済通貨,数量［株］,単価［USドル］,約定代金［USドル］,為替レート,手数料［USドル］,税金［USドル］,受渡金額［USドル］,受渡金額［円］';

/**
 * @param  array<string, string>  $over
 */
function tisJp(array $over = []): string
{
    $row = array_merge([
        '約定日' => '2022/7/5', '受渡日' => '2022/7/7', '銘柄コード' => '1234', '銘柄名' => 'テスト工業',
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
function tisUs(array $over = []): string
{
    $row = array_merge([
        '約定日' => '2024/8/14', '受渡日' => '2024/8/16', 'ティッカー' => 'TEST', '銘柄名' => 'Test Corp',
        '口座' => '特定', '取引区分' => '現物', '売買区分' => '買付', '信用区分' => '-', '弁済期限' => '-',
        '決済通貨' => '円', '数量［株］' => '2', '単価［USドル］' => '100.0000', '約定代金［USドル］' => '200.00',
        '為替レート' => '150.000', '手数料［USドル］' => '-', '税金［USドル］' => '-',
        '受渡金額［USドル］' => '-', '受渡金額［円］' => '30,000.00',
    ], $over);

    return implode(',', array_map(fn (string $c) => str_contains($c, ',') ? '"'.$c.'"' : $c, array_values($row)));
}

/**
 * CP932-encoded upload, like the real 楽天証券 export.
 *
 * @param  array<int, string>  $lines
 */
function tisFile(string $name, string $header, array $lines): UploadedFile
{
    $utf8 = implode("\n", [$header, ...$lines])."\n";

    return UploadedFile::fake()->createWithContent($name, mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8'));
}

/**
 * @param  array<int, string>  $jpLines
 * @param  array<int, string>  $usLines
 * @return array{0: UploadedFile, 1: UploadedFile}
 */
function tisFiles(array $jpLines, array $usLines): array
{
    return [
        tisFile('tradehistory(JP).csv', TIS_JP_HEADER, $jpLines),
        tisFile('tradehistory(US).csv', TIS_US_HEADER, $usLines),
    ];
}

function tisImport(array $jpLines, array $usLines): object
{
    return app(ImportTradeHistoryAction::class)->execute(...tisFiles($jpLines, $usLines));
}

describe('UC-017 売買履歴の取込: 初回取込', function () {
    test('国内株・米国株の全期間履歴を取り込むと、取込記録と売買明細が保存され、銘柄マスタが作られる', function () {
        // Arrange
        $jp = [tisJp(), tisJp(['約定日' => '2023/1/10', '受渡日' => '2023/1/12', '売買区分' => '売付', '銘柄コード' => '5678', '銘柄名' => '売却済み商事'])];
        $us = [tisUs()];

        // Act
        $result = tisImport($jp, $us);

        // Assert: summary
        expect($result->success)->toBeTrue();
        expect([$result->totalRows, $result->newRows, $result->existingRows, $result->missingRows, $result->errorCount])->toBe([3, 3, 0, 0, 0]);
        expect([$result->periodFrom, $result->periodTo])->toBe(['2022-07-05', '2024-08-14']);

        // Assert: batch
        $batch = TradeImportBatch::sole();
        expect($batch->id)->toBe($result->batchId);
        expect($batch->status)->toBe('completed');
        expect($batch->jp_filename)->toBe('tradehistory(JP).csv');
        expect($batch->us_filename)->toBe('tradehistory(US).csv');
        expect([$batch->total_rows, $batch->new_rows, $batch->existing_rows, $batch->missing_rows])->toBe([3, 3, 0, 0]);
        expect($batch->period_from->toDateString())->toBe('2022-07-05');
        expect($batch->period_to->toDateString())->toBe('2024-08-14');
        expect($batch->imported_at)->not->toBeNull();

        // Assert: executions
        expect(TradeExecution::count())->toBe(3);
        $sold = TradeExecution::where('kind', 'sell')->sole();
        expect($sold->market)->toBe('jp');
        expect($sold->trade_date->toDateString())->toBe('2023-01-10');
        expect($sold->account_type)->toBe('specific');
        expect((float) $sold->quantity)->toBe(100.0);
        expect((float) $sold->settlement_amount_jpy)->toBe(100000.0);
        expect($sold->occurrence_index)->toBe(1);
        expect($sold->content_hash)->toMatch('/^[0-9a-f]{64}$/');
        expect($sold->first_import_batch_id)->toBe($batch->id);
        expect($sold->last_seen_import_batch_id)->toBe($batch->id);
        expect($sold->review_status)->toBe('ok');
        expect($sold->source_row['銘柄コード'])->toBe('5678');

        $usRow = TradeExecution::where('market', 'us')->sole();
        expect($usRow->price_currency)->toBe('usd');
        expect((float) $usRow->fx_rate)->toBe(150.0);

        // Assert: holdings (sold-out stocks get a holdings row too)
        $soldHolding = Holding::where('symbol_code', '5678')->where('market', 'jp')->sole();
        expect($soldHolding->instrument_type)->toBe('stock');
        expect($soldHolding->symbol_name)->toBe('売却済み商事');
        expect($sold->holding_id)->toBe($soldHolding->id);
        expect(Holding::where('symbol_code', 'TEST')->where('market', 'us')->exists())->toBeTrue();
    });

    test('既に銘柄マスタにある銘柄は、その行に紐づけ、銘柄種別や名前を変えない', function () {
        // Arrange
        $existing = Holding::create([
            'symbol_code' => '1234', 'market' => 'jp', 'instrument_type' => 'etf',
            'symbol_name' => '既存の名前', 'sector_classification_id' => null, 'first_detected_at' => now(),
        ]);

        // Act
        tisImport([tisJp()], []);

        // Assert
        expect(Holding::where('symbol_code', '1234')->where('market', 'jp')->count())->toBe(1);
        expect(TradeExecution::sole()->holding_id)->toBe($existing->id);
        $existing->refresh();
        expect($existing->instrument_type)->toBe('etf');
        expect($existing->symbol_name)->toBe('既存の名前');
    });

    test('完全に同じ内容の行は2行として保存される', function () {
        // Act
        tisImport([tisJp(), tisJp()], []);

        // Assert
        $rows = TradeExecution::orderBy('occurrence_index')->get();
        expect($rows)->toHaveCount(2);
        expect($rows[0]->content_hash)->toBe($rows[1]->content_hash);
        expect([$rows[0]->occurrence_index, $rows[1]->occurrence_index])->toBe([1, 2]);
    });

    test('読み飛ばした行は件数に数え、他の行は保存する', function () {
        // Act
        $result = tisImport([tisJp(), tisJp(['約定日' => '2022/13/40'])], [tisUs()]);

        // Assert
        expect($result->success)->toBeTrue();
        expect([$result->totalRows, $result->newRows, $result->errorCount])->toBe([3, 2, 1]);
        expect(TradeExecution::count())->toBe(2);
    });
});

describe('UC-017 売買履歴の取込: 全期間履歴の再取込', function () {
    test('同じ全期間履歴を再び取り込んでも行は増えず、最後に確認した取込だけが更新される', function () {
        // Arrange
        $jp = [tisJp(), tisJp(), tisJp(['銘柄コード' => '5678'])];
        $us = [tisUs()];
        $first = tisImport($jp, $us);

        // Act
        $second = tisImport($jp, $us);

        // Assert
        expect(TradeExecution::count())->toBe(4);
        expect([$second->newRows, $second->existingRows, $second->missingRows])->toBe([0, 4, 0]);
        expect(TradeExecution::where('first_import_batch_id', $first->batchId)->count())->toBe(4);
        expect(TradeExecution::where('last_seen_import_batch_id', $second->batchId)->count())->toBe(4);
        expect(TradeImportBatch::count())->toBe(2);
    });

    test('新しい約定が増えた履歴を取り込むと、その行だけが追加される', function () {
        // Arrange
        tisImport([tisJp()], [tisUs()]);

        // Act
        $result = tisImport([tisJp(), tisJp(['約定日' => '2026/10/2', '受渡日' => '2026/10/6'])], [tisUs()]);

        // Assert
        expect([$result->newRows, $result->existingRows, $result->missingRows])->toBe([1, 2, 0]);
        expect(TradeExecution::count())->toBe(3);
        expect(TradeExecution::where('trade_date', '2026-10-02')->sole()->first_import_batch_id)->toBe($result->batchId);
    });
});

describe('UC-017 売買履歴の取込: 前回あった行が消えた場合', function () {
    test('前回あった行が今回の全期間履歴にない場合は、削除せず確認待ちにし、件数を返す', function () {
        // Arrange
        $first = tisImport([tisJp(), tisJp(['銘柄コード' => '5678'])], [tisUs()]);
        $vanishing = TradeExecution::whereHas('holding', fn ($q) => $q->where('symbol_code', '5678'))->sole();

        // Act
        $second = tisImport([tisJp()], [tisUs()]);

        // Assert
        expect($second->missingRows)->toBe(1);
        expect(TradeImportBatch::find($second->batchId)->missing_rows)->toBe(1);
        expect(TradeExecution::count())->toBe(3);
        $vanishing->refresh();
        expect($vanishing->review_status)->toBe('missing_in_latest');
        expect($vanishing->last_seen_import_batch_id)->toBe($first->batchId);
        // The other market is not affected
        expect(TradeExecution::where('market', 'us')->sole()->review_status)->toBe('ok');
    });

    test('確認待ちになった行が次の取込で再び現れたら、確認待ちを解除する', function () {
        // Arrange
        tisImport([tisJp(), tisJp(['銘柄コード' => '5678'])], []);
        tisImport([tisJp()], []);

        // Act
        $third = tisImport([tisJp(), tisJp(['銘柄コード' => '5678'])], []);

        // Assert
        expect($third->missingRows)->toBe(0);
        expect(TradeExecution::where('review_status', 'missing_in_latest')->count())->toBe(0);
        expect(TradeExecution::count())->toBe(2);
    });

    test('同じ内容の行が3回から2回に減ったら、3番目の出現だけが確認待ちになる', function () {
        // Arrange
        tisImport([tisJp(), tisJp(), tisJp()], []);

        // Act
        $result = tisImport([tisJp(), tisJp()], []);

        // Assert
        expect($result->missingRows)->toBe(1);
        expect(TradeExecution::where('review_status', 'missing_in_latest')->sole()->occurrence_index)->toBe(3);
        expect(TradeExecution::where('review_status', 'ok')->pluck('occurrence_index')->sort()->values()->all())->toBe([1, 2]);
    });
});

describe('UC-017 売買履歴の取込: 異常時とプレビュー', function () {
    test('どちらかのファイルの形式が違う場合は取込を失敗として記録し、売買明細も銘柄マスタも保存しない', function () {
        // Arrange
        $jp = tisFile('tradehistory(JP).csv', TIS_JP_HEADER, [tisJp()]);
        $brokenUs = tisFile('tradehistory(US).csv', '列A,列B', ['1,2']);

        // Act
        $result = app(ImportTradeHistoryAction::class)->execute($jp, $brokenUs);

        // Assert
        expect($result->success)->toBeFalse();
        expect($result->failureReason)->not->toBeEmpty();
        $batch = TradeImportBatch::sole();
        expect($batch->status)->toBe('failed');
        expect($batch->failure_reason)->not->toBeNull();
        expect(TradeExecution::count())->toBe(0);
        expect(Holding::count())->toBe(0);
    });

    test('プレビューは新規・既存・消える行の件数と対象期間を返し、データベースを一切変えない', function () {
        // Arrange
        tisImport([tisJp(), tisJp(['銘柄コード' => '5678'])], [tisUs()]);
        $executionsBefore = TradeExecution::orderBy('id')->get()->toArray();
        $holdingsBefore = Holding::count();
        [$jp, $us] = tisFiles([tisJp(), tisJp(['約定日' => '2026/10/2', '受渡日' => '2026/10/6', '銘柄コード' => '9999'])], [tisUs()]);

        // Act
        $preview = app(ImportTradeHistoryAction::class)->preview($jp, $us);

        // Assert: counts
        expect($preview->success)->toBeTrue();
        expect($preview->batchId)->toBeNull();
        expect([$preview->totalRows, $preview->newRows, $preview->existingRows, $preview->missingRows])->toBe([3, 1, 2, 1]);
        expect([$preview->periodFrom, $preview->periodTo])->toBe(['2022-07-05', '2026-10-02']);

        // Assert: nothing changed
        expect(TradeImportBatch::count())->toBe(1);
        expect(TradeExecution::orderBy('id')->get()->toArray())->toBe($executionsBefore);
        expect(Holding::count())->toBe($holdingsBefore);
    });
});
