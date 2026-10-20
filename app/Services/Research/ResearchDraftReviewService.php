<?php

namespace App\Services\Research;

use App\Models\ResearchCandidate;
use App\Models\ResearchCandidateRevision;
use App\Models\ResearchDiscoveryLink;
use App\Models\ResearchEntity;
use App\Models\ResearchEntityListing;
use App\Models\ResearchEvent;
use App\Models\User;
use App\Support\DisplayTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

/** ADR-0033: human review of immutable source versions; never performs HTTP. */
class ResearchDraftReviewService
{
    public function review(int $draftId, User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', ResearchCandidate::class);
        $draft = DB::table('research_candidate_drafts')->find($draftId);
        abort_if($draft === null, 404);
        $version = DB::table('research_document_versions')->where('document_id', $draft->document_id)
            ->find($draft->current_document_version_id);
        abort_if($version === null, 404);
        $candidate = $draft->imported_candidate_id ? ResearchCandidate::findOrFail($draft->imported_candidate_id) : null;

        return [
            'id' => $draft->id, 'lock_version' => $draft->lock_version,
            'document_version_id' => $version->id,
            'catalog_import_ids' => $this->catalogIds(),
            'match_generation' => $draft->match_generation, 'match_input_hash' => $draft->match_input_hash,
            'imported_candidate_lock_version' => $candidate?->lock_version,
            'draft' => (array) $draft,
            'fields' => json_decode($draft->extracted_fields, true, flags: JSON_THROW_ON_ERROR),
            'overrides' => $draft->human_overrides ? json_decode($draft->human_overrides, true, flags: JSON_THROW_ON_ERROR) : [],
            'source' => json_decode($version->payload, true, flags: JSON_THROW_ON_ERROR),
            'existing_events' => ResearchEvent::query()->whereNull('merged_into_event_id')
                ->orderByDesc('id')->limit(200)->get(['id', 'title', 'announced_on'])->toArray(),
            'existing_entities' => ResearchEntity::query()->orderByDesc('id')->limit(200)->get(['id', 'legal_name'])->toArray(),
            'matches' => DB::table('research_draft_listing_matches as matches')
                ->join('research_listing_catalog_entries as entries', 'entries.id', '=', 'matches.entry_id')
                ->where('matches.draft_id', $draftId)->whereNull('matches.superseded_at')
                ->select('entries.*', 'matches.reason', 'matches.match_method', 'matches.alias_id')->get()->map(fn ($entry) => (array) $entry)->all(),
            'warnings' => app(ResearchListingCatalogService::class)->warnings(),
            'history' => DB::table('research_draft_revisions')->where('draft_id', $draftId)->orderBy('lock_version')->get()->map(fn ($revision) => (array) $revision)->all(),
        ];
    }

    public function import(array $review, bool $confirmed, string $note, ?int $eventId, ?int $entityId, User $actor): ResearchCandidate
    {
        Gate::forUser($actor)->authorize('create', ResearchCandidate::class);
        if (! $confirmed) {
            throw new InvalidArgumentException('元資料との照合を確認してください。');
        }
        Validator::make(['note' => $note], ['note' => ['string', 'max:2000']])->validate();

        return DB::transaction(function () use ($review, $note, $eventId, $entityId, $actor) {
            $draft = $this->lockedDraft((int) ($review['id'] ?? 0));
            // A repeat of the same successful operation returns its durable result.
            if ($draft->imported_candidate_id !== null) {
                $candidate = ResearchCandidate::findOrFail($draft->imported_candidate_id);
                Gate::forUser($actor)->authorize('update', $candidate);

                return $candidate;
            }
            $this->assertReview($draft, $review);
            if ($draft->presence !== 'present' || in_array($draft->disposition, ['on_hold', 'rejected', 'out_of_scope'], true)) {
                throw new InvalidArgumentException('最新資料と候補の状態を確認してください。');
            }
            $version = DB::table('research_document_versions')->where('document_id', $draft->document_id)
                ->find($draft->current_document_version_id);
            $source = json_decode($version->payload, true, flags: JSON_THROW_ON_ERROR);
            $fields = json_decode($draft->extracted_fields, true, flags: JSON_THROW_ON_ERROR);
            $overrides = $draft->human_overrides ? json_decode($draft->human_overrides, true, flags: JSON_THROW_ON_ERROR) : [];
            $effective = array_replace($fields, $overrides);
            $event = $eventId ? ResearchEvent::lockForUpdate()->findOrFail($eventId) : ResearchEvent::create([
                'title' => $source['source_title'], 'verification_status' => 'unverified',
            ]);
            if ($event->merged_into_event_id !== null) {
                throw new InvalidArgumentException('統合済みの元発表です。統合先を選択してください。');
            }
            $entity = $entityId ? ResearchEntity::lockForUpdate()->findOrFail($entityId)
                : ResearchEntity::create(['legal_name' => $draft->raw_name, 'identification_status' => 'unidentified']);
            $link = ResearchDiscoveryLink::firstOrCreate(
                ['research_event_id' => $event->id, 'url_hash' => hash('sha256', $source['source_url'])],
                ['route_type' => 'manual', 'input_origin' => 'automatic', 'url' => $source['source_url'],
                    'source_title' => $source['source_title'], 'publisher' => $source['publisher'],
                    'posted_on' => isset($source['published_at']) ? substr($source['published_at'], 0, 10) : null,
                    'checked_on' => DisplayTime::date(now()), 'summary' => $note,
                    'generated_summary' => $this->summary($fields, $source), 'source_document_version_id' => $version->id],
            );
            $candidate = ResearchCandidate::query()->where('research_event_id', $event->id)
                ->where('research_entity_id', $entity->id)->lockForUpdate()->first();
            if ($candidate === null) {
                $candidate = ResearchCandidate::create([
                    'research_event_id' => $event->id, 'research_entity_id' => $entity->id,
                    'theme' => ($effective['themes'][0] ?? '未分類'),
                    'theme_relation' => $this->summary($effective, $source),
                    'entity_role' => implode('、', $effective['roles'] ?? []),
                ])->refresh();
                $this->candidateRevision($candidate, 'created');
            } elseif ($link->wasRecentlyCreated) {
                Gate::forUser($actor)->authorize('update', $candidate);
                $candidate->lock_version++;
                $candidate->save();
                $this->candidateRevision($candidate, 'updated');
            }
            if ($link->wasRecentlyCreated && ! $event->wasRecentlyCreated) {
                foreach ($event->candidates()->whereKeyNot($candidate->id)->orderBy('id')->lockForUpdate()->get() as $related) {
                    Gate::forUser($actor)->authorize('update', $related);
                    $related->lock_version++;
                    if ($related->status === 'watchlisted') {
                        $related->needs_recheck = true;
                    }
                    $related->save();
                    $this->candidateRevision($related, 'updated');
                }
            }
            $this->saveDraft($draft, [
                'disposition' => 'imported', 'imported_candidate_id' => $candidate->id,
                'imported_link_id' => $link->id, 'imported_version_id' => $version->id,
                'reviewed_version_id' => $version->id, 'reviewed_on' => DisplayTime::date(now()), 'reviewed_by' => $actor->id,
            ], 'imported', $actor, true);

            return $candidate;
        }, 3);
    }

    public function reconfirm(array $review, bool $confirmed, User $actor): void
    {
        Gate::forUser($actor)->authorize('create', ResearchCandidate::class);
        if (! $confirmed) {
            throw new InvalidArgumentException('元資料との照合を確認してください。');
        }
        DB::transaction(function () use ($review, $actor) {
            $draft = $this->lockedDraft((int) ($review['id'] ?? 0));
            $this->assertReview($draft, $review);
            if ($draft->imported_candidate_id === null) {
                throw new InvalidArgumentException('調査記録への取り込みが必要です。');
            }
            $candidate = ResearchCandidate::lockForUpdate()->findOrFail($draft->imported_candidate_id);
            Gate::forUser($actor)->authorize('update', $candidate);
            if ($candidate->lock_version !== ($review['imported_candidate_lock_version'] ?? null)) {
                throw new RuntimeException('調査記録の最新版を確認してください。');
            }
            $candidate->lock_version++;
            $candidate->save();
            $this->saveDraft($draft, [
                'reviewed_version_id' => $draft->current_document_version_id,
                'reviewed_on' => DisplayTime::date(now()), 'reviewed_by' => $actor->id,
            ], 'corrected', $actor, true);
            $this->candidateRevision($candidate, 'updated');
        }, 3);
    }

    public function correct(int $draftId, int $expectedVersion, array $overrides, User $actor): void
    {
        Gate::forUser($actor)->authorize('create', ResearchCandidate::class);
        Validator::make($overrides, [
            'roles' => ['sometimes', 'array', 'max:50'], 'roles.*' => ['string', 'max:255'],
            'themes' => ['sometimes', 'array', 'max:50'], 'themes.*' => ['string', 'max:100'],
            'theme_relation' => ['sometimes', 'string', 'max:2000'],
            'generated_summary' => ['sometimes', 'string', 'max:2000'],
        ])->validate();
        if (array_diff(array_keys($overrides), ['roles', 'themes', 'theme_relation', 'generated_summary']) !== []
            || strlen(json_encode($overrides, JSON_THROW_ON_ERROR)) > 16384) {
            throw new InvalidArgumentException('訂正項目または訂正内容の長さを確認してください。');
        }
        $this->mutate($draftId, $expectedVersion, ['human_overrides' => $overrides], 'corrected', $actor);
    }

    public function disposition(int $draftId, int $expectedVersion, string $disposition, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('create', ResearchCandidate::class);
        Validator::make(compact('disposition', 'reason'), [
            'disposition' => ['required', 'in:reviewing,on_hold,rejected,out_of_scope'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        $this->mutate($draftId, $expectedVersion, compact('disposition', 'reason'), 'disposition_changed', $actor);
    }

    public function restore(int $draftId, int $expectedVersion, User $actor): void
    {
        Gate::forUser($actor)->authorize('create', ResearchCandidate::class);
        $this->mutate($draftId, $expectedVersion, ['disposition' => 'unreviewed', 'reason' => null], 'restored', $actor);
    }

    public function listing(User $actor, string $disposition = ''): array
    {
        Gate::forUser($actor)->authorize('viewAny', ResearchCandidate::class);
        $drafts = DB::table('research_candidate_drafts')
            ->when($disposition !== '', fn ($query) => $query->where('disposition', $disposition))
            ->when($disposition === '', fn ($query) => $query->whereNotIn('disposition', ['on_hold', 'rejected', 'out_of_scope']))
            ->orderByDesc('first_detected_at')->orderByDesc('id')->paginate(20, ['*'], 'draftsPage');
        $documents = DB::table('research_source_documents as documents')
            ->join('research_source_items as items', 'items.id', '=', 'documents.source_item_id')
            ->whereIn('documents.extraction_state', ['pending', 'no_company', 'unsupported', 'failed'])
            ->orderByDesc('documents.id')->limit(20)->select('documents.*', 'items.title', 'items.url', 'items.source_key')->get();

        return ['drafts' => $drafts, 'documents' => $documents,
            'warnings' => app(ResearchListingCatalogService::class)->warnings(),
            'draft_count' => DB::table('research_candidate_drafts')->where('presence', 'present')->count(),
            'document_count' => DB::table('research_source_documents')->count()];
    }

    /** Lock source state before the caller locks the candidate, in its transaction. */
    public function lockHandoffSources(int $candidateId): Collection
    {
        $documentIds = DB::table('research_candidate_drafts')->where('imported_candidate_id', $candidateId)
            ->distinct()->orderBy('document_id')->pluck('document_id');
        DB::table('research_source_documents')->whereIn('id', $documentIds)
            ->orderBy('id')->lockForUpdate()->get();

        // Locking reads see the latest committed version even if the discovery
        // query above established an older MySQL repeatable-read snapshot.
        return DB::table('research_candidate_drafts')->where('imported_candidate_id', $candidateId)
            ->orderBy('id')->lockForUpdate()->get();
    }

    /** Revalidate only the automated provenance; old manual records keep their rules. */
    public function handoffError(ResearchCandidate $candidate, ?ResearchEntityListing $selectedListing = null, ?Collection $lockedDrafts = null): ?string
    {
        $drafts = $lockedDrafts ?? DB::table('research_candidate_drafts')->where('imported_candidate_id', $candidate->id)->get();
        if ($drafts->isEmpty()) {
            return null;
        }
        $selectedListing ??= $candidate->entity->listings->first();
        $catalog = app(ResearchListingCatalogService::class);
        $snapshots = $catalog->snapshotIds();
        $warnings = collect($catalog->warnings())->keyBy('catalog_key');
        foreach ($drafts as $draft) {
            if ($draft->reviewed_version_id === null || $draft->reviewed_version_id !== $draft->current_document_version_id) {
                return '元資料が更新されています。最新資料と調査記録を本人が再確認してください。';
            }
            $revision = DB::table('research_draft_revisions')->where('draft_id', $draft->id)
                ->whereNotNull('payload->reviewed_catalog_import_ids')->orderByDesc('lock_version')->first();
            $review = $revision ? json_decode($revision->payload, true, flags: JSON_THROW_ON_ERROR) : [];
            $reviewedIds = $review['reviewed_catalog_import_ids'] ?? [];
            foreach ($selectedListing === null ? [] : [$selectedListing] as $listing) {
                $key = $listing->market === 'jp' ? 'jpx_tse' : 'sec_exchange';
                $snapshotId = $snapshots[$key] ?? null;
                if ($snapshotId === null || $warnings->has($key)) {
                    return '上場照合元の欠測・取得失敗・鮮度を確認してから再確認してください。';
                }
                if (! in_array($snapshotId, $reviewedIds, true)) {
                    return '公式上場情報が更新されています。最新の上場主体を本人が再確認してください。';
                }
                if (! DB::table('research_listing_catalog_entries')->where('import_id', $snapshotId)
                    ->where('market', $listing->market)->where('symbol_code', $listing->symbol_code)->exists()) {
                    return '現在の公式上場情報で銘柄を確認できません。上場主体を再確認してください。';
                }
            }
            foreach ($review['reviewed_matches'] ?? [] as $match) {
                if ($match['alias_id'] !== null && DB::table('research_company_aliases')
                    ->where('id', $match['alias_id'])->whereNotNull('revoked_at')->exists()) {
                    return '別名対応の確認が取り消されています。上場主体を再確認してください。';
                }
                $entry = DB::table('research_listing_catalog_entries')->find($match['entry_id']);
                if ($entry !== null && $entry->market !== $selectedListing?->market) {
                    continue;
                }
                if ($entry === null || ! DB::table('research_listing_catalog_entries')->whereIn('import_id', array_filter($snapshots))
                    ->where('market', $entry->market)->where('issuer_key', $entry->issuer_key)->exists()) {
                    return '現在の公式上場情報で発行体を確認できません。上場主体を再確認してください。';
                }
            }
        }

        return null;
    }

    private function mutate(int $draftId, int $expectedVersion, array $changes, string $type, User $actor): void
    {
        DB::transaction(function () use ($draftId, $expectedVersion, $changes, $type, $actor) {
            $draft = $this->lockedDraft($draftId);
            if ($draft->lock_version !== $expectedVersion) {
                throw new RuntimeException('最新版を確認してから操作してください。');
            }
            $this->saveDraft($draft, $changes, $type, $actor);
        }, 3);
    }

    private function lockedDraft(int $draftId): object
    {
        $documentId = DB::table('research_candidate_drafts')->where('id', $draftId)->value('document_id');
        abort_if($documentId === null, 404);
        DB::table('research_source_documents')->where('id', $documentId)->lockForUpdate()->firstOrFail();

        return DB::table('research_candidate_drafts')->where('id', $draftId)->lockForUpdate()->firstOrFail();
    }

    private function assertReview(object $draft, array $review): void
    {
        if ($draft->lock_version !== ($review['lock_version'] ?? null)
            || $draft->current_document_version_id !== ($review['document_version_id'] ?? null)
            || $draft->match_generation !== ($review['match_generation'] ?? null)
            || $draft->match_input_hash !== ($review['match_input_hash'] ?? null)
            || $this->catalogIds() !== ($review['catalog_import_ids'] ?? null)) {
            throw new RuntimeException('資料・照合元または下書きが更新されています。最新版を確認してください。');
        }
        $aliases = DB::table('research_draft_listing_matches')->where('draft_id', $draft->id)
            ->whereNull('superseded_at')->whereNotNull('alias_id')->pluck('alias_id');
        if ($aliases->isNotEmpty() && DB::table('research_company_aliases')->whereIn('id', $aliases)->whereNotNull('revoked_at')->exists()) {
            throw new RuntimeException('別名の確認が取り消されています。照合し直してください。');
        }
    }

    private function catalogIds(): array
    {
        return array_values(array_filter(app(ResearchListingCatalogService::class)->snapshotIds(), fn ($id) => $id !== null));
    }

    private function saveDraft(object $draft, array $changes, string $type, User $actor, bool $reviewRecorded = false): void
    {
        $before = (array) $draft;
        $after = [...$before, ...$changes, 'lock_version' => $draft->lock_version + 1];
        $reviewedMatches = $reviewRecorded ? DB::table('research_draft_listing_matches')
            ->where('draft_id', $draft->id)->whereNull('superseded_at')
            ->get(['entry_id', 'alias_id', 'match_method'])->map(fn ($match) => (array) $match)->all() : [];
        $payload = json_encode(['before' => $before, 'after' => $after,
            'catalog_import_ids' => $this->catalogIds(),
            'reviewed_catalog_import_ids' => $reviewRecorded ? $this->catalogIds() : null,
            'reviewed_matches' => $reviewedMatches], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($payload) > 65536) {
            throw new InvalidArgumentException('変更履歴の上限を超えています。');
        }
        $stored = $changes;
        if (isset($stored['human_overrides'])) {
            $stored['human_overrides'] = json_encode($stored['human_overrides'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        DB::table('research_candidate_drafts')->where('id', $draft->id)
            ->update([...$stored, 'lock_version' => $after['lock_version'], 'updated_at' => now()]);
        DB::table('research_draft_revisions')->insert([
            'draft_id' => $draft->id, 'lock_version' => $after['lock_version'], 'change_type' => $type,
            'actor_id' => $actor->id, 'payload' => $payload, 'created_at' => now(),
        ]);
        Log::channel('audit')->info('research_draft.'.$type, [
            'action' => 'research_draft.'.$type, 'actor_id' => $actor->id,
            'subject_type' => 'research_candidate_drafts', 'subject_id' => $draft->id,
        ]);
    }

    private function summary(array $fields, array $source): string
    {
        $text = $fields['generated_summary'] ?? $fields['summary'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return $text;
        }
        $values = array_filter($source['source_fields'] ?? [], fn ($value) => is_scalar($value));

        return mb_substr(implode(' / ', array_map('strval', $values)), 0, 2000);
    }

    private function candidateRevision(ResearchCandidate $candidate, string $type): void
    {
        $candidate->load(['event.discoveryLinks', 'entity.listings', 'claims']);
        ResearchCandidateRevision::create([
            'research_candidate_id' => $candidate->id, 'lock_version' => $candidate->lock_version, 'change_type' => $type,
            'payload' => ['candidate' => $candidate->getAttributes(), 'event' => $candidate->event->getAttributes(),
                'discovery_links' => $candidate->event->discoveryLinks->map->getAttributes()->all(),
                'entity' => $candidate->entity->getAttributes(), 'entity_listings' => $candidate->entity->listings->map->getAttributes()->all(),
                'claims' => $candidate->claims->map->getAttributes()->all()],
        ]);
    }
}
