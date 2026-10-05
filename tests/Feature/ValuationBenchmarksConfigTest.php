<?php

use App\Services\Analysis\FundamentalMetricToneEvaluator;
use App\Services\Analysis\ValuationBenchmarkJudge;

/*
| CHG-0034 サイクル2a: 業種別基準PER/PBRの設定ファイルとサービスコンテナ登録 — Red phase
| 根拠: docs/adr/ADR-0023, ADR-0026, docs/product/valuation-benchmarks.md 3・4章
*/

dataset('jp_benchmarks', [
    // 業種名 => [PER値, PER信頼度, PBR値, PBR信頼度]
    '食品' => ['食品', 18.9, 'high', 1.17, 'high'],
    'エネルギー資源' => ['エネルギー資源', 12.4, 'low', 1.00, 'low'],
    '建設・資材' => ['建設・資材', 15.4, 'medium', 1.37, 'high'],
    '素材・化学' => ['素材・化学', 20.9, 'high', 1.31, 'high'],
    '医薬品' => ['医薬品', 18.6, 'high', 1.20, 'high'],
    '自動車・輸送機' => ['自動車・輸送機', 14.9, 'medium', 0.96, 'high'],
    '鉄鋼・非鉄' => ['鉄鋼・非鉄', 13.7, 'low', 0.99, 'low'],
    '機械' => ['機械', 23.2, 'high', 1.80, 'high'],
    '電機・精密' => ['電機・精密', 28.5, 'low', 2.40, 'low'],
    '情報通信・サービスその他' => ['情報通信・サービスその他', 19.0, 'high', 1.95, 'high'],
    '電気・ガス' => ['電気・ガス', 10.8, 'medium', 0.80, 'medium'],
    '運輸・物流' => ['運輸・物流', 13.9, 'high', 1.11, 'high'],
    '商社・卸売' => ['商社・卸売', 15.3, 'high', 1.40, 'high'],
    '小売' => ['小売', 22.5, 'high', 1.90, 'high'],
    '銀行' => ['銀行', 20.0, 'low', 1.20, 'low'],
    '金融（除く銀行）' => ['金融（除く銀行）', 12.9, 'high', 1.16, 'high'],
    '不動産' => ['不動産', 11.5, 'high', 1.50, 'high'],
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
    expect($config['jp']['source'])->toContain('JPX')->toContain('単純平均')->not->toContain('加重');
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

test('実際の設定で日本の食品PER9.0は大きく割安と判定される', function () {
    // Arrange
    $judge = app(ValuationBenchmarkJudge::class);

    // Act
    $result = $judge->judge('per', 9.0, 'jp', '食品');

    // Assert
    expect($result['tier'])->toBe('strong_cheap');
    expect($result['ratio'])->toBe(0.48);
    expect($result['benchmark'])->toBe(18.9);
});

test('実際の設定で日本の食品PER10.0は割安(比率0.53)と判定される', function () {
    // Arrange / Act
    $result = app(ValuationBenchmarkJudge::class)->judge('per', 10.0, 'jp', '食品');

    // Assert
    expect($result['tier'])->toBe('cheap');
    expect($result['ratio'])->toBe(0.53);
});

dataset('jp_per_confidence_changes', [
    '情報通信・サービスその他はhigh' => ['情報通信・サービスその他', 'high'],
    '不動産はhigh' => ['不動産', 'high'],
    '建設・資材はmedium' => ['建設・資材', 'medium'],
    '電気・ガスはmedium' => ['電気・ガス', 'medium'],
    '銀行はlow' => ['銀行', 'low'],
]);

test('単純平均への切替で日本のPER信頼度が変わった業種が新しい信頼度になっている', function (string $sector, string $confidence) {
    // Arrange / Act
    $result = app(ValuationBenchmarkJudge::class)->judge('per', 10.0, 'jp', $sector);

    // Assert
    expect($result['confidence'])->toBe($confidence);
})->with('jp_per_confidence_changes');

test('実際の設定で日本の銀行PERは信頼度lowのため濃い色が付かず不安定扱いになる', function () {
    // Arrange / Act (基準PER 20.0。PER8.0=0.40 は本来強い割安)
    $result = app(ValuationBenchmarkJudge::class)->judge('per', 8.0, 'jp', '銀行');

    // Assert
    expect($result['ratio'])->toBe(0.4);
    expect($result['tier'])->toBe('cheap');
    expect($result['unstable'])->toBeTrue();
});

test('実際の設定で日本の情報通信・サービスその他PER9.0はhighのため強い割安になる', function () {
    // Arrange / Act (基準PER 19.0。PER9.0=0.47)
    $result = app(ValuationBenchmarkJudge::class)->judge('per', 9.0, 'jp', '情報通信・サービスその他');

    // Assert
    expect($result['tier'])->toBe('strong_cheap');
    expect($result['unstable'])->toBeFalse();
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

test('UC-003: 銘柄詳細の基準欄に出す出典は、画面で読める短い名前にする（詳しい算出方法は valuation-benchmarks.md に置く）', function () {
    // Arrange
    $config = config('valuation_benchmarks');

    // Act / Assert: the full method lives in docs/product/valuation-benchmarks.md
    expect($config['jp']['source'])->toBe('JPX 月次統計（東証17業種・単純平均）');
    expect($config['us']['source'])->toBe('Damodaran・SPDR 業種ETF');
    expect(mb_strlen($config['jp']['source']))->toBeLessThanOrEqual(30);
    expect(mb_strlen($config['us']['source']))->toBeLessThanOrEqual(30);
});
