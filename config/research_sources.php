<?php

// Enable only after the source's access conditions and response structure have
// been verified (ADR-0031). Activation evidence is recorded in the source catalog.
return [
    'H01' => [
        'name' => 'ものづくり太郎（YouTube公式RSS）',
        'method' => 'rss',
        'url' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCY9KXoezyo6cp-YwguOOCcg',
        'enabled' => false,
        'min_interval_hours' => 24,
        'max_response_bytes' => 1048576,
        'coverage_days' => 14,
    ],
    'H02' => [
        'name' => '中島聡（公開note公式RSS）',
        'method' => 'rss',
        'url' => 'https://note.com/lifeisbeautiful/rss',
        'enabled' => true,
        'min_interval_hours' => 24,
        'max_response_bytes' => 1048576,
        'coverage_days' => 14,
    ],
    'S09' => [
        'name' => 'NEDO公募・採択結果',
        'method' => 'html_list',
        'url' => 'https://www.nedo.go.jp/koubo/2026_list_05_03.html',
        'enabled' => true,
        'min_interval_hours' => 24,
        'max_response_bytes' => 1048576,
        'coverage_days' => 365,
    ],
    'S10' => [
        'name' => 'FDA AI医療機器一覧',
        'method' => 'file',
        'url' => 'https://www.fda.gov/media/178541/download?attachment=',
        'enabled' => true,
        'min_interval_hours' => 168,
        'min_request_interval_seconds' => 30,
        'max_response_bytes' => 5242880,
        'coverage_days' => 365,
    ],
];
