<?php

namespace App\Services\Research;

use App\Support\ResearchSymbolCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Official lookup snapshots are suggestions, never human-confirmed listings. */
class ResearchListingCatalogService
{
    private const URLS = [
        'jpx_tse' => 'https://www.jpx.co.jp/markets/statistics-equities/misc/tvdivq0000001vg2-att/data_j.xlsx',
        'sec_exchange' => 'https://www.sec.gov/files/company_tickers_exchange.json',
    ];

    public function __construct(private readonly ResearchListingCatalogParser $parser, private readonly ResearchSourceFetcher $fetcher) {}

    public function refresh(int $runId, ?callable $remaining = null): array
    {
        $remaining = $this->budget($remaining);
        foreach (self::URLS as $key => $officialUrl) {
            $settings = config('research_automation.catalogs.'.$key, []);
            if (! ($settings['enabled'] ?? false)) {
                continue;
            }
            $last = DB::table('research_listing_catalog_imports')->where('catalog_key', $key)->latest('id')->first();
            $interval = max($key === 'jpx_tse' ? 168 : 24, (int) ($settings['min_interval_hours'] ?? 0));
            if ($last && CarbonImmutable::parse($last->finished_at ?? $last->started_at, 'UTC')->addHours($interval)->isAfter(CarbonImmutable::now('UTC'))) {
                continue;
            }
            if ($remaining() <= 0) {
                break;
            }
            $importId = DB::table('research_listing_catalog_imports')->insertGetId([
                'run_id' => $runId, 'catalog_key' => $key, 'source_url' => $officialUrl,
                'started_at' => now(), 'status' => 'running', 'row_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            try {
                if (($settings['url'] ?? $officialUrl) !== $officialUrl) {
                    throw new RuntimeException('catalog_url_not_allowed');
                }
                $userAgent = trim((string) ($settings['user_agent'] ?? ''));
                if ($key === 'sec_exchange' && ($userAgent === '' || str_contains($userAgent, "\n") || str_contains($userAgent, "\r"))) {
                    $this->finishFailure($importId, 'catalog_user_agent_missing', 'blocked');

                    continue;
                }
                $this->waitForHost($key, $importId, $remaining);
                $seconds = $remaining();
                if ($seconds <= 0) {
                    throw new RuntimeException('catalog_time_limit');
                }
                $limit = min(8 * 1024 * 1024, max(1, (int) ($settings['max_response_bytes'] ?? 8 * 1024 * 1024)));
                $response = Http::timeout(min(15, $seconds))->connectTimeout(min(15, $seconds))->withoutRedirecting()
                    ->withHeaders([
                        'User-Agent' => $userAgent !== '' ? $userAgent : 'StockAutoOrder-Research/1.0',
                        'Accept' => $key === 'jpx_tse' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/json',
                    ])->withOptions([
                        'stream' => true, 'read_timeout' => min(15, $seconds),
                        'on_headers' => static function (ResponseInterface $response) use ($limit): void {
                            if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                                throw new RuntimeException('catalog_response_limit');
                            }
                        },
                    ])->get($officialUrl);
                if (! $response->successful()) {
                    $response->toPsrResponse()->getBody()->close();
                    throw new RuntimeException('catalog_http_failed');
                }
                $body = $this->fetcher->read($response, $limit, hrtime(true) / 1_000_000_000 + min(15, max(0, $remaining())));
                $snapshot = $this->parser->parse($key, $body, $officialUrl);
                DB::transaction(function () use ($key, $importId, $snapshot, $body, $remaining): void {
                    $previous = DB::table('research_listing_catalog_imports')->where('catalog_key', $key)
                        ->where('status', 'published')->latest('id')->first();
                    if ($key === 'jpx_tse' && $previous?->as_of_on !== null && $snapshot['as_of_on'] < $previous->as_of_on) {
                        throw new RuntimeException('catalog_date_rollback');
                    }
                    $entries = array_map(fn (array $entry) => array_merge($entry, [
                        'import_id' => $importId, 'created_at' => now(), 'updated_at' => now(),
                    ]), $snapshot['entries']);
                    foreach (array_chunk($entries, 250) as $chunk) {
                        if ($remaining() <= 0) {
                            throw new RuntimeException('catalog_time_limit');
                        }
                        DB::table('research_listing_catalog_entries')->insert($chunk);
                    }
                    if ($remaining() <= 0) {
                        throw new RuntimeException('catalog_time_limit');
                    }
                    DB::table('research_listing_catalog_imports')->where('id', $importId)->update([
                        'status' => 'published', 'finished_at' => now(), 'published_at' => now(),
                        'as_of_on' => $snapshot['as_of_on'], 'content_hash' => hash('sha256', $body),
                        'row_count' => count($entries), 'updated_at' => now(),
                    ]);
                }, 3);
            } catch (Throwable $error) {
                // Only fixed codes are persisted; transport bodies/UA never enter the log or DB.
                $code = $error instanceof RuntimeException && preg_match('/^catalog_[a-z_]+$/D', $error->getMessage())
                    ? $error->getMessage() : 'catalog_import_failed';
                $this->finishFailure($importId, $code);
            }
        }

        return $this->snapshotIds();
    }

    public function snapshotIds(): array
    {
        $ids = [];
        foreach (array_keys(self::URLS) as $key) {
            $id = DB::table('research_listing_catalog_imports')->where('catalog_key', $key)->where('status', 'published')->max('id');
            $ids[$key] = $id === null ? null : (int) $id;
        }

        return $ids;
    }

    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->snapshotIds() as $key => $id) {
            $latest = DB::table('research_listing_catalog_imports')->where('catalog_key', $key)->latest('id')->first();
            $snapshot = $id === null ? null : DB::table('research_listing_catalog_imports')->find($id);
            $code = null;
            if ($latest && $latest->status !== 'published') {
                $code = $latest->status === 'blocked' ? 'blocked' : 'failed';
            } elseif ($snapshot === null) {
                $code = 'missing';
            } elseif ($key === 'jpx_tse'
                ? CarbonImmutable::parse($snapshot->as_of_on, 'UTC')->addDays(45)->isBefore(CarbonImmutable::now('UTC')->startOfDay())
                : CarbonImmutable::parse($snapshot->finished_at, 'UTC')->addDays(7)->isBefore(CarbonImmutable::now('UTC'))) {
                $code = 'stale';
            }
            if ($code !== null) {
                $warnings[] = ['catalog_key' => $key, 'code' => $code, 'message' => match ($code) {
                    'stale' => '照合元が古い',
                    'failed' => '照合元の取得失敗',
                    'blocked' => '照合元の取得設定が不足しています',
                    default => '公式上場情報が未取得です',
                }];
            }
        }

        return $warnings;
    }

    public function matchDrafts(?callable $remaining = null): bool
    {
        $remaining = $this->budget($remaining);
        if ($remaining() <= 0) {
            return false;
        }
        $snapshots = $this->snapshotIds();
        $unavailable = array_column($this->warnings(), 'catalog_key');
        $activeIds = array_values(array_filter($snapshots, fn ($id, $key) => $id !== null && ! in_array($key, $unavailable, true), ARRAY_FILTER_USE_BOTH));
        $entries = DB::table('research_listing_catalog_entries')->whereIn('import_id', $activeIds)->get()->keyBy('id');
        $activeAliases = DB::table('research_company_aliases')->whereNull('revoked_at')->orderBy('id')->get();
        $oldTargets = DB::table('research_listing_catalog_entries')->whereIn('id', $activeAliases->pluck('target_entry_id'))->get()->keyBy('id');
        $warnings = $this->warnings();
        $completed = true;
        DB::table('research_candidate_drafts')->orderBy('id')->chunkById(100, function ($drafts) use ($snapshots, $entries, $activeAliases, $oldTargets, $warnings, $remaining, &$completed): ?bool {
            foreach ($drafts as $draft) {
                if ($remaining() <= 0) {
                    $completed = false;

                    return false;
                }
                $overrides = json_decode($draft->human_overrides ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                $name = ResearchListingCatalogParser::normalizeName($overrides['raw_name'] ?? $draft->raw_name);
                $aliases = $activeAliases->filter(fn ($alias) => $alias->normalized_name === $name)->values();
                $inputHash = hash('sha256', json_encode([
                    $draft->current_document_version_id, $draft->presence, $name, $snapshots,
                    array_map(fn ($warning) => [$warning['catalog_key'], $warning['code']], $warnings),
                    $aliases->map(fn ($alias) => [$alias->id, $alias->target_entry_id, $alias->relationship])->all(),
                ], JSON_THROW_ON_ERROR));
                if ($draft->match_input_hash === $inputHash) {
                    continue;
                }
                $matches = [];
                if ($draft->presence === 'present') {
                    foreach ($entries as $entry) {
                        if ($this->eligible($entry) && $entry->normalized_name === $name) {
                            $matches[$entry->id.':exact_name'] = [$entry, 'exact_name', null, '公式上場情報との厳密な名称一致（本人未確認）'];
                        }
                    }
                    foreach ($aliases as $alias) {
                        $target = $oldTargets->get($alias->target_entry_id);
                        if (! $target) {
                            continue;
                        }
                        foreach ($entries as $entry) {
                            // JPX issuer key includes its as-of date/name; do not treat a reused code as an entity ID.
                            if ($this->eligible($entry) && $entry->market === $target->market
                                && ($entry->market === 'us' ? $entry->issuer_key === $target->issuer_key
                                    : ($entry->symbol_code === $target->symbol_code && $entry->normalized_name === $target->normalized_name))) {
                                $matches[$entry->id.':verified_alias'] ??= [$entry, 'verified_alias', $alias->id,
                                    '本人確認済みの名称対応（'.$alias->relationship.'）。上場主体は本人確認待ち'];
                            }
                        }
                    }
                }
                DB::transaction(function () use ($draft, $inputHash, $matches): void {
                    DB::table('research_source_documents')->where('id', $draft->document_id)->lockForUpdate()->first();
                    $current = DB::table('research_candidate_drafts')->where('id', $draft->id)->lockForUpdate()->first();
                    if ($current->match_input_hash === $inputHash || $current->lock_version !== $draft->lock_version
                        || $current->current_document_version_id !== $draft->current_document_version_id) {
                        return;
                    }
                    $generation = $current->match_generation + 1;
                    DB::table('research_draft_listing_matches')->where('draft_id', $draft->id)->whereNull('superseded_at')->update([
                        'superseded_at' => now(), 'updated_at' => now(),
                    ]);
                    foreach ($matches as [$entry, $method, $aliasId, $reason]) {
                        DB::table('research_draft_listing_matches')->insert([
                            'draft_id' => $draft->id, 'entry_id' => $entry->id, 'generation' => $generation,
                            'alias_id' => $aliasId, 'match_method' => $method, 'reason' => $reason,
                            'matched_at' => now(), 'superseded_at' => null, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    DB::table('research_candidate_drafts')->where('id', $draft->id)->update([
                        'match_generation' => $generation, 'match_input_hash' => $inputHash, 'updated_at' => now(),
                    ]);
                }, 3);
            }

            return null;
        });

        return $completed;
    }

    private function budget(?callable $remaining): callable
    {
        if ($remaining !== null) {
            return $remaining;
        }
        $started = CarbonImmutable::now();
        $clock = hrtime(true);

        return fn (): float => 120 - max($started->diffInSeconds(CarbonImmutable::now()), (hrtime(true) - $clock) / 1_000_000_000);
    }

    private function eligible(object $entry): bool
    {
        return $entry->market === 'jp'
            ? ResearchSymbolCode::normalize('jp', $entry->symbol_code) !== null
            : in_array($entry->exchange, ['Nasdaq', 'NYSE', 'NYSE American', 'NYSE Arca', 'Cboe', 'BATS'], true);
    }

    private function finishFailure(int $id, string $code, string $status = 'failed'): void
    {
        DB::table('research_listing_catalog_imports')->where('id', $id)->update([
            'status' => $status, 'finished_at' => now(), 'row_count' => 0, 'error_code' => $code, 'updated_at' => now(),
        ]);
    }

    private function waitForHost(string $key, int $currentId, callable $remaining): void
    {
        $previous = DB::table('research_listing_catalog_imports')->where('catalog_key', $key)->where('id', '<', $currentId)
            ->whereNotNull('finished_at')->where('status', '!=', 'blocked')->latest('id')->first();
        if (! $previous) {
            return;
        }
        // DB timestamps have second precision; add one second beyond the 1.5 second minimum.
        $next = CarbonImmutable::parse($previous->finished_at, 'UTC')->addMilliseconds(2500);
        $wait = max(0, $next->getTimestampMs() - CarbonImmutable::now('UTC')->getTimestampMs());
        if ($wait / 1000 >= $remaining()) {
            throw new RuntimeException('catalog_time_limit');
        }
        if ($wait > 0) {
            Sleep::for((int) ceil($wait))->milliseconds();
        }
    }
}
