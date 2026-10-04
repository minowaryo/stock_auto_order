<?php

/*
| Sector benchmark PER/PBR for valuation colors (CHG-0034, ADR-0023).
| Source of truth: docs/product/valuation-benchmarks.md (update that file first).
*/

return [
    'jp' => [
        'as_of' => '2026-09',
        'source' => 'JPX 月次統計（東証17業種、プライム・連結、加重）。17業種は33業種の公式値を純利益・純資産で加重集計した再計算値',
        'per' => [
            '食品' => ['value' => 23.5, 'confidence' => 'high'],
            'エネルギー資源' => ['value' => 12.3, 'confidence' => 'low'],
            '建設・資材' => ['value' => 13.9, 'confidence' => 'low'],
            '素材・化学' => ['value' => 23.3, 'confidence' => 'high'],
            '医薬品' => ['value' => 24.3, 'confidence' => 'high'],
            '自動車・輸送機' => ['value' => 16.0, 'confidence' => 'medium'],
            '鉄鋼・非鉄' => ['value' => 23.9, 'confidence' => 'low'],
            '機械' => ['value' => 25.4, 'confidence' => 'high'],
            '電機・精密' => ['value' => 43.5, 'confidence' => 'low'],
            '情報通信・サービスその他' => ['value' => 17.3, 'confidence' => 'low'],
            '電気・ガス' => ['value' => 14.3, 'confidence' => 'low'],
            '運輸・物流' => ['value' => 12.8, 'confidence' => 'high'],
            '商社・卸売' => ['value' => 17.3, 'confidence' => 'high'],
            '小売' => ['value' => 27.7, 'confidence' => 'high'],
            '銀行' => ['value' => 18.4, 'confidence' => 'high'],
            '金融（除く銀行）' => ['value' => 12.8, 'confidence' => 'high'],
            '不動産' => ['value' => 13.2, 'confidence' => 'low'],
        ],
        'pbr' => [
            '食品' => ['value' => 1.86, 'confidence' => 'high'],
            'エネルギー資源' => ['value' => 0.95, 'confidence' => 'low'],
            '建設・資材' => ['value' => 1.35, 'confidence' => 'high'],
            '素材・化学' => ['value' => 1.41, 'confidence' => 'high'],
            '医薬品' => ['value' => 1.90, 'confidence' => 'high'],
            '自動車・輸送機' => ['value' => 0.92, 'confidence' => 'high'],
            '鉄鋼・非鉄' => ['value' => 1.60, 'confidence' => 'low'],
            '機械' => ['value' => 2.20, 'confidence' => 'high'],
            '電機・精密' => ['value' => 3.64, 'confidence' => 'low'],
            '情報通信・サービスその他' => ['value' => 2.05, 'confidence' => 'high'],
            '電気・ガス' => ['value' => 0.74, 'confidence' => 'medium'],
            '運輸・物流' => ['value' => 1.08, 'confidence' => 'high'],
            '商社・卸売' => ['value' => 1.80, 'confidence' => 'medium'],
            '小売' => ['value' => 2.50, 'confidence' => 'high'],
            '銀行' => ['value' => 1.60, 'confidence' => 'low'],
            '金融（除く銀行）' => ['value' => 1.44, 'confidence' => 'high'],
            '不動産' => ['value' => 1.30, 'confidence' => 'medium'],
        ],
    ],
    'us' => [
        'as_of' => '2026-01〜2026-10',
        'source' => 'Damodaran（NYU Stern）業種別データと SPDR 業種ETF の実績P/Eの相加平均',
        'per' => [
            'Semiconductors' => ['value' => 44.8, 'confidence' => 'high'],
            'Aerospace & Defense' => ['value' => 33.4, 'confidence' => 'high'],
            'Utilities' => ['value' => 19.2, 'confidence' => 'high'],
            'Pharmaceuticals' => ['value' => 20.1, 'confidence' => 'low'],
            'Metals & Mining' => ['value' => 23.7, 'confidence' => 'low'],
            'Road & Rail' => ['value' => 19.0, 'confidence' => 'low'],
            'Electrical Equipment' => ['value' => 37.9, 'confidence' => 'low'],
            'Retail' => ['value' => 19.9, 'confidence' => 'low'],
            'Automobiles' => ['value' => null, 'confidence' => 'none'],
            'Beverages' => ['value' => null, 'confidence' => 'none'],
            'Media' => ['value' => null, 'confidence' => 'none'],
            'Technology' => ['value' => null, 'confidence' => 'none'],
        ],
        'pbr' => [
            'Utilities' => ['value' => 2.0, 'confidence' => 'high'],
        ],
    ],
];
