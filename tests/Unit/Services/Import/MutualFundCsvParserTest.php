<?php

namespace Tests\Unit\Services\Import;

use App\Services\Import\MutualFundCsvParser;

/*
|--------------------------------------------------------------------------
| MutualFundCsvParser — account type (口座区分) Red phase Unit Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/adr/ADR-0002-nisa-account-type-tracking.md
|   - docs/architecture/data-model.md (`holding_snapshot_accounts`)
|   - C:\Users\minow\.claude\plans\stock_auto_order-nisa-account-implementation-phase.md
|     ("投資信託CSVには...口座区分列（特定/NISAつみたて投資枠）が実在する")
|
| Unlike the two stock parsers, the mutual fund CSV already has a
| "口座区分" column loaded into $columnIndex (it is part of the header row),
| but MutualFundCsvParser has no constant/logic referencing it yet — the
| column value is never read. ParsedCsvRow also has no `accountType`
| property yet. Every test below is expected to fail:
|   - the "known label" tests fail because $row->accountType does not
|     exist (reading an undefined property returns null)
|   - the "unknown label" test fails because the parser today has no
|     concept of account-type validation at all: every row parses
|     successfully regardless of the 口座区分 column's value
|
| This is a pure parsing Unit Test (no DB/HTTP dependency), so — matching
| tests/Unit/Services/Analysis/FundamentalIndicatorMapperTest.php — it is
| not bound to Tests\TestCase (Unit/ tests are plain Pest per tests/Pest.php).
*/

function mfAccCsvHeader(): string
{
    return '投資信託種別,口座区分,ファンド,保有数量[口],平均取得価額[円],基準価額[円]';
}

/**
 * Build a minimal mutual fund CSV (single flat table, no account-section
 * splitting — unlike the stock CSVs, the account type is a per-row column
 * value instead), trimmed down to only the columns MutualFundCsvParser
 * actually reads.
 *
 * @param  array<int, array{account_type: string, fund_name: string, qty: string, avg: string, price: string}>  $rows
 */
function mfAccCsvBuild(array $rows): string
{
    $lines = [mfAccCsvHeader()];

    foreach ($rows as $row) {
        $lines[] = implode(',', ['投資信託', $row['account_type'], $row['fund_name'], $row['qty'], $row['avg'], $row['price']]);
    }

    return implode("\r\n", $lines)."\r\n";
}

test('口座区分列が「特定」の場合accountTypeがspecificになる', function () {
    $csv = mfAccCsvBuild([
        ['account_type' => '特定', 'fund_name' => '楽天・全米株式インデックス・ファンド', 'qty' => '100', 'avg' => '10000', 'price' => '12000'],
    ]);

    $parsed = (new MutualFundCsvParser())->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->accountType)->toBe('specific');
});

test('口座区分列が「NISAつみたて投資枠」の場合accountTypeがnisa_tsumitateになる', function () {
    $csv = mfAccCsvBuild([
        ['account_type' => 'NISAつみたて投資枠', 'fund_name' => 'eMAXIS Slim 全世界株式', 'qty' => '50', 'avg' => '11000', 'price' => '12500'],
    ]);

    $parsed = (new MutualFundCsvParser())->parse($csv);

    expect($parsed->rows)->toHaveCount(1);
    expect($parsed->rows[0]->accountType)->toBe('nisa_tsumitate');
});

test('同一ファンドが複数口座区分にまたがる場合、各行が自身の口座区分列のaccountTypeを持つ', function () {
    $csv = mfAccCsvBuild([
        ['account_type' => '特定', 'fund_name' => '楽天・全米株式インデックス・ファンド', 'qty' => '100', 'avg' => '10000', 'price' => '12000'],
        ['account_type' => 'NISAつみたて投資枠', 'fund_name' => '楽天・全米株式インデックス・ファンド', 'qty' => '50', 'avg' => '11000', 'price' => '12000'],
    ]);

    $parsed = (new MutualFundCsvParser())->parse($csv);

    expect($parsed->rows)->toHaveCount(2);
    expect($parsed->rows[0]->accountType)->toBe('specific');
    expect($parsed->rows[1]->accountType)->toBe('nisa_tsumitate');
});

test('口座区分列が未知のラベルの場合はエラーとして扱われる', function () {
    $csv = mfAccCsvBuild([
        ['account_type' => '謎の口座区分', 'fund_name' => '謎ファンド', 'qty' => '1', 'avg' => '1000', 'price' => '1000'],
    ]);

    $exceptionThrown = false;
    $fundNames = [];
    $errorCount = 0;

    try {
        $parsed = (new MutualFundCsvParser())->parse($csv);
        $fundNames = array_map(fn ($row) => $row->code, $parsed->rows);
        $errorCount = $parsed->errorCount;
    } catch (\Throwable) {
        $exceptionThrown = true;
    }

    expect($exceptionThrown || (! in_array('謎ファンド', $fundNames, true) && $errorCount > 0))->toBeTrue();
});
