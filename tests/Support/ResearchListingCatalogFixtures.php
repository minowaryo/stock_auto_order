<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function uc016CatalogUrl(string $key): string
{
    return match ($key) {
        'jpx_tse' => 'https://www.jpx.co.jp/markets/statistics-equities/misc/tvdivq0000001vg2-att/data_j.xlsx',
        'sec_exchange' => 'https://www.sec.gov/files/company_tickers_exchange.json',
    };
}

function uc016CatalogConfig(string $key, array $overrides = []): void
{
    config(['research_automation.catalogs.'.$key => array_replace([
        'enabled' => true,
        'url' => uc016CatalogUrl($key),
        'min_interval_hours' => $key === 'jpx_tse' ? 168 : 24,
        'max_response_bytes' => 8 * 1024 * 1024,
        'user_agent' => $key === 'sec_exchange' ? 'SyntheticResearchTest test@example.invalid' : null,
    ], $overrides)]);
}

function uc016CatalogSec(array $rows = [], array $fields = ['cik', 'name', 'ticker', 'exchange']): string
{
    return json_encode(['fields' => $fields, 'data' => $rows], JSON_THROW_ON_ERROR);
}

function uc016CatalogXlsx(string $file = 'jpx-valid.xlsx'): string
{
    return file_get_contents(__DIR__.'/../Fixtures/ResearchAutomation/'.$file);
}

function uc016CatalogResponses(string $key, string $body, int $status = 200, array $headers = []): void
{
    uc016AutomationFakeHttp([uc016CatalogUrl($key) => Http::response($body, $status, $headers)]);
}

function uc016CatalogActiveMatches(): Collection
{
    return DB::table('research_draft_listing_matches')->whereNull('superseded_at')->get();
}

/** An explicit human-confirmed fixture, never a mocked resolution result. */
function uc016CatalogAlias(int $entryId, string $rawName, string $relationship = 'same_entity'): int
{
    $actor = User::factory()->create();
    $normalized = preg_replace('/\s+/u', ' ', trim(\Normalizer::normalize($rawName, \Normalizer::FORM_KC)));

    return DB::table('research_company_aliases')->insertGetId([
        'raw_name' => $rawName, 'normalized_name' => $normalized,
        'name_hash' => hash('sha256', $normalized), 'target_entry_id' => $entryId,
        'relationship' => $relationship, 'evidence_url' => 'https://example.invalid/official-relationship',
        'note' => 'Synthetic relationship explicitly checked by the test actor.',
        'confirmed_on' => now()->toDateString(), 'confirmed_by' => $actor->id,
        'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
