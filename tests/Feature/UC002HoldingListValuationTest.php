<?php

use App\Actions\Holding\ListHoldingsAction;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;

/*
| UC-002 / CHG-0034 サイクル2a: ListHoldingsAction の出力に業種比較の判定
| (per_verdict) と売上成長率の色調 (revenue_growth_tone) を配線する — Red phase
| 根拠: ADR-0023 D3-D6・D9, ADR-0026, valuation-benchmarks.md
| 画面表示（Blade/Livewire）は次サイクル(2b)のため対象外。
*/

function uc002vTestSnapshot(): Snapshot
{
    $batch = ImportBatch::create([
        'status' => 'completed',
        'jp_stock_filename' => 'jp_stock.csv',
        'us_stock_filename' => 'us_stock.csv',
        'mutual_fund_filename' => null,
        'imported_count' => 0,
        'error_count' => 0,
        'imported_at' => now(),
    ]);

    return Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
}

/**
 * Creates a holding in the latest snapshot and returns its list row.
 *
 * @param  array<string, mixed>  $fundamental  null to skip the fundamental row
 */
function uc002vTestRow(string $market, ?string $sector, ?array $fundamental, string $type = 'stock'): array
{
    $snapshot = uc002vTestSnapshot();
    $sectorId = $sector === null ? null : SectorClassification::firstOrCreate(['market' => $market, 'name' => $sector], ['code' => null])->id;

    $holding = Holding::create([
        'symbol_code' => '9999',
        'market' => $market,
        'instrument_type' => $type,
        'symbol_name' => 'テスト銘柄',
        'sector_classification_id' => $sectorId,
        'first_detected_at' => now(),
    ]);
    HoldingSnapshot::create([
        'snapshot_id' => $snapshot->id,
        'holding_id' => $holding->id,
        'quantity' => 10,
        'average_cost' => 2000,
        'current_price' => 2500,
        'fx_rate_used' => null,
        'unrealized_gain_amount' => 5000,
        'unrealized_gain_rate' => 25.0,
        'ma20' => null,
        'ma75' => null,
        'is_newly_detected' => false,
    ]);
    if ($fundamental !== null) {
        FundamentalIndicator::create(array_merge([
            'holding_id' => $holding->id,
            'per' => null, 'pbr' => null, 'roe' => null, 'revenue_growth' => null,
            'operating_income_growth' => null, 'equity_ratio' => null, 'dividend_yield' => null,
            'dividend_payout_ratio' => null, 'fetched_at' => now(),
        ], $fundamental));
    }

    return app(ListHoldingsAction::class)->execute()[0];
}

test('日本・食品でPER10.0の個別株は業種比較で大きく割安と判定される', function () {
    // Arrange / Act
    $row = uc002vTestRow('jp', '食品', ['per' => 10.0]);

    // Assert
    expect($row['per_verdict'])->toBeArray();
    expect($row['per_verdict']['tier'])->toBe('strong_cheap');
    expect((float) $row['per_verdict']['benchmark'])->toBe(23.5);
    expect($row['per_verdict']['confidence'])->toBe('high');
    expect($row['per_verdict'])->toHaveKeys(['ratio', 'unstable', 'as_of', 'source']);
});

test('基準の信頼度が低い業種(電機・精密)でPER20.0は濃色が格下げされ割安・不安定扱いになる', function () {
    // Arrange / Act
    $row = uc002vTestRow('jp', '電機・精密', ['per' => 20.0]);

    // Assert
    expect($row['per_verdict']['tier'])->toBe('cheap');
    expect($row['per_verdict']['unstable'])->toBeTrue();
});

test('業種未分類の個別株はper_verdictがnullになる', function () {
    // Arrange / Act
    $row = uc002vTestRow('jp', null, ['per' => 10.0]);

    // Assert
    expect($row['per_verdict'])->toBeNull();
});

test('PERが0以下またはnullの個別株はper_verdictがnullになる', function (?float $per) {
    // Arrange / Act
    $row = uc002vTestRow('jp', '食品', ['per' => $per]);

    // Assert
    expect($row['per_verdict'])->toBeNull();
})->with([[0.0], [-5.0], [null]]);

test('基本指標が未取得の個別株はper_verdictがnullになる', function () {
    // Arrange / Act
    $row = uc002vTestRow('jp', '食品', null);

    // Assert
    expect($row['per_verdict'])->toBeNull();
    expect($row['revenue_growth_tone'])->toBeNull();
});

test('ETF・投資信託はper_verdictとrevenue_growth_toneがnullになる', function (string $type) {
    // Arrange / Act
    $row = uc002vTestRow('jp', '食品', ['per' => 10.0, 'revenue_growth' => 16.0], $type);

    // Assert
    expect($row['per_verdict'])->toBeNull();
    expect($row['revenue_growth_tone'])->toBeNull();
})->with(['etf', 'mutual_fund']);

test('米国・SemiconductorsでPER22.4は割安(比率0.50)と判定される', function () {
    // Arrange / Act
    $row = uc002vTestRow('us', 'Semiconductors', ['per' => 22.4]);

    // Assert
    expect($row['per_verdict']['tier'])->toBe('cheap');
    expect((float) $row['per_verdict']['ratio'])->toBe(0.5);
});

test('売上成長率の色調は市場別のラインで判定される', function (string $market, ?float $growth, ?string $expected) {
    // Arrange / Act
    $row = uc002vTestRow($market, $market === 'jp' ? '食品' : 'Semiconductors', ['revenue_growth' => $growth]);

    // Assert
    expect($row['revenue_growth_tone'])->toBe($expected);
})->with([
    '日本16.0は強い良好' => ['jp', 16.0, 'strong_good'],
    '米国16.0は良好' => ['us', 16.0, 'good'],
    '日本-12.0は大きく下回る' => ['jp', -12.0, 'strong_below'],
    'nullはnull' => ['jp', null, null],
]);

test('設定を差し替えると差し替え後の基準表で判定される', function () {
    // Arrange
    config(['valuation_benchmarks' => [
        'jp' => [
            'as_of' => '2030-01',
            'source' => 'test',
            'per' => ['食品' => ['value' => 10.0, 'confidence' => 'high']],
            'pbr' => [],
        ],
    ]]);

    // Act
    $row = uc002vTestRow('jp', '食品', ['per' => 10.0]);

    // Assert
    expect($row['per_verdict']['tier'])->toBe('fair');
    expect((float) $row['per_verdict']['benchmark'])->toBe(10.0);
});

test('既存キーの値は配線後も変わらない', function () {
    // Arrange / Act
    $row = uc002vTestRow('jp', '食品', ['per' => 10.0, 'revenue_growth' => 12.3]);

    // Assert
    expect((float) $row['per'])->toBe(10.0);
    expect((float) $row['revenue_growth'])->toBe(12.3);
    expect($row['sector'])->toBe('食品');
    expect($row['market'])->toBe('jp');
    expect($row)->toHaveKeys(['id', 'symbol_code', 'symbol_name', 'instrument_type', 'quantity', 'average_cost', 'current_price', 'unrealized_gain_rate', 'has_signal', 'rsi', 'is_newly_detected']);
});
