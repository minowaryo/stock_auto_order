<?php

namespace Tests\Unit\Services\Import;

use App\Exceptions\Import\CsvStructureException;
use App\Services\Import\JpStockCsvParser;

/*
|--------------------------------------------------------------------------
| JpStockCsvParser — account type (口座区分) Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0002-nisa-account-type-tracking.md
|   - docs/architecture/data-model.md (`holding_snapshot_accounts`)
|   - C:\Users\minow\.claude\plans\stock_auto_order-nisa-account-implementation-phase.md
|
| Today, App\Services\Import\Support\ParsedCsvRow has no `accountType`
| property at all, and JpStockCsvParser discards the "■特定口座" /
| "■NISA成長投資枠" section-heading text entirely (it only uses the leading
| "■" to toggle $inDataSection off, per app/Services/Import/
| JpStockCsvParser.php lines 64-68 as of this writing). Every test below is
| expected to fail:
|   - the "known label" tests fail because $row->accountType does not exist
|     (reading an undefined property returns null, so it never equals the
|     expected enum string)
|   - the "unknown label" test fails because the parser currently treats
|     every account section identically regardless of its heading text, so
|     rows under an unrecognized label are parsed normally today (no error
|     is raised, nothing is skipped)
|
| This is a pure parsing Unit Test (no DB/HTTP dependency), so — matching
| tests/Unit/Services/Analysis/FundamentalIndicatorMapperTest.php — it is
| not bound to Tests\TestCase (Unit/ tests are plain Pest per tests/Pest.php).
*/

function jpAccCsvHeader(): string
{
    return '銘柄コード,銘柄名,保有数量［株］,平均取得価額［円］,現在値［円］';
}

/**
 * Build a minimal JP stock CSV with one or more account sections, each
 * repeating its own header row before its data rows — matching the real
 * 楽天証券 export structure (docs/product/use-cases.md UC-001業務ルール)
 * and the fuller fixture builder already used in
 * tests/Feature/UC001CsvImportTest.php (ucFrom001TestJpStockCsv), but
 * trimmed down to only the columns this parser actually reads.
 *
 * @param  array<int, array{label: string, rows: array<int, array{code: string, name: string, qty: string, avg: string, price: string}>}>  $sections
 */
function jpAccCsvBuild(array $sections): string
{
    $lines = [];

    foreach ($sections as $section) {
        $lines[] = $section['label'];
        $lines[] = '';
        $lines[] = jpAccCsvHeader();

        foreach ($section['rows'] as $row) {
            $lines[] = implode(',', [$row['code'], $row['name'], $row['qty'], $row['avg'], $row['price']]);
        }

        $lines[] = '';
    }

    return implode("\r\n", $lines)."\r\n";
}

test('■特定口座セクションの保有銘柄はaccountTypeがspecificになる', function () {
    $csv = jpAccCsvBuild([
        ['label' => '■特定口座', 'rows' => [
            ['code' => '7203', 'name' => 'トヨタ自動車', 'qty' => '100', 'avg' => '2000', 'price' => '2500'],
        ]],
    ]);

    $parsed = (new JpStockCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->accountType)->toBe('specific');
});

test('■NISA成長投資枠セクションの保有銘柄はaccountTypeがnisa_growthになる', function () {
    $csv = jpAccCsvBuild([
        ['label' => '■NISA成長投資枠', 'rows' => [
            ['code' => '7203', 'name' => 'トヨタ自動車', 'qty' => '50', 'avg' => '2200', 'price' => '2500'],
        ]],
    ]);

    $parsed = (new JpStockCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->accountType)->toBe('nisa_growth');
});

test('複数の口座区分セクションにまたがる場合、各行が自身のセクションのaccountTypeを持つ', function () {
    $csv = jpAccCsvBuild([
        ['label' => '■特定口座', 'rows' => [
            ['code' => '7203', 'name' => 'トヨタ自動車', 'qty' => '100', 'avg' => '2000', 'price' => '2500'],
        ]],
        ['label' => '■NISA成長投資枠', 'rows' => [
            ['code' => '7203', 'name' => 'トヨタ自動車', 'qty' => '50', 'avg' => '2200', 'price' => '2500'],
        ]],
    ]);

    $parsed = (new JpStockCsvParser)->parse($csv);

    expect($parsed->rows)->toHaveCount(2);
    expect($parsed->rows[0]->accountType)->toBe('specific');
    expect($parsed->rows[1]->accountType)->toBe('nisa_growth');
});

test('未知の口座区分ラベルの見出し行がある場合はCsvStructureExceptionが投げられ取込全体が失敗する', function () {
    $csv = jpAccCsvBuild([
        ['label' => '■謎の口座区分', 'rows' => [
            ['code' => '9999', 'name' => '謎銘柄', 'qty' => '10', 'avg' => '1000', 'price' => '1000'],
        ]],
    ]);

    expect(fn () => (new JpStockCsvParser)->parse($csv))
        ->toThrow(CsvStructureException::class);
});
