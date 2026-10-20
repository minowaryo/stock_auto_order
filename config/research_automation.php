<?php

return [
    'enabled' => true,
    'sources' => [
        'S10' => ['enabled' => true],
        'S09' => ['enabled' => false],
        'H02' => ['enabled' => false],
    ],
    'catalogs' => [
        'jpx_tse' => [
            'enabled' => true,
            'url' => 'https://www.jpx.co.jp/markets/statistics-equities/misc/tvdivq0000001vg2-att/data_j.xlsx',
            'min_interval_hours' => 168,
            'max_response_bytes' => 8 * 1024 * 1024,
        ],
        'sec_exchange' => [
            'enabled' => true,
            'url' => 'https://www.sec.gov/files/company_tickers_exchange.json',
            'min_interval_hours' => 24,
            'max_response_bytes' => 8 * 1024 * 1024,
            'user_agent' => env('RESEARCH_SEC_USER_AGENT', ''),
        ],
    ],
];
