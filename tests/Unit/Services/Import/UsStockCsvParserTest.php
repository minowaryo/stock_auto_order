<?php

namespace Tests\Unit\Services\Import;

use App\Exceptions\Import\CsvStructureException;
use App\Services\Import\UsStockCsvParser;

/*
|--------------------------------------------------------------------------
| UsStockCsvParser — account type (口座区分) Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0002-nisa-account-type-tracking.md
|   - docs/architecture/data-model.md (`holding_snapshot_accounts`)
|   - C:\Users\minow\.claude\plans\stock_auto_order-nisa-account-implementation-phase.md
|     ("今回のセッションで実際のユーザーCSV...を取り込んだ際、...US株CSVに
|     ■特定口座/■一般口座/■NISA成長投資枠のセクション...が実在することを
|     確認済み")
|
| Same Red-state reasoning as tests/Unit/Services/Import/JpStockCsvParserTest.php:
| ParsedCsvRow has no `accountType` property yet, and UsStockCsvParser
| discards the "■特定口座" / "■一般口座" / "■NISA成長投資枠" section-heading
| text entirely.
|
| This is a pure parsing Unit Test (no DB/HTTP dependency), so — matching
| tests/Unit/Services/Analysis/FundamentalIndicatorMapperTest.php — it is
| not bound to Tests\TestCase (Unit/ tests are plain Pest per tests/Pest.php).
*/

function usAccCsvHeader(): string
{
    return 'ティッカー,銘柄名,保有数量［株］,平均取得価額［USドル］,現在値［USドル］';
}

/**
 * Build a minimal US stock CSV with a reference FX rate line and one or
 * more account sections, trimmed down to only the columns
 * UsStockCsvParser actually reads.
 *
 * @param  array<int, array{label: string, rows: array<int, array{ticker: string, name: string, qty: string, avg: string, price: string}>}>  $sections
 */
function usAccCsvBuild(array $sections, string $fxRate = '150.00'): string
{
    $lines = [
        '参考為替レート(米ドル),'.$fxRate,
        '',
    ];

    foreach ($sections as $section) {
        $lines[] = $section['label'];
        $lines[] = '';
        $lines[] = usAccCsvHeader();

        foreach ($section['rows'] as $row) {
            $lines[] = implode(',', [$row['ticker'], $row['name'], $row['qty'], $row['avg'], $row['price']]);
        }

        $lines[] = '';
    }

    return implode("\r\n", $lines)."\r\n";
}

test('■特定口座セクションの保有銘柄はaccountTypeがspecificになる', function () {
    $csv = usAccCsvBuild([
        ['label' => '■特定口座', 'rows' => [
            ['ticker' => 'AAPL', 'name' => 'アップル', 'qty' => '10', 'avg' => '100', 'price' => '150'],
        ]],
    ]);

    $parsed = (new UsStockCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->accountType)->toBe('specific');
});

test('■一般口座セクションの保有銘柄はaccountTypeがgeneralになる', function () {
    $csv = usAccCsvBuild([
        ['label' => '■一般口座', 'rows' => [
            ['ticker' => 'AAPL', 'name' => 'アップル', 'qty' => '5', 'avg' => '90', 'price' => '150'],
        ]],
    ]);

    $parsed = (new UsStockCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->accountType)->toBe('general');
});

test('■NISA成長投資枠セクションの保有銘柄はaccountTypeがnisa_growthになる', function () {
    $csv = usAccCsvBuild([
        ['label' => '■NISA成長投資枠', 'rows' => [
            ['ticker' => 'AAPL', 'name' => 'アップル', 'qty' => '3', 'avg' => '120', 'price' => '150'],
        ]],
    ]);

    $parsed = (new UsStockCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->accountType)->toBe('nisa_growth');
});

test('特定口座・一般口座・NISA成長投資枠の3セクションにまたがる場合、各行が自身のセクションのaccountTypeを持つ', function () {
    $csv = usAccCsvBuild([
        ['label' => '■特定口座', 'rows' => [
            ['ticker' => 'AAPL', 'name' => 'アップル', 'qty' => '10', 'avg' => '100', 'price' => '150'],
        ]],
        ['label' => '■一般口座', 'rows' => [
            ['ticker' => 'AAPL', 'name' => 'アップル', 'qty' => '5', 'avg' => '90', 'price' => '150'],
        ]],
        ['label' => '■NISA成長投資枠', 'rows' => [
            ['ticker' => 'AAPL', 'name' => 'アップル', 'qty' => '3', 'avg' => '120', 'price' => '150'],
        ]],
    ]);

    $parsed = (new UsStockCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(3);
    expect($parsed->rows[0]->accountType)->toBe('specific');
    expect($parsed->rows[1]->accountType)->toBe('general');
    expect($parsed->rows[2]->accountType)->toBe('nisa_growth');
});

test('未知の口座区分ラベルの見出し行がある場合はCsvStructureExceptionが投げられ取込全体が失敗する', function () {
    $csv = usAccCsvBuild([
        ['label' => '■謎の口座区分', 'rows' => [
            ['ticker' => 'ZZZZ', 'name' => '謎銘柄', 'qty' => '1', 'avg' => '10', 'price' => '10'],
        ]],
    ]);

    expect(fn () => (new UsStockCsvParser)->parse($csv))
        ->toThrow(CsvStructureException::class);
});
