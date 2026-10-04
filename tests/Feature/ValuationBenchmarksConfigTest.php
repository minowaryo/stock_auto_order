<?php

use App\Services\Analysis\FundamentalMetricToneEvaluator;
use App\Services\Analysis\ValuationBenchmarkJudge;

/*
| CHG-0034 サイクル2a: 業種別基準PER/PBRの設定ファイルとサービスコンテナ登録 — Red phase
| 根拠: docs/adr/ADR-0023, ADR-0026, docs/product/valuation-benchmarks.md 3・4章
*/

dataset('jp_benchmarks', [
    // 業種名 => [PER値, PER信頼度, PBR値, PBR信頼度]
    '食品' => ['食品', 23.5, 'high', 1.86, 'high'],
    'エネルギー資源' => ['エネルギー資源', 12.3, 'low', 0.95, 'low'],
    '建設・資材' => ['建設・資材', 13.9, 'low', 1.35, 'high'],
    '素材・化学' => ['素材・化学', 23.3, 'high', 1.41, 'high'],
    '医薬品' => ['医薬品', 24.3, 'high', 1.90, 'high'],
    '自動車・輸送機' => ['自動車・輸送機', 16.0, 'medium', 0.92, 'high'],
    '鉄鋼・非鉄' => ['鉄鋼・非鉄', 23.9, 'low', 1.60, 'low'],
    '機械' => ['機械', 25.4, 'high', 2.20, 'high'],
    '電機・精密' => ['電機・精密', 43.5, 'low', 3.64, 'low'],
    '情報通信・サービスその他' => ['情報通信・サービスその他', 17.3, 'low', 2.05, 'high'],
    '電気・ガス' => ['電気・ガス', 14.3, 'low', 0.74, 'medium'],
    '運輸・物流' => ['運輸・物流', 12.8, 'high', 1.08, 'high'],
    '商社・卸売' => ['商社・卸売', 17.3, 'high', 1.80, 'medium'],
    '小売' => ['小売', 27.7, 'high', 2.50, 'high'],
    '銀行' => ['銀行', 18.4, 'high', 1.60, 'low'],
    '金融（除く銀行）' => ['金融（除く銀行）', 12.8, 'high', 1.44, 'high'],
    '不動産' => ['不動産', 13.2, 'low', 1.30, 'medium'],
]);

test('設定には日本・米国の出典と基準時点が空でない文字列で定義されている', function () {
    // Arrange / Act
    $config = config('valuation_benchmarks');

    // Assert
    expect($config)->toBeArray()->toHaveKeys(['jp', 'us']);
    foreach (['jp', 'us'] as $market) {
        expect($config[$market])->toHaveKeys(['as_of', 'source', 'per', 'pbr']);
        expect($config[$market]['as_of'])->toBeString()->not->toBe('');
        expect($config[$market]['source'])->toBeString()->not->toBe('');
    }
    expect($config['jp']['as_of'])->toContain('2026-09');
    expect($config['jp']['source'])->toContain('JPX');
    expect($config['us']['source'])->toContain('Damodaran');
});

test('日本の業種別基準PER/PBRが基準値文書どおりに設定されている', function (string $sector, float $per, string $perConf, float $pbr, string $pbrConf) {
    // Arrange / Act
    $jp = config('valuation_benchmarks.jp');

    // Assert
    expect((float) $jp['per'][$sector]['value'])->toBe($per);
    expect($jp['per'][$sector]['confidence'])->toBe($perConf);
    expect((float) $jp['pbr'][$sector]['value'])->toBe($pbr);
    expect($jp['pbr'][$sector]['confidence'])->toBe($pbrConf);
})->with('jp_benchmarks');

test('日本の基準は17業種のみで「その他」は含まれない', function () {
    // Arrange / Act
    $jp = config('valuation_benchmarks.jp');

    // Assert
    expect($jp['per'])->toHaveCount(17)->not->toHaveKey('その他');
    expect($jp['pbr'])->toHaveCount(17)->not->toHaveKey('その他');
});

test('米国の業種別基準PERが基準値文書どおりに設定されている', function () {
    // Arrange
    $expected = [
        'Semiconductors' => [44.8, 'high'],
        'Aerospace & Defense' => [33.4, 'high'],
        'Utilities' => [19.2, 'high'],
        'Pharmaceuticals' => [20.1, 'low'],
        'Metals & Mining' => [23.7, 'low'],
        'Road & Rail' => [19.0, 'low'],
        'Electrical Equipment' => [37.9, 'low'],
        'Retail' => [19.9, 'low'],
    ];

    // Act
    $per = config('valuation_benchmarks.us.per');

    // Assert
    foreach ($expected as $sector => [$value, $confidence]) {
        expect((float) $per[$sector]['value'])->toBe($value, $sector);
        expect($per[$sector]['confidence'])->toBe($confidence, $sector);
    }
    foreach (['Automobiles', 'Beverages', 'Media', 'Technology'] as $sector) {
        expect($per[$sector]['value'])->toBeNull();
        expect($per[$sector]['confidence'])->toBe('none');
    }
    expect($per)->toHaveCount(12);
});

test('米国の業種別基準PBRはUtilitiesのみ設定されている', function () {
    // Arrange / Act
    $pbr = config('valuation_benchmarks.us.pbr');

    // Assert
    expect($pbr)->toHaveCount(1);
    expect((float) $pbr['Utilities']['value'])->toBe(2.0);
    expect($pbr['Utilities']['confidence'])->toBe('high');
});

test('実際の設定で日本の食品PER10.0は大きく割安と判定される', function () {
    // Arrange
    $judge = app(ValuationBenchmarkJudge::class);

    // Act
    $result = $judge->judge('per', 10.0, 'jp', '食品');

    // Assert
    expect($result['tier'])->toBe('strong_cheap');
});

test('実際の設定で米国Automobilesの基準値nullは例外にならず判定なし(confidence_none)になる', function () {
    // Arrange
    $judge = app(ValuationBenchmarkJudge::class);

    // Act
    $result = $judge->judge('per', 15.0, 'us', 'Automobiles');

    // Assert
    expect($result['tier'])->toBeNull();
    expect($result['reason'])->toBe('confidence_none');
});

test('実際の設定で米国SemiconductorsのPBRは基準なし(no_benchmark)になる', function () {
    // Arrange
    $judge = app(ValuationBenchmarkJudge::class);

    // Act
    $result = $judge->judge('pbr', 5.0, 'us', 'Semiconductors');

    // Assert
    expect($result['tier'])->toBeNull();
    expect($result['reason'])->toBe('no_benchmark');
});

test('実際の設定で日本の「その他」は基準なし(no_benchmark)になる', function () {
    // Arrange
    $judge = app(ValuationBenchmarkJudge::class);

    // Act
    $result = $judge->judge('per', 15.0, 'jp', 'その他');

    // Assert
    expect($result['tier'])->toBeNull();
    expect($result['reason'])->toBe('no_benchmark');
});

test('ValuationBenchmarkJudgeは解決のたびに現在の設定から組み立てられる', function () {
    // Arrange
    config(['valuation_benchmarks' => [
        'jp' => [
            'as_of' => '2030-01',
            'source' => 'test source',
            'per' => ['食品' => ['value' => 10.0, 'confidence' => 'high']],
            'pbr' => [],
        ],
    ]]);

    // Act
    $result = app(ValuationBenchmarkJudge::class)->judge('per', 10.0, 'jp', '食品');
    config(['valuation_benchmarks.jp.per.食品.value' => 20.0]);
    $after = app(ValuationBenchmarkJudge::class)->judge('per', 10.0, 'jp', '食品');

    // Assert
    expect($result['tier'])->toBe('fair');
    expect($result['benchmark'])->toBe(10.0);
    expect($result['as_of'])->toBe('2030-01');
    expect($after['tier'])->toBe('cheap');
    expect($after['benchmark'])->toBe(20.0);
});

test('FundamentalMetricToneEvaluatorはサービスコンテナから解決できる', function () {
    // Arrange / Act
    $evaluator = app(FundamentalMetricToneEvaluator::class);

    // Assert
    expect($evaluator)->toBeInstanceOf(FundamentalMetricToneEvaluator::class);
    expect($evaluator->tone('roe', 16.0, 'jp', '食品'))->toBe('strong_good');
});
