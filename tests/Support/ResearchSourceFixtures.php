<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Laravel appends fake callbacks, so a replacement response needs a new Factory. */
function uc016FakeSourceResponses(array $responses): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake($responses);
}

function uc016SourceDefinition(array $overrides = []): array
{
    return array_replace([
        'name' => '試験用RSS', 'method' => 'rss', 'url' => 'https://example.org/feed.xml',
        'enabled' => true, 'max_response_bytes' => 65536, 'coverage_days' => 14,
    ], $overrides);
}

function uc016SourceRss(array $urls = ['https://example.org/articles/old'], bool $dated = true): string
{
    $items = '';
    foreach ($urls as $url) {
        $date = $dated ? '<pubDate>Fri, 09 Oct 2026 03:00:00 GMT</pubDate>' : '';
        $items .= '<item><title>試験の発表</title><link>'.htmlspecialchars($url, ENT_XML1).'</link>'.$date
            .'<dc:creator>試験著者</dc:creator><category>AI</category>'
            .'<description>ARTICLE_BODY_MUST_NOT_BE_STORED</description></item>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>試験RSS</title>'.$items.'</channel></rss>';
}

function uc016SourceCheck(string $key = 'H01', array $overrides = []): int
{
    $startedAt = $overrides['started_at'] ?? now()->subDays(2);

    return DB::table('research_source_checks')->insertGetId(array_replace([
        'source_key' => $key, 'trigger' => 'startup', 'started_at' => $startedAt,
        'finished_at' => $startedAt, 'result' => 'no_new',
        'item_count' => 0, 'new_item_count' => 0, 'http_status' => 200,
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides));
}

function uc016SourceItem(int $checkId, array $overrides = []): int
{
    $url = $overrides['url'] ?? 'https://example.org/articles/old';

    return DB::table('research_source_items')->insertGetId(array_replace([
        'source_key' => 'H01', 'url' => $url, 'url_hash' => hash('sha256', $url),
        'title' => '試験の発表', 'published_at' => '2026-10-09 03:00:00',
        'metadata' => json_encode(['author' => '試験著者'], JSON_THROW_ON_ERROR),
        'first_check_id' => $checkId, 'is_baseline' => false, 'disposition' => 'unreviewed',
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides));
}
