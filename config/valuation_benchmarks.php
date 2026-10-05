<?php

/*
| Sector benchmark PER/PBR for valuation colors (CHG-0034, ADR-0023).
| Source of truth: docs/product/valuation-benchmarks.md (update that file first).
*/

return [
    'jp' => [
        'as_of' => '2026-09',
        'source' => 'JPX 月次統計（東証17業種・単純平均）',
        'per' => [
            '食品' => ['value' => 18.9, 'confidence' => 'high'],
            'エネルギー資源' => ['value' => 12.4, 'confidence' => 'low'],
            '建設・資材' => ['value' => 15.4, 'confidence' => 'medium'],
            '素材・化学' => ['value' => 20.9, 'confidence' => 'high'],
            '医薬品' => ['value' => 18.6, 'confidence' => 'high'],
            '自動車・輸送機' => ['value' => 14.9, 'confidence' => 'medium'],
            '鉄鋼・非鉄' => ['value' => 13.7, 'confidence' => 'low'],
            '機械' => ['value' => 23.2, 'confidence' => 'high'],
            '電機・精密' => ['value' => 28.5, 'confidence' => 'low'],
            '情報通信・サービスその他' => ['value' => 19.0, 'confidence' => 'high'],
            '電気・ガス' => ['value' => 10.8, 'confidence' => 'medium'],
            '運輸・物流' => ['value' => 13.9, 'confidence' => 'high'],
            '商社・卸売' => ['value' => 15.3, 'confidence' => 'high'],
            '小売' => ['value' => 22.5, 'confidence' => 'high'],
            '銀行' => ['value' => 20.0, 'confidence' => 'low'],
            '金融（除く銀行）' => ['value' => 12.9, 'confidence' => 'high'],
            '不動産' => ['value' => 11.5, 'confidence' => 'high'],
        ],
        'pbr' => [
            '食品' => ['value' => 1.17, 'confidence' => 'high'],
            'エネルギー資源' => ['value' => 1.00, 'confidence' => 'low'],
            '建設・資材' => ['value' => 1.37, 'confidence' => 'high'],
            '素材・化学' => ['value' => 1.31, 'confidence' => 'high'],
            '医薬品' => ['value' => 1.20, 'confidence' => 'high'],
            '自動車・輸送機' => ['value' => 0.96, 'confidence' => 'high'],
            '鉄鋼・非鉄' => ['value' => 0.99, 'confidence' => 'low'],
            '機械' => ['value' => 1.80, 'confidence' => 'high'],
            '電機・精密' => ['value' => 2.40, 'confidence' => 'low'],
            '情報通信・サービスその他' => ['value' => 1.95, 'confidence' => 'high'],
            '電気・ガス' => ['value' => 0.80, 'confidence' => 'medium'],
            '運輸・物流' => ['value' => 1.11, 'confidence' => 'high'],
            '商社・卸売' => ['value' => 1.40, 'confidence' => 'high'],
            '小売' => ['value' => 1.90, 'confidence' => 'high'],
            '銀行' => ['value' => 1.20, 'confidence' => 'low'],
            '金融（除く銀行）' => ['value' => 1.16, 'confidence' => 'high'],
            '不動産' => ['value' => 1.50, 'confidence' => 'high'],
        ],
    ],
    'us' => [
        'as_of' => '2026-01〜2026-10',
        'source' => 'Damodaran・SPDR 業種ETF',
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
