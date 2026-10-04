<?php

use App\Actions\Holding\ShowHoldingDetailAction;
use App\Models\FundamentalIndicator;
use App\Models\Holding;
use App\Models\HoldingSnapshot;
use App\Models\ImportBatch;
use App\Models\SectorClassification;
use App\Models\Snapshot;

/*
| UC-003 / CHG-0034 サイクル2a: ShowHoldingDetailAction の出力に PER/PBR の
| 業種比較判定 (per_verdict / pbr_verdict) と財務指標の色調 (metric_tones) を
| 配線する — Red phase
| 根拠: ADR-0023 D3-D6・D9, ADR-0026, valuation-benchmarks.md
| 画面表示は次サイクル(2b)のため対象外。
*/

/**
 * @param  array<string, mixed>|null  $fundamental  null to skip the fundamental row
 * @return array<string, mixed>
 */
function uc003vTestDetail(string $market, ?string $sector, ?array $fundamental): array
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
    $snapshot = Snapshot::create(['import_batch_id' => $batch->id, 'snapshotted_at' => now()]);
    $sectorId = $sector === null ? null : SectorClassification::firstOrCreate(['market' => $market, 'name' => $sector], ['code' => null])->id;

    $holding = Holding::create([
        'symbol_code' => '9999',
        'market' => $market,
        'instrument_type' => 'stock',
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
            'operating_income_growth' => null, 'equity_ratio' => null, 'operating_margin' => null,
            'dividend_yield' => null, 'dividend_payout_ratio' => null, 'fetched_at' => now(),
        ], $fundamental));
    }

    return app(ShowHoldingDetailAction::class)->execute($holding->fresh());
}

test('日本・食品でPER9.0・PBR0.5の銘柄はPERもPBRも大きく割安と判定される', function () {
    // Arrange / Act
    $detail = uc003vTestDetail('jp', '食品', ['per' => 9.0, 'pbr' => 0.5]);

    // Assert
    expect($detail['per_verdict']['tier'])->toBe('strong_cheap');
    expect($detail['pbr_verdict']['tier'])->toBe('strong_cheap');
    expect((float) $detail['pbr_verdict']['ratio'])->toBe(0.43);
});

test('米国・SemiconductorsのPBRは基準なし(no_benchmark)で判定されない', function () {
    // Arrange / Act
    $detail = uc003vTestDetail('us', 'Semiconductors', ['per' => 22.4, 'pbr' => 5.0]);

    // Assert
    expect($detail['pbr_verdict']['tier'])->toBeNull();
    expect($detail['pbr_verdict']['reason'])->toBe('no_benchmark');
});

test('米国・UtilitiesでPBR1.0は割安(比率0.50)と判定される', function () {
    // Arrange / Act
    $detail = uc003vTestDetail('us', 'Utilities', ['per' => 19.2, 'pbr' => 1.0]);

    // Assert
    expect($detail['pbr_verdict']['tier'])->toBe('cheap');
    expect((float) $detail['pbr_verdict']['ratio'])->toBe(0.5);
});

test('業種未分類の銘柄は判定なし(sector_unclassified)になる', function () {
    // Arrange / Act
    $detail = uc003vTestDetail('jp', null, ['per' => 10.0, 'pbr' => 0.9]);

    // Assert
    expect($detail['per_verdict']['tier'])->toBeNull();
    expect($detail['per_verdict']['reason'])->toBe('sector_unclassified');
    expect($detail['pbr_verdict']['reason'])->toBe('sector_unclassified');
});

test('米国・Automobilesは基準の信頼度なし(confidence_none)で判定されない', function () {
    // Arrange / Act
    $detail = uc003vTestDetail('us', 'Automobiles', ['per' => 15.0, 'pbr' => 2.0]);

    // Assert
    expect($detail['per_verdict']['tier'])->toBeNull();
    expect($detail['per_verdict']['reason'])->toBe('confidence_none');
});

test('赤字(PER負)の銘柄は判定なし(invalid_value)になる', function () {
    // Arrange / Act
    $detail = uc003vTestDetail('jp', '食品', ['per' => -8.0, 'pbr' => 0.9]);

    // Assert
    expect($detail['per_verdict']['tier'])->toBeNull();
    expect($detail['per_verdict']['reason'])->toBe('invalid_value');
});

test('基本指標が未取得の銘柄はinvalid_valueで、色調は全てnullになる', function () {
    // Arrange / Act
    $detail = uc003vTestDetail('jp', '食品', null);

    // Assert
    expect($detail['per_verdict']['reason'])->toBe('invalid_value');
    expect($detail['pbr_verdict']['reason'])->toBe('invalid_value');
    expect($detail['metric_tones'])->toBe([
        'roe' => null,
        'equity_ratio' => null,
        'operating_margin' => null,
        'revenue_growth' => null,
        'operating_income_growth' => null,
    ]);
});

test('財務指標の色調が5項目それぞれ市場別ラインで判定される', function () {
    // Arrange / Act
    $detail = uc003vTestDetail('jp', '食品', [
        'roe' => 16.0, 'equity_ratio' => 45.0, 'operating_margin' => 5.0,
        'revenue_growth' => -12.0, 'operating_income_growth' => 35.0,
    ]);

    // Assert
    expect($detail['metric_tones'])->toBe([
        'roe' => 'strong_good',
        'equity_ratio' => 'good',
        'operating_margin' => 'below',
        'revenue_growth' => 'strong_below',
        'operating_income_growth' => 'strong_good',
    ]);
});

test('銀行の保有では自己資本比率と営業利益率は色調なしで、ROEと成長率は判定される', function () {
    // Arrange / Act
    $detail = uc003vTestDetail('jp', '銀行', [
        'roe' => 16.0, 'equity_ratio' => 5.0, 'operating_margin' => 10.0,
        'revenue_growth' => 5.0, 'operating_income_growth' => 5.0,
    ]);

    // Assert
    expect($detail['metric_tones']['equity_ratio'])->toBeNull();
    expect($detail['metric_tones']['operating_margin'])->toBeNull();
    expect($detail['metric_tones']['roe'])->toBe('strong_good');
    expect($detail['metric_tones']['revenue_growth'])->toBe('good');
    expect($detail['metric_tones']['operating_income_growth'])->toBe('good');
});

test('設定を差し替えると差し替え後の基準表で判定される', function () {
    // Arrange
    config(['valuation_benchmarks' => [
        'jp' => [
            'as_of' => '2030-01',
            'source' => 'test',
            'per' => ['食品' => ['value' => 10.0, 'confidence' => 'high']],
            'pbr' => ['食品' => ['value' => 1.0, 'confidence' => 'high']],
        ],
    ]]);

    // Act
    $detail = uc003vTestDetail('jp', '食品', ['per' => 10.0, 'pbr' => 1.0]);

    // Assert
    expect($detail['per_verdict']['tier'])->toBe('fair');
    expect($detail['pbr_verdict']['tier'])->toBe('fair');
    expect($detail['per_verdict']['as_of'])->toBe('2030-01');
});
