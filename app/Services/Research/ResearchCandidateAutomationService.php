<?php

namespace App\Services\Research;

use App\Models\ResearchAutomationRun;
use App\Models\ResearchCandidateDraft;
use App\Models\ResearchDocumentAttempt;
use App\Models\ResearchDocumentVersion;
use App\Models\ResearchDraftRevision;
use App\Models\ResearchSourceCheck;
use App\Models\ResearchSourceDocument;
use App\Models\ResearchSourceItem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Normalizer;
use RuntimeException;
use Throwable;

/** ADR-0033: bounded extraction of unverified drafts, never investment records. */
class ResearchCandidateAutomationService
{
    public function __construct(private ResearchListingCatalogService $catalog) {}

    public function run(string $trigger = 'manual', ?User $actor = null): array
    {
        $result = ['processed' => 0, 'failed' => 0, 'status' => 'skipped'];
        if (! config('research_automation.enabled', false)) {
            return $result;
        }
        if (! array_key_exists('S10', config('research_sources', [])) && ! ResearchCandidateDraft::exists()) {
            return $result;
        }
        $lock = Cache::store('database')->lock('research-source-check', 600);
        if (! $lock->get()) {
            return $result + ['running' => true];
        }
        $run = null;
        $started = now();
        $clock = hrtime(true);
        $remaining = fn (): float => 120 - max($started->diffInSeconds(now()), (hrtime(true) - $clock) / 1_000_000_000);
        try {
            // An empty application needs no catalog network traffic at startup.
            if ($trigger === 'startup' && ! ResearchSourceItem::where('source_key', 'S10')->exists()
                && ! ResearchCandidateDraft::exists()) {
                return $result;
            }
            ResearchAutomationRun::where('status', 'running')->update([
                'status' => 'interrupted', 'finished_at' => now(), 'error_code' => 'interrupted',
            ]);
            $run = ResearchAutomationRun::create([
                'trigger' => in_array($trigger, ['startup', 'manual', 'backfill'], true) ? $trigger : 'manual',
                'actor_id' => $actor?->id, 'started_at' => now(), 'status' => 'running', 'counters' => [],
            ]);
            $this->catalog->refresh($run->id, $remaining);
            $processed = 0;
            if (config('research_automation.sources.S10.enabled', false)) {
                foreach (ResearchSourceCheck::where('source_key', 'S10')->whereNotNull('automation_payload')->orderBy('id')->cursor() as $check) {
                    if ($processed >= 200 || $remaining() <= 0) {
                        break;
                    }
                    // Validation/storage failures roll back this batch. A deadline is
                    // a normal stop: commit only complete documents and their cursor.
                    $processed += DB::transaction(function () use ($check, $run, $processed, $remaining): int {
                        $current = ResearchSourceCheck::whereKey($check->id)->lockForUpdate()->firstOrFail();
                        $payload = $current->automation_payload;
                        if ($payload === null) {
                            return 0;
                        }
                        if (($payload['schema_version'] ?? null) !== 1 || ! is_array($payload['items'] ?? null)) {
                            throw new RuntimeException('automation_invalid_input');
                        }
                        $offset = $current->automation_cursor ?? 0;
                        $items = array_slice($payload['items'], $offset, 200 - $processed);
                        $consumed = 0;
                        foreach ($items as $input) {
                            if ($remaining() <= 0) {
                                break;
                            }
                            $item = ResearchSourceItem::whereKey($input['source_item_id'])->firstOrFail();
                            if ($item->source_key !== 'S10') {
                                throw new RuntimeException('automation_invalid_input');
                            }
                            if ($item->disposition === 'unreviewed') {
                                $this->extract($item, $input, $current, $run->id, $input['discovery_kind'] ?? 'backfill');
                            }
                            $consumed++;
                        }
                        $next = $offset + $consumed;
                        $complete = $next >= count($payload['items']);
                        $current->update($complete
                            ? ['automation_cursor' => null, 'automation_payload' => null]
                            : ['automation_cursor' => $next]);

                        return $consumed;
                    });
                }
                if ($processed < 200 && $remaining() > 0) {
                    $items = ResearchSourceItem::where('source_key', 'S10')->where('disposition', 'unreviewed')
                        ->whereNotIn('id', ResearchSourceDocument::select('source_item_id'))
                        ->orderBy('id')->limit(200 - $processed)->get();
                    $processed += DB::transaction(function () use ($items, $run, $remaining): int {
                        $consumed = 0;
                        foreach ($items as $item) {
                            if ($remaining() <= 0) {
                                break;
                            }
                            $this->extract($item, [
                                'url' => $item->url, 'title' => $item->title, 'metadata' => $item->metadata,
                            ], $item->firstCheck, $run->id, 'backfill');
                            $consumed++;
                        }

                        return $consumed;
                    });
                }
            }
            $matchingComplete = $this->catalog->matchDrafts($remaining);
            $pending = config('research_automation.sources.S10.enabled', false)
                && (ResearchSourceCheck::where('source_key', 'S10')->whereNotNull('automation_payload')->exists()
                    || ResearchSourceItem::where('source_key', 'S10')->where('disposition', 'unreviewed')
                        ->whereNotIn('id', ResearchSourceDocument::select('source_item_id'))->exists());
            $result = ['processed' => $processed, 'failed' => 0, 'status' => $pending || ! $matchingComplete ? 'partial' : 'completed'];
            $run->update(['finished_at' => now(), 'status' => $result['status'], 'counters' => $result]);

            return $result;
        } catch (Throwable $exception) {
            if ($run) {
                $run->update([
                    'finished_at' => now(), 'status' => 'failed',
                    'error_code' => 'automation_extract_failed', 'counters' => ['failed' => 1],
                ]);
            }

            return ['processed' => 0, 'failed' => 1, 'status' => 'failed'];
        } finally {
            $lock->release();
        }
    }

    private function extract(ResearchSourceItem $item, array $input, ?ResearchSourceCheck $check, int $runId, string $kind): void
    {
        $fields = $input['metadata'] ?? [];
        $rawName = $fields['company'] ?? '';
        if (! is_string($rawName) || trim($rawName) === '' || mb_strlen($rawName) > 255) {
            throw new RuntimeException('automation_invalid_company');
        }
        foreach (['company', 'device_name', 'submission_number', 'date_of_final_decision', 'review_panel', 'product_code'] as $field) {
            if (! isset($fields[$field]) || ! is_string($fields[$field]) || trim($fields[$field]) === '') {
                throw new RuntimeException('automation_invalid_fields');
            }
        }
        $name = preg_replace('/\s+/u', ' ', trim(Normalizer::normalize($rawName, Normalizer::FORM_KC)));
        $key = hash('sha256', $name);
        $summary = 'FDA医療AI一覧掲載: '.$rawName.' / '.$fields['device_name'].' / '.$fields['submission_number']
            .' / 決定日 '.$fields['date_of_final_decision'].'（公開日不明）';
        if (mb_strlen($summary) > 2000) {
            throw new RuntimeException('automation_payload_limit');
        }
        $extracted = [
            'roles' => ['一覧掲載企業'], 'themes' => ['医療AI'],
            'classification_reason' => 'FDAの医療AI機器一覧への掲載に基づく分類', 'generated_summary' => $summary,
        ];
        $payload = [
            'schema_version' => 1, 'source_url' => $input['url'], 'source_title' => $input['title'],
            'publisher' => config('research_sources.S10.name', 'FDA'),
            'published_at' => null, 'announced_on' => null, 'updated_on' => null,
            'source_fields' => $fields,
            'mentions' => [[
                'raw_name' => $rawName, 'normalized_name' => $name, 'mention_key' => $key,
                'roles' => $extracted['roles'], 'themes' => $extracted['themes'],
                'evidence_url' => $input['url'], 'excerpt' => $summary,
                'classification_reason' => $extracted['classification_reason'],
            ]],
            'extraction_result' => 'extracted',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 128 * 1024 || strlen(json_encode($extracted, JSON_THROW_ON_ERROR)) > 16 * 1024) {
            throw new RuntimeException('automation_payload_limit');
        }
        $document = ResearchSourceDocument::firstOrCreate(['source_item_id' => $item->id]);
        $document = ResearchSourceDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
        $latest = $document->latest_feed_version_id ? ResearchDocumentVersion::findOrFail($document->latest_feed_version_id) : null;
        $hash = hash('sha256', $json);
        $attempt = ResearchDocumentAttempt::create([
            'run_id' => $runId, 'document_id' => $document->id, 'phase' => 'feed_extract',
            'started_at' => now(), 'input_version_id' => $latest?->id,
        ]);
        if ($latest && $latest->payload_hash === $hash && $latest->parser_version === 'fda-csv-1' && $latest->rule_version === 'fda-medical-ai-1') {
            $attempt->update(['finished_at' => now(), 'status' => 'unchanged', 'output_version_id' => $latest->id]);
            $document->update(['last_seen_check_id' => $check?->id]);
            ResearchCandidateDraft::where('document_id', $document->id)->where('presence', 'present')->update(['last_observed_at' => now()]);

            return;
        }
        $version = ResearchDocumentVersion::create([
            'document_id' => $document->id, 'phase' => 'feed', 'version_no' => ($latest?->version_no ?? 0) + 1,
            'observed_check_id' => $check?->id, 'attempt_id' => $attempt->id,
            'payload' => $payload, 'payload_hash' => $hash,
            'parser_version' => 'fda-csv-1', 'rule_version' => 'fda-medical-ai-1',
            'observed_at' => $check?->finished_at ?? now(),
        ]);
        $draft = ResearchCandidateDraft::where('document_id', $document->id)->where('mention_key', $key)->lockForUpdate()->first();
        if ($draft && $draft->normalized_name !== $name) {
            throw new RuntimeException('automation_key_collision');
        }
        $new = $draft === null;
        $draft ??= new ResearchCandidateDraft([
            'document_id' => $document->id, 'mention_key' => $key, 'first_detected_at' => now(),
            'discovery_kind' => in_array($kind, ['new', 'baseline', 'backfill'], true) ? $kind : 'backfill',
            'disposition' => 'unreviewed', 'lock_version' => 0,
        ]);
        $draft->fill([
            'latest_version_id' => $version->id, 'current_document_version_id' => $version->id,
            'raw_name' => $rawName, 'normalized_name' => $name, 'extracted_fields' => $extracted,
            'presence' => 'present', 'last_observed_at' => now(), 'lock_version' => $draft->lock_version + 1,
        ])->save();
        $this->revision($draft, $new ? 'extracted' : 'source_changed');
        foreach (ResearchCandidateDraft::where('document_id', $document->id)->where('id', '!=', $draft->id)->lockForUpdate()->get() as $removed) {
            $removed->update([
                'presence' => 'removed', 'current_document_version_id' => $version->id,
                'lock_version' => $removed->lock_version + 1,
            ]);
            $this->revision($removed, 'source_removed');
        }
        $document->update([
            'latest_feed_version_id' => $version->id, 'extracted_version_id' => $version->id,
            'last_seen_check_id' => $check?->id, 'extraction_state' => 'extracted',
            'last_attempt_at' => now(), 'last_success_at' => now(), 'lock_version' => $document->lock_version + 1,
        ]);
        $attempt->update(['finished_at' => now(), 'status' => 'extracted', 'output_version_id' => $version->id]);
    }

    private function revision(ResearchCandidateDraft $draft, string $type): void
    {
        $payload = $draft->attributesToArray();
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 64 * 1024) {
            throw new RuntimeException('automation_revision_limit');
        }
        ResearchDraftRevision::create([
            'draft_id' => $draft->id, 'lock_version' => $draft->lock_version,
            'change_type' => $type, 'payload' => $payload, 'created_at' => now(),
        ]);
    }
}
