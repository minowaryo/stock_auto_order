<?php

namespace Tests\Unit\Services\Import;

use App\Exceptions\Import\CsvStructureException;
use App\Services\Import\TradeHistoryCsvParser;

/*
|--------------------------------------------------------------------------
| TradeHistoryCsvParser — UC-017 売買履歴CSVの読み取り — Red phase
| (CHG-0033 Cycle 2)
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md UC-017 基本フロー2〜3・異常時
|   - docs/adr/ADR-0027-trade-history-storage.md D1（content_hash＋出現回数）
|   - docs/architecture/data-model.md `trade_executions`
|   - docs/product/trade-decision-effect-proposal.md 8章（2026-10-03 の実ファイル
|     の実測）。値の書式は実ファイルに合わせた架空データ。
|
| Contract assumed here (flag at Gate 4 if a different shape is preferred):
|   - App\Services\Import\TradeHistoryCsvParser (pure, no DB) with
|       parseJp(string $utf8Content): ParsedTradeHistory
|       parseUs(string $utf8Content): ParsedTradeHistory
|     The caller decodes CP932 to UTF-8 first (same as the other parsers).
|   - ParsedTradeHistory { rows: list<ParsedTradeRow>, errorCount: int }
|     (App\Services\Import\Support). Row-level errors (bad date, unknown
|     売買区分, unknown account label) skip only that row and count it; a
|     missing required column, or the other market's file, throws
|     App\Exceptions\Import\CsvStructureException.
|   - ParsedTradeRow (readonly): market jp|us, symbolCode, symbolName,
|     tradeDate (Y-m-d), settlementDate (Y-m-d|null), accountType (same enum
|     values as holding_snapshot_accounts), kind (buy|sell|transfer_in|
|     transfer_out|tsumitate|split_in), isRoutine, quantity, unitPrice|null,
|     priceCurrency jpy|usd, settlementCurrency jpy|usd|null,
|     settlementAmountJpy|null, settlementAmountUsd|null, fxRate|null,
|     feeAmount|null, contentHash (64 hex), occurrenceIndex (1-based),
|     sourceRow (column => original string), lineNumber (1-based, header = 1).
|   - Dates arrive un-padded ("2022/7/5"); "-" means no value (null, never 0).
|   - isRoutine is true ONLY for the US 取引区分「積立」. JP 「現物(単元未満)」
|     is not routine (UC-018 決定5).
|   - contentHash is over the normalized business columns (trimmed text,
|     numbers without separators/trailing zeros, Y-m-d dates, market) and
|     never over file name or line number. A byte-identical row repeated in
|     the same file gets the same contentHash and occurrenceIndex 1, 2, 3…
|
| Pure parsing Unit Test (no DB/HTTP); plain Pest like JpStockCsvParserTest.
| Expected Red: App\Services\Import\TradeHistoryCsvParser does not exist.
|
*/

const TH_JP_HEADER = '約定日,受渡日,銘柄コード,銘柄名,市場名称,口座区分,取引区分,売買区分,信用区分,弁済期限,数量［株］,単価［円］,手数料［円］,税金等［円］,諸費用［円］,税区分,受渡金額［円］,建約定日,建単価［円］,建手数料［円］,建手数料消費税［円］,金利（支払）〔円〕,金利（受取）〔円〕,逆日歩／特別空売り料（支払）〔円〕,逆日歩（受取）〔円〕,貸株料,事務管理費〔円〕（税抜）,名義書換料〔円〕（税抜）';

const TH_US_HEADER = '約定日,受渡日,ティッカー,銘柄名,口座,取引区分,売買区分,信用区分,弁済期限,決済通貨,数量［株］,単価［USドル］,約定代金［USドル］,為替レート,手数料［USドル］,税金［USドル］,受渡金額［USドル］,受渡金額［円］';

/**
 * @param  array<string, string>  $over  column name => value overrides
 */
function thJpLine(array $over = []): string
{
    $row = array_merge([
        '約定日' => '2022/7/5', '受渡日' => '2022/7/7', '銘柄コード' => '1234', '銘柄名' => 'テスト工業',
        '市場名称' => '東証', '口座区分' => '特定', '取引区分' => '現物', '売買区分' => '買付',
        '信用区分' => '-', '弁済期限' => '-', '数量［株］' => '100', '単価［円］' => '1,234.5',
        '手数料［円］' => '0', '税金等［円］' => '0', '諸費用［円］' => '0', '税区分' => '-',
        '受渡金額［円］' => '123,450',
        '建約定日' => '-', '建単価［円］' => '0.0', '建手数料［円］' => '0', '建手数料消費税［円］' => '0',
        '金利（支払）〔円〕' => '0', '金利（受取）〔円〕' => '0', '逆日歩／特別空売り料（支払）〔円〕' => '0',
        '逆日歩（受取）〔円〕' => '0', '貸株料' => '0', '事務管理費〔円〕（税抜）' => '0', '名義書換料〔円〕（税抜）' => '0',
    ], $over);

    return thCsvLine(array_values($row));
}

/**
 * @param  array<string, string>  $over
 */
function thUsLine(array $over = []): string
{
    $row = array_merge([
        '約定日' => '2024/8/14', '受渡日' => '2024/8/16', 'ティッカー' => 'TEST', '銘柄名' => 'Test Corp',
        '口座' => '特定', '取引区分' => '現物', '売買区分' => '買付', '信用区分' => '-', '弁済期限' => '-',
        '決済通貨' => '円', '数量［株］' => '2', '単価［USドル］' => '123.4567', '約定代金［USドル］' => '246.91',
        '為替レート' => '150.123', '手数料［USドル］' => '-', '税金［USドル］' => '-',
        '受渡金額［USドル］' => '-', '受渡金額［円］' => '37,065.45',
    ], $over);

    return thCsvLine(array_values($row));
}

/**
 * Cells with a comma are quoted, like the real export.
 *
 * @param  array<int, string>  $cells
 */
function thCsvLine(array $cells): string
{
    return implode(',', array_map(fn (string $c) => str_contains($c, ',') ? '"'.$c.'"' : $c, $cells));
}

/**
 * @param  array<int, string>  $lines
 */
function thFile(string $header, array $lines): string
{
    return implode("\n", [$header, ...$lines])."\n";
}

describe('UC-017 国内株の売買履歴CSV（parseJp）', function () {
    test('買付1行を、日付・口座・数量・単価・受渡金額つきの売買明細として読み取る（日付は桁揃えなし、数値は桁区切りなし）', function () {
        // Arrange
        $csv = thFile(TH_JP_HEADER, [thJpLine()]);

        // Act
        $parsed = (new TradeHistoryCsvParser)->parseJp($csv);

        // Assert
        expect($parsed->errorCount)->toBe(0);
        expect($parsed->rows)->toHaveCount(1);
        $row = $parsed->rows[0];
        expect($row->market)->toBe('jp');
        expect($row->symbolCode)->toBe('1234');
        expect($row->symbolName)->toBe('テスト工業');
        expect($row->tradeDate)->toBe('2022-07-05');
        expect($row->settlementDate)->toBe('2022-07-07');
        expect($row->accountType)->toBe('specific');
        expect($row->kind)->toBe('buy');
        expect($row->isRoutine)->toBeFalse();
        expect($row->quantity)->toBe(100.0);
        expect($row->unitPrice)->toBe(1234.5);
        expect($row->priceCurrency)->toBe('jpy');
        expect($row->settlementCurrency)->toBe('jpy');
        expect($row->settlementAmountJpy)->toBe(123450.0);
        expect($row->settlementAmountUsd)->toBeNull();
        expect($row->feeAmount)->toBe(0.0);
        expect($row->lineNumber)->toBe(2);
        expect($row->sourceRow['約定日'])->toBe('2022/7/5');
        expect($row->sourceRow['単価［円］'])->toBe('1,234.5');
    });

    test('売付・一般口座・NISA成長投資枠の口座区分を、アプリの口座区分に対応づけて読み取る', function () {
        // Arrange
        $csv = thFile(TH_JP_HEADER, [
            thJpLine(['売買区分' => '売付', '口座区分' => '一般']),
            thJpLine(['売買区分' => '買付', '口座区分' => 'NISA成長投資枠']),
        ]);

        // Act
        $rows = (new TradeHistoryCsvParser)->parseJp($csv)->rows;

        // Assert
        expect($rows[0]->kind)->toBe('sell');
        expect($rows[0]->accountType)->toBe('general');
        expect($rows[1]->accountType)->toBe('nisa_growth');
    });

    test('入庫・出庫は、取引区分が空・受渡金額が「-」でも読み取り、受渡金額と決済通貨は不明（null）になる', function () {
        // Arrange
        $csv = thFile(TH_JP_HEADER, [
            thJpLine(['取引区分' => '', '売買区分' => '入庫', '受渡金額［円］' => '-']),
            thJpLine(['取引区分' => '', '売買区分' => '出庫', '受渡金額［円］' => '-']),
        ]);

        // Act
        $parsed = (new TradeHistoryCsvParser)->parseJp($csv);

        // Assert
        expect($parsed->errorCount)->toBe(0);
        expect($parsed->rows[0]->kind)->toBe('transfer_in');
        expect($parsed->rows[1]->kind)->toBe('transfer_out');
        expect($parsed->rows[0]->settlementAmountJpy)->toBeNull();
        expect($parsed->rows[0]->settlementCurrency)->toBeNull();
    });

    test('「現物(単元未満)」の買付は定型積立として扱わない', function () {
        // Arrange
        $csv = thFile(TH_JP_HEADER, [thJpLine(['取引区分' => '現物(単元未満)', '市場名称' => '市場外'])]);

        // Act
        $row = (new TradeHistoryCsvParser)->parseJp($csv)->rows[0];

        // Assert
        expect($row->kind)->toBe('buy');
        expect($row->isRoutine)->toBeFalse();
    });

    test('英字を含む銘柄コードをそのまま保持する', function () {
        // Arrange
        $csv = thFile(TH_JP_HEADER, [thJpLine(['銘柄コード' => '285A'])]);

        // Act
        $row = (new TradeHistoryCsvParser)->parseJp($csv)->rows[0];

        // Assert
        expect($row->symbolCode)->toBe('285A');
    });

    test('完全に同じ内容の行は消さず、同じ内容ハッシュで出現回数1・2・3の別行として読み取る', function () {
        // Arrange
        $same = thJpLine();
        $csv = thFile(TH_JP_HEADER, [$same, thJpLine(['数量［株］' => '200']), $same, $same]);

        // Act
        $rows = (new TradeHistoryCsvParser)->parseJp($csv)->rows;

        // Assert
        expect($rows)->toHaveCount(4);
        expect($rows[0]->contentHash)->toMatch('/^[0-9a-f]{64}$/');
        expect($rows[2]->contentHash)->toBe($rows[0]->contentHash);
        expect($rows[3]->contentHash)->toBe($rows[0]->contentHash);
        expect($rows[1]->contentHash)->not->toBe($rows[0]->contentHash);
        expect([$rows[0]->occurrenceIndex, $rows[2]->occurrenceIndex, $rows[3]->occurrenceIndex])->toBe([1, 2, 3]);
        expect($rows[1]->occurrenceIndex)->toBe(1);
    });

    test('内容ハッシュは書式の揺れ（日付の0埋め・数値の桁区切りや末尾の0）と行の位置に左右されず、数量が違えば変わる', function () {
        // Arrange
        $base = thFile(TH_JP_HEADER, [thJpLine()]);
        $jitter = thFile(TH_JP_HEADER, [
            thJpLine(['約定日' => '2022/07/05', '受渡日' => '2022/07/07', '単価［円］' => '1234.50', '受渡金額［円］' => '123450']),
        ]);
        $shifted = thFile(TH_JP_HEADER, [thJpLine(['銘柄コード' => '9999']), thJpLine()]);
        $otherQuantity = thFile(TH_JP_HEADER, [thJpLine(['数量［株］' => '101'])]);
        $parser = new TradeHistoryCsvParser;

        // Act
        $hash = $parser->parseJp($base)->rows[0]->contentHash;

        // Assert
        expect($parser->parseJp($jitter)->rows[0]->contentHash)->toBe($hash);
        expect($parser->parseJp($shifted)->rows[1]->contentHash)->toBe($hash);
        expect($parser->parseJp($otherQuantity)->rows[0]->contentHash)->not->toBe($hash);
    });

    test('日付が不正・売買区分が未知・口座区分が未知の行はその行だけ読み飛ばして件数に数え、他の行は読み取る', function () {
        // Arrange
        $csv = thFile(TH_JP_HEADER, [
            thJpLine(),
            thJpLine(['約定日' => '2022/13/40']),
            thJpLine(['売買区分' => '現引']),
            thJpLine(['口座区分' => '未知の口座']),
            thJpLine(['銘柄コード' => '5678']),
        ]);

        // Act
        $parsed = (new TradeHistoryCsvParser)->parseJp($csv);

        // Assert
        expect($parsed->errorCount)->toBe(3);
        expect(array_map(fn ($r) => $r->symbolCode, $parsed->rows))->toBe(['1234', '5678']);
    });

    test('必須の列が欠けたファイル、米国株のファイルを国内株として渡した場合は構造エラーにする', function () {
        // Arrange
        $missingColumn = thFile(str_replace('受渡金額［円］,', '', TH_JP_HEADER), []);
        $usFile = thFile(TH_US_HEADER, [thUsLine()]);
        $parser = new TradeHistoryCsvParser;

        // Act & Assert
        expect(fn () => $parser->parseJp($missingColumn))->toThrow(CsvStructureException::class);
        expect(fn () => $parser->parseJp($usFile))->toThrow(CsvStructureException::class);
    });

    test('ヘッダーだけで行がないファイルは、エラーにせず0件として読み取る', function () {
        // Act
        $parsed = (new TradeHistoryCsvParser)->parseJp(thFile(TH_JP_HEADER, []));

        // Assert
        expect($parsed->rows)->toBe([]);
        expect($parsed->errorCount)->toBe(0);
    });
});

describe('UC-017 米国株の売買履歴CSV（parseUs）', function () {
    test('円決済の買付を、ドル建ての単価・為替レート・円の受渡金額つきで読み取る（手数料の「-」は不明）', function () {
        // Arrange
        $csv = thFile(TH_US_HEADER, [thUsLine()]);

        // Act
        $parsed = (new TradeHistoryCsvParser)->parseUs($csv);

        // Assert
        expect($parsed->errorCount)->toBe(0);
        $row = $parsed->rows[0];
        expect($row->market)->toBe('us');
        expect($row->symbolCode)->toBe('TEST');
        expect($row->tradeDate)->toBe('2024-08-14');
        expect($row->kind)->toBe('buy');
        expect($row->accountType)->toBe('specific');
        expect($row->isRoutine)->toBeFalse();
        expect($row->priceCurrency)->toBe('usd');
        expect($row->unitPrice)->toBe(123.4567);
        expect($row->fxRate)->toBe(150.123);
        expect($row->settlementCurrency)->toBe('jpy');
        expect($row->settlementAmountJpy)->toBe(37065.45);
        expect($row->settlementAmountUsd)->toBeNull();
        expect($row->feeAmount)->toBeNull();
    });

    test('ドル決済（全角「ＵＳドル」）の買付は、決済通貨がドルで、ドルの受渡金額だけを持つ', function () {
        // Arrange
        $csv = thFile(TH_US_HEADER, [thUsLine(['決済通貨' => 'ＵＳドル', '受渡金額［USドル］' => '247.50', '受渡金額［円］' => '-'])]);

        // Act
        $row = (new TradeHistoryCsvParser)->parseUs($csv)->rows[0];

        // Assert
        expect($row->settlementCurrency)->toBe('usd');
        expect($row->settlementAmountUsd)->toBe(247.5);
        expect($row->settlementAmountJpy)->toBeNull();
    });

    test('取引区分「積立」（売買区分が空）は買い方向の定型積立として読み取る', function () {
        // Arrange
        $csv = thFile(TH_US_HEADER, [thUsLine(['取引区分' => '積立', '売買区分' => ''])]);

        // Act
        $row = (new TradeHistoryCsvParser)->parseUs($csv)->rows[0];

        // Assert
        expect($row->kind)->toBe('tsumitate');
        expect($row->isRoutine)->toBeTrue();
    });

    test('「入庫（分割）」は分割入庫として読み取り、単価・金額・決済通貨は不明（null）で、買付費用や定型にはしない', function () {
        // Arrange
        $csv = thFile(TH_US_HEADER, [thUsLine([
            '取引区分' => '入庫（分割）', '売買区分' => '', '決済通貨' => '-', '単価［USドル］' => '-',
            '約定代金［USドル］' => '-', '為替レート' => '-', '受渡金額［円］' => '-',
        ])]);

        // Act
        $row = (new TradeHistoryCsvParser)->parseUs($csv)->rows[0];

        // Assert
        expect($row->kind)->toBe('split_in');
        expect($row->isRoutine)->toBeFalse();
        expect($row->unitPrice)->toBeNull();
        expect($row->fxRate)->toBeNull();
        expect($row->settlementCurrency)->toBeNull();
        expect($row->settlementAmountJpy)->toBeNull();
    });

    test('売付とNISA成長投資枠を読み取り、同一内容の行は出現回数で別行にする', function () {
        // Arrange
        $same = thUsLine(['売買区分' => '売付', '口座' => 'NISA成長投資枠']);
        $csv = thFile(TH_US_HEADER, [$same, $same]);

        // Act
        $rows = (new TradeHistoryCsvParser)->parseUs($csv)->rows;

        // Assert
        expect($rows[0]->kind)->toBe('sell');
        expect($rows[0]->accountType)->toBe('nisa_growth');
        expect($rows[1]->contentHash)->toBe($rows[0]->contentHash);
        expect([$rows[0]->occurrenceIndex, $rows[1]->occurrenceIndex])->toBe([1, 2]);
    });

    test('国内株と米国株の内容ハッシュは市場を含むため、同じ文字列の行でも衝突しない', function () {
        // Arrange
        $parser = new TradeHistoryCsvParser;
        $jp = $parser->parseJp(thFile(TH_JP_HEADER, [thJpLine(['銘柄コード' => 'ABCD'])]))->rows[0];
        $us = $parser->parseUs(thFile(TH_US_HEADER, [thUsLine(['ティッカー' => 'ABCD'])]))->rows[0];

        // Assert
        expect($jp->contentHash)->not->toBe($us->contentHash);
    });

    test('国内株のファイルを米国株として渡した場合、必須の列が欠けた場合は構造エラーにする', function () {
        // Arrange
        $parser = new TradeHistoryCsvParser;

        // Act & Assert
        expect(fn () => $parser->parseUs(thFile(TH_JP_HEADER, [thJpLine()])))->toThrow(CsvStructureException::class);
        expect(fn () => $parser->parseUs(thFile(str_replace('為替レート,', '', TH_US_HEADER), [])))->toThrow(CsvStructureException::class);
    });
});
