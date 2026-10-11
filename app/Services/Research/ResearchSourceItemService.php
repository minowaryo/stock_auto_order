<?php

namespace App\Services\Research;

use App\Models\ResearchCandidate;
use App\Models\ResearchDiscoveryLink;
use App\Models\ResearchSourceCheck;
use App\Models\ResearchSourceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** UC-016: display source checks and record a selected entry after human review. */
class ResearchSourceItemService
{
    public function checkSources(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', ResearchCandidate::class);

        return app(ResearchSourceCheckService::class)->check(true);
    }

    public function statuses(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', ResearchCandidate::class);
        $statuses = [];
        foreach (config('research_sources', []) as $key => $definition) {
            $recent = ResearchSourceCheck::query()->where('source_key', $key)
                ->orderByDesc('started_at')->orderByDesc('id')->limit(3)->get();
            $last = $recent->first();
            $success = ResearchSourceCheck::query()->where('source_key', $key)
                ->whereIn('result', ['baseline', 'new_items', 'no_new'])
                ->orderByDesc('started_at')->orderByDesc('id')->first();
            $coverageDays = $definition['coverage_days'] ?? null;
            $statuses[] = [
                'key' => $key,
                'name' => $definition['name'] ?? $key,
                'enabled' => (bool) ($definition['enabled'] ?? false),
                'last_check' => $last,
                'failure_warning' => $recent->count() === 3 && $recent->every(fn ($check) => $check->result === 'failed'),
                'coverage_warning' => $success !== null && is_numeric($coverageDays)
                    && $success->started_at->copy()->addDays((float) $coverageDays)->lt(now()),
                'unread_count' => ResearchSourceItem::query()->where('source_key', $key)
                    ->where('is_baseline', false)->where('disposition', 'unreviewed')->count(),
            ];
        }

        return $statuses;
    }

    public function items(User $actor)
    {
        Gate::forUser($actor)->authorize('viewAny', ResearchCandidate::class);

        return ResearchSourceItem::query()->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'sourceItemsPage');
    }

    public function itemForRecording(int $itemId, User $actor): ResearchSourceItem
    {
        Gate::forUser($actor)->authorize('create', ResearchCandidate::class);
        $item = ResearchSourceItem::findOrFail($itemId);
        $this->assertRecordable($item);

        return $item;
    }

    public function record(int $itemId, array $form, User $actor): ResearchCandidate
    {
        Gate::forUser($actor)->authorize('create', ResearchCandidate::class);

        return DB::transaction(function () use ($itemId, $form, $actor) {
            $item = ResearchSourceItem::query()->lockForUpdate()->findOrFail($itemId);
            $this->assertRecordable($item);
            if (($form['discovery_url'] ?? '') !== $item->url) {
                throw new InvalidArgumentException('選択した項目と発見経路URLが一致しません。項目を選び直してください。');
            }
            $candidate = app(ResearchCandidateService::class)->create($form, $actor);
            $link = ResearchDiscoveryLink::query()->where('research_event_id', $candidate->research_event_id)
                ->where('url_hash', hash('sha256', $item->url))->firstOrFail();
            $item->update([
                'research_discovery_link_id' => $link->id,
                'disposition' => 'recorded',
                'reviewed_at' => now(),
            ]);

            return $candidate;
        });
    }

    public function ignore(int $itemId, User $actor): void
    {
        $this->changeDisposition($itemId, 'ignored', $actor);
    }

    public function restore(int $itemId, User $actor): void
    {
        $this->changeDisposition($itemId, 'unreviewed', $actor);
    }

    private function changeDisposition(int $itemId, string $disposition, User $actor): void
    {
        Gate::forUser($actor)->authorize('create', ResearchCandidate::class);
        DB::transaction(function () use ($itemId, $disposition) {
            ResearchSourceItem::query()->lockForUpdate()->findOrFail($itemId)->update([
                'disposition' => $disposition,
                'reviewed_at' => $disposition === 'unreviewed' ? null : now(),
            ]);
        });
    }

    private function assertRecordable(ResearchSourceItem $item): void
    {
        if ($item->research_discovery_link_id !== null || $item->disposition === 'recorded') {
            throw new InvalidArgumentException('この項目は候補に記録済みです。既存の調査候補を確認してください。');
        }
    }
}
