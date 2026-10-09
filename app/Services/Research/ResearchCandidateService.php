<?php

namespace App\Services\Research;

use App\Models\FavoriteCsvImportState;
use App\Models\Holding;
use App\Models\ResearchCandidate;
use App\Models\ResearchCandidateRevision;
use App\Models\ResearchClaim;
use App\Models\ResearchDiscoveryLink;
use App\Models\ResearchEntity;
use App\Models\ResearchEntityListing;
use App\Models\ResearchEvent;
use App\Models\ResearchWatchlistHandoff;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WatchlistItem;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** UC-016 research records and the explicit UC-012 handoff. No URL is fetched. */
class ResearchCandidateService
{
    public function create(array $form, User $actor): ResearchCandidate
    {
        Gate::forUser($actor)->authorize('create', ResearchCandidate::class);
        $this->assertDiscoveryInput($form);

        return DB::transaction(function () use ($form, $actor) {
            $event = ! empty($form['existing_event_id'])
                ? ResearchEvent::findOrFail($form['existing_event_id'])
                : ResearchEvent::create([
                    'title' => trim((string) ($form['event_title'] ?? '')) !== '' ? $form['event_title'] : $form['source_title'],
                    'original_url' => $this->blankToNull($form['original_url'] ?? null),
                    'original_publisher' => $this->blankToNull($form['original_publisher'] ?? null),
                    'announced_on' => $this->blankToNull($form['announced_on'] ?? null),
                    'verification_status' => 'unverified',
                ]);

            $entity = ! empty($form['existing_entity_id'])
                ? ResearchEntity::findOrFail($form['existing_entity_id'])
                : ResearchEntity::create(['legal_name' => $form['legal_name']]);

            $url = $form['discovery_url'];
            $discoveryLink = ResearchDiscoveryLink::firstOrCreate(
                ['research_event_id' => $event->id, 'url_hash' => hash('sha256', $url)],
                ['route_type' => 'manual', 'url' => $url, 'source_title' => $form['source_title'],
                    'publisher' => $form['publisher'], 'posted_on' => $form['posted_on'] ?? null,
                    'checked_on' => $form['checked_on'], 'summary' => $form['summary'],
                    'sponsorship_note' => $form['sponsorship_note'] ?? null],
            );

            $candidate = ResearchCandidate::firstOrCreate(
                ['research_event_id' => $event->id, 'research_entity_id' => $entity->id],
                ['theme' => $form['theme'], 'theme_relation' => $form['theme_relation'],
                    'entity_role' => $this->blankToNull($form['entity_role'] ?? null)],
            );
            if (! $candidate->revisions()->exists()) {
                // Eloquent does not hydrate database defaults on insert.
                $this->appendRevision($candidate->refresh(), 'created');
            } elseif ($discoveryLink->wasRecentlyCreated) {
                $candidate->increment('lock_version');
                $this->appendRevision($candidate->refresh(), 'updated');
            }
            if ($discoveryLink->wasRecentlyCreated && ! $event->wasRecentlyCreated) {
                foreach ($event->candidates()->whereKeyNot($candidate->id)->lockForUpdate()->get() as $related) {
                    Gate::forUser($actor)->authorize('update', $related);
                    if ($related->status === 'watchlisted') {
                        $related->needs_recheck = true;
                    }
                    $related->lock_version++;
                    $related->save();
                    $this->appendRevision($related, 'updated');
                }
            }

            return $candidate->refresh();
        });
    }

    public function update(int $candidateId, int $expectedVersion, array $form, User $actor): ResearchCandidate
    {
        $this->assertInputLimits($form);

        return DB::transaction(function () use ($candidateId, $expectedVersion, $form, $actor) {
            $candidate = ResearchCandidate::query()->lockForUpdate()->findOrFail($candidateId);
            Gate::forUser($actor)->authorize('update', $candidate);
            $this->assertVersion($candidate, $expectedVersion);

            foreach (['theme', 'theme_relation', 'entity_role', 'evidence_stage', 'counter_evidence', 'counter_evidence_checked_on', 'status_reason'] as $field) {
                if (array_key_exists($field, $form)) {
                    $candidate->{$field} = in_array($field, ['entity_role', 'counter_evidence', 'counter_evidence_checked_on', 'status_reason'], true)
                        ? $this->blankToNull($form[$field]) : $form[$field];
                }
            }
            if ($candidate->status === 'watchlisted' && $candidate->isDirty(['evidence_stage', 'counter_evidence', 'counter_evidence_checked_on', 'entity_role'])) {
                $candidate->needs_recheck = true;
            }
            $candidate->lock_version++;
            $candidate->save();

            $event = $candidate->event;
            $previousOriginalUrl = $event->original_url;
            if (array_key_exists('event_title', $form)) {
                $event->title = $form['event_title'];
            }
            foreach (['original_url', 'original_publisher', 'announced_on', 'verification_status', 'verified_on'] as $field) {
                if (array_key_exists($field, $form)) {
                    $event->{$field} = $field === 'verification_status' ? $form[$field] : $this->blankToNull($form[$field]);
                }
            }
            if ($previousOriginalUrl !== null && $event->original_url !== $previousOriginalUrl) {
                // The submitted confirmation belongs to the old original source.
                $event->verification_status = 'unverified';
                $event->verified_on = null;
            }
            if ($event->isDirty()) {
                if ($candidate->status === 'watchlisted') {
                    $candidate->update(['needs_recheck' => true]);
                }
                $event->save();
            }
            $eventChanged = $event->wasChanged();
            $entity = $candidate->entity;
            foreach (['legal_name', 'identification_status', 'identification_note'] as $field) {
                if (array_key_exists($field, $form)) {
                    $entity->{$field} = $field === 'identification_note' ? $this->blankToNull($form[$field]) : $form[$field];
                }
            }
            if ($entity->isDirty()) {
                if ($candidate->status === 'watchlisted') {
                    $candidate->update(['needs_recheck' => true]);
                }
                $entity->save();
            }
            $entityChanged = $entity->wasChanged();

            $link = $event->discoveryLinks()->orderBy('id')->first();
            if ($link !== null) {
                foreach (['source_title', 'publisher', 'checked_on', 'summary', 'sponsorship_note'] as $field) {
                    if (array_key_exists($field, $form)) {
                        $link->{$field} = $field === 'sponsorship_note' ? $this->blankToNull($form[$field]) : $form[$field];
                    }
                }
                if ($link->isDirty()) {
                    $link->save();
                }
            }
            $linkChanged = $link?->wasChanged() ?? false;

            $listingChanged = $this->updateFlatListing($entity, $form);
            $claimChanged = $this->updateFlatClaim($candidate, $form);

            if (isset($form['claims']) && is_array($form['claims'])) {
                $this->syncClaims($candidate, $form['claims']);
            }
            if (isset($form['listings']) && is_array($form['listings'])) {
                $this->syncListings($entity, $form['listings']);
            }
            if ($previousOriginalUrl !== null && (
                $event->original_url !== $previousOriginalUrl
                || ($event->verification_status === 'unavailable' && $event->wasChanged('verification_status'))
            )) {
                ResearchClaim::query()
                    ->whereHas('candidate', fn ($query) => $query->where('research_event_id', $event->id))
                    ->where('evidence_url', $previousOriginalUrl)
                    ->where('status', 'verified')
                    ->update(['status' => 'unverified', 'checked_on' => null]);
            }
            if ($candidate->status === 'watchlisted' && ($listingChanged || $claimChanged || isset($form['listings']) || isset($form['claims']))) {
                $candidate->needs_recheck = true;
                $candidate->save();
            }
            $this->appendRevision($candidate->refresh(), 'updated');

            // An event or entity may be shared by several candidates. Their displayed
            // evidence changes too, so invalidate any prepared handoff for each one.
            if ($eventChanged || $entityChanged || $linkChanged || $listingChanged || isset($form['listings'])) {
                $related = ResearchCandidate::query()->whereKeyNot($candidate->id)
                    ->where(function ($query) use ($candidate, $eventChanged, $entityChanged, $linkChanged, $listingChanged, $form) {
                        if ($eventChanged || $linkChanged) {
                            $query->orWhere('research_event_id', $candidate->research_event_id);
                        }
                        if ($entityChanged || $listingChanged || isset($form['listings'])) {
                            $query->orWhere('research_entity_id', $candidate->research_entity_id);
                        }
                    })->lockForUpdate()->get();
                foreach ($related as $sibling) {
                    Gate::forUser($actor)->authorize('update', $sibling);
                    if ($sibling->status === 'watchlisted') {
                        $sibling->needs_recheck = true;
                    }
                    $sibling->lock_version++;
                    $sibling->save();
                    $this->appendRevision($sibling, 'updated');
                }
            }

            return $candidate->refresh();
        });
    }

    public function changeStatus(int $candidateId, int $expectedVersion, string $status, string $reason, User $actor): ResearchCandidate
    {
        if (! in_array($status, ['investigating', 'on_hold', 'rejected'], true) || (in_array($status, ['on_hold', 'rejected'], true) && trim($reason) === '')) {
            throw new InvalidArgumentException('状態と理由を確認してください。');
        }

        return DB::transaction(function () use ($candidateId, $expectedVersion, $status, $reason, $actor) {
            $candidate = ResearchCandidate::query()->lockForUpdate()->findOrFail($candidateId);
            Gate::forUser($actor)->authorize('update', $candidate);
            $this->assertVersion($candidate, $expectedVersion);
            $candidate->status = $status;
            $candidate->status_reason = $reason;
            $candidate->lock_version++;
            $candidate->save();
            $this->appendRevision($candidate, 'status_changed');

            return $candidate->refresh();
        });
    }

    public function archive(int $candidateId, User $actor): void
    {
        DB::transaction(function () use ($candidateId, $actor) {
            $candidate = ResearchCandidate::query()->lockForUpdate()->findOrFail($candidateId);
            Gate::forUser($actor)->authorize('delete', $candidate);
            if ($candidate->archived_at !== null) {
                return;
            }
            $candidate->archived_at = now();
            $candidate->lock_version++;
            $candidate->save();
            $this->appendRevision($candidate, 'archived');
            $this->audit('research_candidate.archived', $actor, 'research_candidates', $candidateId);
        });
    }

    public function restore(int $candidateId, User $actor): void
    {
        DB::transaction(function () use ($candidateId, $actor) {
            $candidate = ResearchCandidate::query()->lockForUpdate()->findOrFail($candidateId);
            Gate::forUser($actor)->authorize('restore', $candidate);
            if ($candidate->archived_at === null) {
                return;
            }
            $candidate->archived_at = null;
            $candidate->lock_version++;
            $candidate->save();
            $this->appendRevision($candidate, 'restored');
            $this->audit('research_candidate.restored', $actor, 'research_candidates', $candidateId);
        });
    }

    public function mergeEvents(int $sourceEventId, int $targetEventId, User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAny', ResearchCandidate::class);
        if ($sourceEventId === $targetEventId) {
            throw new InvalidArgumentException('同じ元発表は統合できません。');
        }
        DB::transaction(function () use ($sourceEventId, $targetEventId, $actor) {
            $source = ResearchEvent::query()->lockForUpdate()->findOrFail($sourceEventId);
            $target = ResearchEvent::findOrFail($targetEventId);
            foreach ($source->candidates as $candidate) {
                Gate::forUser($actor)->authorize('update', $candidate);
            }
            if ($target->merged_into_event_id !== null || $source->merged_into_event_id !== null) {
                throw new DomainException('統合先または統合元を再確認してください。');
            }
            $source->merged_into_event_id = $target->id;
            $source->save();
            foreach ($source->candidates as $candidate) {
                $candidate->increment('lock_version');
                $this->appendRevision($candidate->refresh(), 'merged');
            }
            $this->audit('research_event.merged', $actor, 'research_events', $sourceEventId);
        });
    }

    public function unmergeEvent(int $sourceEventId, User $actor): void
    {
        Gate::forUser($actor)->authorize('viewAny', ResearchCandidate::class);
        DB::transaction(function () use ($sourceEventId, $actor) {
            $source = ResearchEvent::query()->lockForUpdate()->findOrFail($sourceEventId);
            foreach ($source->candidates as $candidate) {
                Gate::forUser($actor)->authorize('update', $candidate);
            }
            if ($source->merged_into_event_id === null) {
                return;
            }
            $source->merged_into_event_id = null;
            $source->save();
            foreach ($source->candidates as $candidate) {
                $candidate->increment('lock_version');
                $this->appendRevision($candidate->refresh(), 'unmerged');
            }
            $this->audit('research_event.unmerged', $actor, 'research_events', $sourceEventId);
        });
    }

    /** @return array{idempotency_key:string, lock_version:int, listing_id:int|null, listings:array<int,array>, matched_snapshot_at:?string, favorites_last_imported_at:?string, error:string|null} */
    public function prepareHandoff(int $candidateId, User $actor, ?int $selectedListingId = null): array
    {
        $candidate = ResearchCandidate::with(['event', 'entity.listings', 'claims'])->findOrFail($candidateId);
        Gate::forUser($actor)->authorize('update', $candidate);
        $baseline = $this->baseline();
        $listings = $candidate->entity->listings;
        $listing = $selectedListingId !== null ? $listings->firstWhere('id', $selectedListingId) : $listings->first();
        if ($selectedListingId !== null && $listing === null) {
            throw new DomainException('上場主体の選択を確認してください。');
        }

        return [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => $candidate->lock_version,
            'listing_id' => $listing?->id,
            'listings' => $listings->map(fn ($row) => $row->only(['id', 'market', 'symbol_code', 'listed_entity_name']))->values()->all(),
            'matched_snapshot_at' => $baseline['snapshot']?->snapshotted_at?->toDateTimeString(),
            'favorites_last_imported_at' => $baseline['favorites_last_imported_at'],
            'error' => $this->handoffError($candidate, $baseline),
        ];
    }

    public function confirmHandoff(int $candidateId, int $expectedVersion, string $idempotencyKey, string $reason, User $actor, ?int $selectedListingId = null): ResearchWatchlistHandoff
    {
        if (! Str::isUuid($idempotencyKey) || trim($reason) === '') {
            throw new InvalidArgumentException('監視登録の確認内容が不足しています。');
        }

        return DB::transaction(function () use ($candidateId, $expectedVersion, $idempotencyKey, $reason, $actor, $selectedListingId) {
            $candidate = ResearchCandidate::query()->lockForUpdate()->with(['event', 'entity.listings', 'claims'])->findOrFail($candidateId);
            Gate::forUser($actor)->authorize('update', $candidate);
            $existing = ResearchWatchlistHandoff::where('research_candidate_id', $candidateId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $this->assertVersion($candidate, $expectedVersion);
            $baseline = $this->baseline();
            if ($error = $this->handoffError($candidate, $baseline)) {
                throw new DomainException($error);
            }

            $listing = $selectedListingId !== null
                ? $candidate->entity->listings->firstWhere('id', $selectedListingId)
                : $candidate->entity->listings->first();
            if ($listing === null) {
                throw new DomainException('上場主体の選択を確認してください。');
            }
            $revision = $candidate->revisions()->where('lock_version', $candidate->lock_version)->sole();
            $holding = Holding::firstOrCreate(
                ['symbol_code' => $listing->symbol_code, 'market' => $listing->market],
                ['instrument_type' => 'stock', 'symbol_name' => $listing->listed_entity_name, 'first_detected_at' => now()],
            );

            $isHeld = $baseline['snapshot']->holdingSnapshots()->where('holding_id', $holding->id)->exists();
            $item = null;
            if ($isHeld) {
                $outcome = 'already_held';
            } else {
                $item = WatchlistItem::where('holding_id', $holding->id)->first();
                if ($item !== null) {
                    $outcome = 'linked_existing';
                } else {
                    $item = WatchlistItem::create([
                        'holding_id' => $holding->id, 'source' => 'manual', 'is_starred' => true,
                        'folder_name' => null, 'last_seen_in_csv_at' => null, 'registered_at' => now(),
                    ]);
                    $outcome = 'created';
                }
            }

            if ($item !== null && $item->is_starred) {
                $candidate->status = 'watchlisted';
                $candidate->status_reason = $reason;
            }
            $candidate->lock_version++;
            $candidate->save();
            $this->appendRevision($candidate, 'handed_off');
            $handoff = ResearchWatchlistHandoff::create([
                'research_candidate_id' => $candidateId,
                'research_candidate_revision_id' => $revision->id,
                'research_entity_listing_id' => $listing->id,
                'holding_id' => $holding->id,
                'watchlist_item_id' => $item?->id,
                'outcome' => $outcome,
                'matched_snapshot_id' => $baseline['snapshot']->id,
                'favorites_last_imported_at' => $baseline['favorites_last_imported_at'],
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->audit('research_candidate.handed_off', $actor, 'research_candidates', $candidateId);

            return $handoff;
        });
    }

    /** @return array<string,int> */
    public function stats(?User $actor = null): array
    {
        Gate::forUser($actor ?? auth()->user())->authorize('viewAny', ResearchCandidate::class);
        $candidates = ResearchCandidate::with(['event', 'entity.listings'])->whereNull('archived_at')->get();
        $weekStart = now()->startOfWeek();
        $weekEnd = $weekStart->copy()->addWeek();
        $previousEntityIds = ResearchCandidate::query()->where('created_at', '<', $weekStart)
            ->pluck('research_entity_id')->flip();
        $newThisWeek = $candidates->filter(function ($candidate) use ($weekStart, $weekEnd, $previousEntityIds) {
            $announced = $candidate->event->announced_on;
            if (! $announced || $announced->lt($weekStart) || $announced->gte($weekEnd)) {
                return false;
            }
            if ($candidate->created_at->lt($weekStart) || $candidate->created_at->gte($weekEnd)) {
                return false;
            }

            return ! $previousEntityIds->has($candidate->research_entity_id);
        });

        return [
            'original_events' => $candidates->filter(fn ($c) => $c->event->merged_into_event_id === null)->pluck('research_event_id')->unique()->count(),
            'verified_listed_entities' => $candidates->filter(fn ($c) => $c->entity->identification_status === 'identified' && $c->entity->listings->isNotEmpty())->pluck('research_entity_id')->unique()->count(),
            'this_week_new_discoveries' => $newThisWeek->pluck('research_entity_id')->unique()->count(),
            'past_announcements' => $candidates->filter(fn ($c) => $c->event->announced_on?->lt($weekStart) ?? false)->count(),
        ];
    }

    private function baseline(): array
    {
        $latestFavoriteImport = FavoriteCsvImportState::query()->with('latestBatch')->find(1)?->latestBatch;

        return [
            'snapshot' => Snapshot::query()->orderByDesc('snapshotted_at')->orderByDesc('id')->first(),
            'favorites_last_imported_at' => $latestFavoriteImport?->imported_at?->toDateTimeString()
                ?? WatchlistItem::whereNotNull('last_seen_in_csv_at')->max('last_seen_in_csv_at'),
        ];
    }

    private function handoffError(ResearchCandidate $candidate, array $baseline): ?string
    {
        if ($candidate->archived_at !== null || $candidate->status === 'rejected') {
            return 'アーカイブまたは見送り済みの候補は監視登録できません。';
        }
        if ($candidate->event->verification_status !== 'verified' || ! $candidate->event->original_url || ! $candidate->event->original_publisher || ! $candidate->event->announced_on || ! $candidate->event->verified_on) {
            return '元発表と発表日を一次資料で確認してください。';
        }
        if ($candidate->entity->identification_status !== 'identified' || $candidate->entity->listings->isEmpty()) {
            return '上場主体を確認してください。';
        }
        if (trim((string) $candidate->entity_role) === '') {
            return '企業の役割を確認してください。';
        }
        if (! $candidate->counter_evidence_checked_on) {
            return '反証・未確認事項を確認してください。';
        }
        if (! $candidate->claims->contains(fn ($claim) => $claim->status === 'verified' && $claim->is_primary_source && $claim->evidence_url && $claim->checked_on)) {
            return '確認済みの一次資料の主張が必要です。';
        }
        if (! $baseline['snapshot'] || ! $baseline['favorites_last_imported_at']) {
            return '照合未実施';
        }

        return null;
    }

    private function assertDiscoveryInput(array $form): void
    {
        foreach (['theme', 'theme_relation', 'discovery_url', 'source_title', 'publisher', 'checked_on', 'summary', 'legal_name'] as $field) {
            if (trim((string) ($form[$field] ?? '')) === '') {
                throw new InvalidArgumentException("{$field} が必要です。");
            }
        }
        $this->assertInputLimits($form);
    }

    private function assertInputLimits(array $form): void
    {
        foreach (['discovery_url', 'original_url', 'listing_source_url', 'claim_evidence_url'] as $field) {
            if (! isset($form[$field]) || $form[$field] === '') {
                continue;
            }
            $url = (string) $form[$field];
            if (mb_strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new InvalidArgumentException("{$field} は2048文字以内のHTTP(S) URLにしてください。");
            }
        }
        foreach (['summary', 'theme_relation', 'counter_evidence', 'status_reason', 'identification_note', 'sponsorship_note', 'claim'] as $field) {
            if (isset($form[$field]) && mb_strlen((string) $form[$field]) > 2000) {
                throw new InvalidArgumentException("{$field} は2000文字以内にしてください。");
            }
        }
        foreach (['checked_on', 'posted_on', 'announced_on', 'verified_on', 'counter_evidence_checked_on', 'listing_confirmed_on', 'claim_checked_on'] as $field) {
            if (! isset($form[$field]) || $form[$field] === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $form[$field]);
            if (! $date || $date->format('Y-m-d') !== $form[$field]) {
                throw new InvalidArgumentException("{$field} は実在する日付にしてください。");
            }
        }
    }

    private function assertVersion(ResearchCandidate $candidate, int $expectedVersion): void
    {
        if ($candidate->lock_version !== $expectedVersion) {
            throw new RuntimeException('最新版を確認してから操作してください。');
        }
    }

    private function blankToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    private function syncClaims(ResearchCandidate $candidate, array $claims): void
    {
        foreach ($claims as $input) {
            if (empty($input['claim'])) {
                continue;
            }
            $claim = ! empty($input['id']) ? $candidate->claims()->findOrFail($input['id']) : new ResearchClaim(['research_candidate_id' => $candidate->id]);
            $fields = array_intersect_key($input, array_flip(['claim', 'evidence_url', 'status', 'is_primary_source', 'checked_on']));
            if (array_key_exists('checked_on', $fields)) {
                $fields['checked_on'] = $this->blankToNull($fields['checked_on']);
            }
            $claim->fill($fields);
            $claim->save();
        }
    }

    private function syncListings(ResearchEntity $entity, array $listings): void
    {
        foreach ($listings as $input) {
            if (empty($input['market']) || empty($input['symbol_code'])) {
                continue;
            }
            $listing = ! empty($input['id']) ? $entity->listings()->findOrFail($input['id']) : new ResearchEntityListing(['research_entity_id' => $entity->id]);
            $listing->fill(array_intersect_key($input, array_flip(['market', 'symbol_code', 'listed_entity_name', 'source_url', 'confirmed_on'])));
            $listing->save();
        }
    }

    private function updateFlatListing(ResearchEntity $entity, array $form): bool
    {
        if (empty($form['market']) || empty($form['symbol_code'])) {
            return false;
        }
        $listing = $entity->listings()->orderBy('id')->first() ?? new ResearchEntityListing(['research_entity_id' => $entity->id]);
        foreach (['market', 'symbol_code', 'listed_entity_name'] as $field) {
            if (array_key_exists($field, $form)) {
                $listing->{$field} = $form[$field];
            }
        }
        foreach (['listing_source_url' => 'source_url', 'listing_confirmed_on' => 'confirmed_on'] as $source => $field) {
            if (array_key_exists($source, $form)) {
                $listing->{$field} = $this->blankToNull($form[$source]);
            }
        }
        $changed = ! $listing->exists || $listing->isDirty();
        if ($changed) {
            $listing->save();
        }

        return $changed;
    }

    private function updateFlatClaim(ResearchCandidate $candidate, array $form): bool
    {
        if (empty($form['claim'])) {
            return false;
        }
        $claim = $candidate->claims()->orderBy('id')->first() ?? new ResearchClaim(['research_candidate_id' => $candidate->id]);
        $claim->claim = $form['claim'];
        foreach (['claim_evidence_url' => 'evidence_url', 'claim_status' => 'status', 'claim_primary' => 'is_primary_source', 'claim_checked_on' => 'checked_on'] as $source => $field) {
            if (array_key_exists($source, $form)) {
                $claim->{$field} = in_array($field, ['evidence_url', 'checked_on'], true) ? $this->blankToNull($form[$source]) : $form[$source];
            }
        }
        $changed = ! $claim->exists || $claim->isDirty();
        if ($changed) {
            $claim->save();
        }

        return $changed;
    }

    private function appendRevision(ResearchCandidate $candidate, string $type): ResearchCandidateRevision
    {
        $candidate->load(['event.discoveryLinks', 'entity.listings', 'claims']);

        return ResearchCandidateRevision::create([
            'research_candidate_id' => $candidate->id,
            'lock_version' => $candidate->lock_version,
            'change_type' => $type,
            'payload' => [
                'candidate' => $candidate->getAttributes(),
                'event' => $candidate->event->getAttributes(),
                'discovery_links' => $candidate->event->discoveryLinks->map->getAttributes()->all(),
                'entity' => $candidate->entity->getAttributes(),
                'entity_listings' => $candidate->entity->listings->map->getAttributes()->all(),
                'claims' => $candidate->claims->map->getAttributes()->all(),
            ],
        ]);
    }

    private function audit(string $action, User $actor, string $subjectType, int $subjectId): void
    {
        Log::channel('audit')->info($action, [
            'action' => $action,
            'actor_id' => $actor->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ]);
    }
}
