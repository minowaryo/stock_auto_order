<?php

namespace App\Livewire\Candidate;

use App\Models\ResearchCandidate;
use App\Models\ResearchDiscoveryLink;
use App\Models\ResearchEntity;
use App\Services\Research\ResearchCandidateService;
use DomainException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/** UC-016: 本人が公開資料を読んで記録する調査候補画面。 */
#[Layout('components.layouts.app', ['title' => '調査候補', 'active' => 'candidate-check'])]
class CandidateResearch extends Component
{
    use WithPagination;

    public array $form = [];

    public array $editForm = [];

    public array $claimForm = [];

    public array $listingForm = [];

    public ?int $editingCandidateId = null;

    public ?int $editingVersion = null;

    public ?int $selectedClaimForEditId = null;

    public ?int $selectedListingForEditId = null;

    public ?int $archiveCandidateId = null;

    public ?int $statusCandidateId = null;

    public ?int $statusVersion = null;

    public string $newStatus = '';

    public string $statusReason = '';

    public array $handoff = [];

    public ?int $handoffCandidateId = null;

    public ?int $selectedListingId = null;

    public string $handoffReason = '';

    public ?int $selectedCandidateId = null;

    public ?int $mergeTargetEventId = null;

    public ?string $notice = null;

    public string $themeFilter = '';

    public string $statusFilter = '';

    public string $marketFilter = '';

    public string $sourceFilter = '';

    public bool $unregisteredOnly = false;

    public bool $showArchived = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', ResearchCandidate::class);
        $this->form = $this->blankForm();
        if (request()->query('candidate') !== null) {
            $candidateId = filter_var(request()->query('candidate'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($candidateId === false, 404);
            $candidate = ResearchCandidate::findOrFail($candidateId);
            Gate::authorize('view', $candidate);
            $this->selectedCandidateId = $candidate->id;
            $this->showArchived = $candidate->archived_at !== null;
        }
    }

    public function updatedThemeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMarketFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedUnregisteredOnly(): void
    {
        $this->resetPage();
    }

    public function updatedShowArchived(): void
    {
        $this->resetPage();
    }

    public function saveCandidate(ResearchCandidateService $service): void
    {
        Gate::authorize('create', ResearchCandidate::class);
        $this->validate($this->creationRules(), [], $this->attributes());

        try {
            $service->create($this->form, auth()->user());
            $this->form = $this->blankForm();
            $this->notice = '調査候補を記録しました。一次資料と上場主体を確認してください。';
            $this->resetPage();
        } catch (InvalidArgumentException|DomainException|RuntimeException $exception) {
            $this->addError('form', $exception->getMessage());
        }
    }

    public function editCandidate(int $candidateId): void
    {
        $candidate = ResearchCandidate::query()
            ->with(['event.discoveryLinks', 'entity.listings', 'claims'])
            ->findOrFail($candidateId);
        Gate::authorize('update', $candidate);

        $link = $candidate->event->discoveryLinks->first();
        $listing = $candidate->entity->listings->first();
        $claim = $candidate->claims->first();
        $this->editingCandidateId = $candidate->id;
        $this->editingVersion = $candidate->lock_version;
        $this->editForm = [
            'theme' => $candidate->theme,
            'theme_relation' => $candidate->theme_relation,
            'entity_role' => $candidate->entity_role,
            'evidence_stage' => $candidate->evidence_stage,
            'counter_evidence' => $candidate->counter_evidence,
            'counter_evidence_checked_on' => $candidate->counter_evidence_checked_on?->format('Y-m-d'),
            'event_title' => $candidate->event->title,
            'original_url' => $candidate->event->original_url,
            'original_publisher' => $candidate->event->original_publisher,
            'announced_on' => $candidate->event->announced_on?->format('Y-m-d'),
            'verification_status' => $candidate->event->verification_status,
            'verified_on' => $candidate->event->verified_on?->format('Y-m-d'),
            'legal_name' => $candidate->entity->legal_name,
            'identification_status' => $candidate->entity->identification_status,
            'identification_note' => $candidate->entity->identification_note,
            'market' => $listing?->market,
            'symbol_code' => $listing?->symbol_code,
            'listed_entity_name' => $listing?->listed_entity_name,
            'listing_source_url' => $listing?->source_url,
            'listing_confirmed_on' => $listing?->confirmed_on?->format('Y-m-d'),
            'claim' => $claim?->claim,
            'claim_evidence_url' => $claim?->evidence_url,
            'claim_status' => $claim?->status,
            'claim_primary' => (bool) ($claim?->is_primary_source ?? false),
            'claim_checked_on' => $claim?->checked_on?->format('Y-m-d'),
            'source_title' => $link?->source_title,
            'publisher' => $link?->publisher,
            'checked_on' => $link?->checked_on?->format('Y-m-d'),
            'summary' => $link?->summary,
            'sponsorship_note' => $link?->sponsorship_note,
        ];
        $this->claimForm = $this->blankClaimForm();
        $this->listingForm = $this->blankListingForm();
        $this->selectedClaimForEditId = null;
        $this->selectedListingForEditId = null;
        $this->resetErrorBag();
    }

    public function selectClaimForEdit(int $claimId): void
    {
        $candidate = ResearchCandidate::findOrFail($this->editingCandidateId);
        Gate::authorize('update', $candidate);
        $claim = $candidate->claims()->find($claimId);
        abort_if($claim === null, 404);
        $this->selectedClaimForEditId = $claim->id;
        $this->claimForm = [
            'claim' => $claim->claim,
            'evidence_url' => $claim->evidence_url,
            'status' => $claim->status,
            'is_primary_source' => $claim->is_primary_source,
            'checked_on' => $claim->checked_on?->format('Y-m-d'),
        ];
        $this->resetErrorBag();
    }

    public function selectListingForEdit(int $listingId): void
    {
        $candidate = ResearchCandidate::with('entity')->findOrFail($this->editingCandidateId);
        Gate::authorize('update', $candidate);
        $listing = $candidate->entity->listings()->find($listingId);
        abort_if($listing === null, 404);
        $this->selectedListingForEditId = $listing->id;
        $this->listingForm = [
            'market' => $listing->market,
            'symbol_code' => $listing->symbol_code,
            'listed_entity_name' => $listing->listed_entity_name,
            'source_url' => $listing->source_url,
            'confirmed_on' => $listing->confirmed_on?->format('Y-m-d'),
        ];
        $this->resetErrorBag();
    }

    public function saveClaimChanges(ResearchCandidateService $service): void
    {
        if ($this->editingCandidateId === null || $this->editingVersion === null || $this->selectedClaimForEditId === null) {
            $this->addError('claimForm', '訂正する主張を選んでください。');

            return;
        }

        $candidate = ResearchCandidate::findOrFail($this->editingCandidateId);
        Gate::authorize('update', $candidate);
        abort_if(! $candidate->claims()->whereKey($this->selectedClaimForEditId)->exists(), 404);
        $this->validate([
            'claimForm.claim' => ['required', 'string', 'max:2000'],
            'claimForm.evidence_url' => ['required', 'url:http,https', 'max:2048'],
            'claimForm.status' => ['required', 'in:unverified,verified,contradicted,unavailable'],
            'claimForm.is_primary_source' => ['required', 'boolean'],
            'claimForm.checked_on' => ['required_if:claimForm.status,verified', 'nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $updated = $service->update($candidate->id, $this->editingVersion, [
                'claims' => [['id' => $this->selectedClaimForEditId, ...$this->claimForm]],
            ], auth()->user());
            $this->editingVersion = $updated->lock_version;
            $this->selectedClaimForEditId = null;
            $this->claimForm = $this->blankClaimForm();
            $this->notice = '主張の訂正を新しい版として保存しました。';
        } catch (RuntimeException $exception) {
            $this->addError('claimForm', '最新版を確認してから編集し直してください。');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addError('claimForm', $exception->getMessage());
        }
    }

    public function saveListingChanges(ResearchCandidateService $service): void
    {
        if ($this->editingCandidateId === null || $this->editingVersion === null || $this->selectedListingForEditId === null) {
            $this->addError('listingForm', '訂正する上場先を選んでください。');

            return;
        }

        $candidate = ResearchCandidate::with('entity')->findOrFail($this->editingCandidateId);
        Gate::authorize('update', $candidate);
        abort_if(! $candidate->entity->listings()->whereKey($this->selectedListingForEditId)->exists(), 404);
        $this->validate([
            'listingForm.market' => ['required', 'in:jp,us'],
            'listingForm.symbol_code' => ['required', 'string', 'max:20'],
            'listingForm.listed_entity_name' => ['required', 'string', 'max:255'],
            'listingForm.source_url' => ['required', 'url:http,https', 'max:2048'],
            'listingForm.confirmed_on' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $updated = $service->update($candidate->id, $this->editingVersion, [
                'listings' => [['id' => $this->selectedListingForEditId, ...$this->listingForm]],
            ], auth()->user());
            $this->editingVersion = $updated->lock_version;
            $this->selectedListingForEditId = null;
            $this->listingForm = $this->blankListingForm();
            $this->notice = '上場先の訂正を新しい版として保存しました。';
        } catch (RuntimeException $exception) {
            $this->addError('listingForm', '最新版を確認してから編集し直してください。');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addError('listingForm', $exception->getMessage());
        }
    }

    public function addClaim(ResearchCandidateService $service): void
    {
        if ($this->editingCandidateId === null || $this->editingVersion === null) {
            $this->addError('claimForm', '追記する候補を選んでください。');

            return;
        }

        Gate::authorize('update', ResearchCandidate::findOrFail($this->editingCandidateId));
        $this->validate([
            'claimForm.claim' => ['required', 'string', 'max:2000'],
            'claimForm.evidence_url' => ['required', 'url:http,https', 'max:2048'],
            'claimForm.status' => ['required', 'in:unverified,verified,contradicted,unavailable'],
            'claimForm.is_primary_source' => ['required', 'boolean'],
            'claimForm.checked_on' => ['required_if:claimForm.status,verified', 'nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $updated = $service->update($this->editingCandidateId, $this->editingVersion, ['claims' => [$this->claimForm]], auth()->user());
            $this->editingVersion = $updated->lock_version;
            $this->claimForm = $this->blankClaimForm();
            $this->notice = '主張と根拠を新しい版に追記しました。';
        } catch (RuntimeException $exception) {
            $this->addError('claimForm', '最新版を確認してから追記し直してください。');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addError('claimForm', $exception->getMessage());
        }
    }

    public function addListing(ResearchCandidateService $service): void
    {
        if ($this->editingCandidateId === null || $this->editingVersion === null) {
            $this->addError('listingForm', '追記する候補を選んでください。');

            return;
        }

        Gate::authorize('update', ResearchCandidate::findOrFail($this->editingCandidateId));
        $this->validate([
            'listingForm.market' => ['required', 'in:jp,us'],
            'listingForm.symbol_code' => ['required', 'string', 'max:20'],
            'listingForm.listed_entity_name' => ['required', 'string', 'max:255'],
            'listingForm.source_url' => ['required', 'url:http,https', 'max:2048'],
            'listingForm.confirmed_on' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $updated = $service->update($this->editingCandidateId, $this->editingVersion, ['listings' => [$this->listingForm]], auth()->user());
            $this->editingVersion = $updated->lock_version;
            $this->listingForm = $this->blankListingForm();
            $this->notice = '上場先と確認元を新しい版に追記しました。';
        } catch (RuntimeException $exception) {
            $this->addError('listingForm', '最新版を確認してから追記し直してください。');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addError('listingForm', $exception->getMessage());
        }
    }

    public function saveChanges(ResearchCandidateService $service): void
    {
        if ($this->editingCandidateId === null || $this->editingVersion === null) {
            $this->addError('editForm', '編集する候補を選んでください。');

            return;
        }

        $candidate = ResearchCandidate::findOrFail($this->editingCandidateId);
        Gate::authorize('update', $candidate);
        $this->validate($this->editRules(), [], $this->attributes());

        try {
            $service->update($this->editingCandidateId, $this->editingVersion, $this->editForm, auth()->user());
            $this->editingCandidateId = null;
            $this->editForm = [];
            $this->notice = '訂正を新しい版として保存しました。';
        } catch (RuntimeException $exception) {
            $this->addError('editForm', '最新版を確認してから編集し直してください。');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addError('editForm', $exception->getMessage());
        }
    }

    public function beginStatusChange(int $candidateId, string $status): void
    {
        $candidate = ResearchCandidate::findOrFail($candidateId);
        Gate::authorize('update', $candidate);
        $this->statusCandidateId = $candidateId;
        $this->statusVersion = $candidate->lock_version;
        $this->newStatus = $status;
        $this->statusReason = '';
        $this->resetErrorBag();
    }

    public function saveStatus(ResearchCandidateService $service): void
    {
        if ($this->statusCandidateId === null || $this->statusVersion === null) {
            $this->addError('statusReason', '状態を変更する候補を選んでください。');

            return;
        }

        Gate::authorize('update', ResearchCandidate::findOrFail($this->statusCandidateId));
        $this->validate([
            'newStatus' => ['required', 'in:investigating,on_hold,rejected'],
            'statusReason' => ['required', 'string', 'max:2000'],
        ], [], ['statusReason' => '判断理由']);

        try {
            $service->changeStatus($this->statusCandidateId, $this->statusVersion, $this->newStatus, $this->statusReason, auth()->user());
            $this->statusCandidateId = null;
            $this->statusReason = '';
            $this->notice = '判断理由と状態を保存しました。';
        } catch (RuntimeException $exception) {
            $this->addError('statusReason', '最新版を確認してからやり直してください。');
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->addError('statusReason', $exception->getMessage());
        }
    }

    public function requestArchive(int $candidateId): void
    {
        Gate::authorize('delete', ResearchCandidate::findOrFail($candidateId));
        $this->archiveCandidateId = $candidateId;
    }

    public function confirmArchive(ResearchCandidateService $service): void
    {
        if ($this->archiveCandidateId === null) {
            return;
        }

        Gate::authorize('delete', ResearchCandidate::findOrFail($this->archiveCandidateId));
        $service->archive($this->archiveCandidateId, auth()->user());
        $this->archiveCandidateId = null;
        $this->notice = '調査候補をアーカイブしました。';
    }

    public function restoreCandidate(int $candidateId, ResearchCandidateService $service): void
    {
        Gate::authorize('restore', ResearchCandidate::findOrFail($candidateId));
        $service->restore($candidateId, auth()->user());
        $this->notice = '調査候補を復元しました。';
    }

    public function mergeEvents(int $sourceEventId, int $targetEventId, ResearchCandidateService $service): void
    {
        Gate::authorize('update', ResearchCandidate::query()->where('research_event_id', $sourceEventId)->firstOrFail());
        try {
            $service->mergeEvents($sourceEventId, $targetEventId, auth()->user());
            $this->notice = '元発表を関連付けました。';
        } catch (InvalidArgumentException|DomainException|RuntimeException $exception) {
            $this->addError('merge', $exception->getMessage());
        }
    }

    public function unmergeEvent(int $sourceEventId, ResearchCandidateService $service): void
    {
        Gate::authorize('update', ResearchCandidate::query()->where('research_event_id', $sourceEventId)->firstOrFail());
        try {
            $service->unmergeEvent($sourceEventId, auth()->user());
            $this->notice = '元発表の関連付けを解除しました。';
        } catch (InvalidArgumentException|DomainException|RuntimeException $exception) {
            $this->addError('merge', $exception->getMessage());
        }
    }

    public function prepareHandoff(int $candidateId, ResearchCandidateService $service): void
    {
        Gate::authorize('update', ResearchCandidate::findOrFail($candidateId));
        $this->resetErrorBag('handoff');
        $this->handoffCandidateId = $candidateId;
        $this->selectedListingId = null;
        try {
            $this->handoff = ['candidate_id' => $candidateId] + $service->prepareHandoff($candidateId, auth()->user());
            $this->selectedListingId = $this->handoff['listing_id'] ?? null;
            if (filled($this->handoff['error'] ?? null)) {
                $this->addError('handoff', $this->handoff['error']);
            }
        } catch (InvalidArgumentException|DomainException|RuntimeException $exception) {
            $this->handoff = ['candidate_id' => $candidateId, 'error' => $exception->getMessage()];
            $this->addError('handoff', $exception->getMessage());
        }
    }

    public function selectHandoffListing(int $listingId, ResearchCandidateService $service): void
    {
        if ($this->handoffCandidateId === null) {
            return;
        }

        Gate::authorize('update', ResearchCandidate::findOrFail($this->handoffCandidateId));
        try {
            $this->handoff = ['candidate_id' => $this->handoffCandidateId]
                + $service->prepareHandoff($this->handoffCandidateId, auth()->user(), $listingId);
            $this->selectedListingId = $listingId;
            $this->resetErrorBag('handoff');
            if (filled($this->handoff['error'] ?? null)) {
                $this->addError('handoff', $this->handoff['error']);
            }
        } catch (InvalidArgumentException|DomainException|RuntimeException $exception) {
            $this->addError('handoff', $exception->getMessage());
        }
    }

    public function confirmPreparedHandoff(ResearchCandidateService $service): void
    {
        $this->confirmHandoff($this->handoffReason, $service);
    }

    public function mergeSelectedEvent(int $sourceEventId, ResearchCandidateService $service): void
    {
        if ($this->mergeTargetEventId === null) {
            $this->addError('merge', '統合先の元発表IDを入力してください。');

            return;
        }

        $this->mergeEvents($sourceEventId, $this->mergeTargetEventId, $service);
    }

    public function selectCandidate(int $candidateId): void
    {
        Gate::authorize('view', ResearchCandidate::findOrFail($candidateId));
        $this->selectedCandidateId = $this->selectedCandidateId === $candidateId ? null : $candidateId;
    }

    public function confirmHandoff(string $reason, ResearchCandidateService $service): void
    {
        if (! isset($this->handoff['candidate_id'], $this->handoff['lock_version'], $this->handoff['idempotency_key'])) {
            $this->addError('handoff', $this->handoff['error'] ?? '候補の確認欄を開き、照合情報を確認してください。');

            return;
        }

        Gate::authorize('update', ResearchCandidate::findOrFail($this->handoff['candidate_id']));
        if (blank($reason) || mb_strlen($reason) > 2000) {
            $this->addError('handoff', '監視を選択した理由を2000文字以内で入力してください。');

            return;
        }

        try {
            $result = $service->confirmHandoff(
                $this->handoff['candidate_id'],
                $this->handoff['lock_version'],
                $this->handoff['idempotency_key'],
                $reason,
                auth()->user(),
                $this->handoff['listing_id'] ?? null,
            );
            $this->resetErrorBag('handoff');
            $this->notice = match ($result->outcome) {
                'created' => 'ウォッチリストに登録しました。指標は未取得です。',
                'linked_existing' => '既存候補に関連付けました。★がOFFならウォッチリストで選択してください。',
                'already_held' => '既に保有しています。保有一覧で確認してください。',
                default => '受け渡し結果を記録しました。',
            };
        } catch (InvalidArgumentException|DomainException|RuntimeException $exception) {
            $this->addError('handoff', $exception->getMessage());
        }
    }

    public function render()
    {
        Gate::authorize('viewAny', ResearchCandidate::class);
        $discoveryUrl = trim((string) ($this->form['discovery_url'] ?? ''));
        $legalName = trim((string) ($this->form['legal_name'] ?? ''));
        $query = ResearchCandidate::query()
            ->with(['event.discoveryLinks', 'entity.listings', 'claims', 'handoffs'])
            ->when(! $this->showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->when($this->showArchived, fn ($query) => $query->whereNotNull('archived_at'))
            ->when($this->themeFilter !== '', fn ($query) => $query->where('theme', $this->themeFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->marketFilter !== '', fn ($query) => $query->whereHas('entity.listings', fn ($listings) => $listings->where('market', $this->marketFilter)))
            ->when($this->sourceFilter !== '', fn ($query) => $query->whereHas('event.discoveryLinks', fn ($links) => $links->where('publisher', $this->sourceFilter)))
            ->when($this->unregisteredOnly, fn ($query) => $query->whereDoesntHave('handoffs'))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        $candidates = $query->paginate(20);
        $candidates->getCollection()->firstWhere('id', $this->selectedCandidateId)?->load('revisions');

        return view('livewire.candidate.candidate-research', [
            'candidates' => $candidates,
            'stats' => app(ResearchCandidateService::class)->stats(),
            'handoffCandidate' => $this->handoffCandidateId !== null
                ? ResearchCandidate::query()->with(['event', 'entity.listings', 'claims'])->find($this->handoffCandidateId)
                : null,
            'editingCandidate' => $this->editingCandidateId !== null
                ? ResearchCandidate::query()->with(['entity.listings', 'claims'])->find($this->editingCandidateId)
                : null,
            'themes' => ResearchCandidate::query()->whereNull('archived_at')->distinct()->orderBy('theme')->pluck('theme'),
            'publishers' => ResearchDiscoveryLink::query()->distinct()->orderBy('publisher')->pluck('publisher'),
            'matchingEvents' => $discoveryUrl !== '' && filter_var($discoveryUrl, FILTER_VALIDATE_URL)
                ? ResearchDiscoveryLink::query()->with('event')->where('url_hash', hash('sha256', $discoveryUrl))
                    ->limit(10)->get()->pluck('event')->unique('id')
                : collect(),
            'matchingEntities' => $legalName !== ''
                ? ResearchEntity::query()->with('listings')->where('legal_name', $legalName)->limit(10)->get()
                : collect(),
        ]);
    }

    private function blankForm(): array
    {
        return [
            'theme' => '', 'theme_relation' => '', 'event_title' => '',
            'discovery_url' => '', 'source_title' => '', 'publisher' => '',
            'checked_on' => now()->toDateString(), 'summary' => '', 'legal_name' => '',
            'original_url' => null, 'original_publisher' => null, 'announced_on' => null,
            'entity_role' => null, 'existing_event_id' => null, 'existing_entity_id' => null,
        ];
    }

    private function blankClaimForm(): array
    {
        return ['claim' => '', 'evidence_url' => '', 'status' => 'unverified', 'is_primary_source' => false, 'checked_on' => null];
    }

    private function blankListingForm(): array
    {
        return ['market' => '', 'symbol_code' => '', 'listed_entity_name' => '', 'source_url' => '', 'confirmed_on' => null];
    }

    private function creationRules(): array
    {
        return [
            'form.theme' => ['required', 'string', 'max:100'],
            'form.theme_relation' => ['required', 'string', 'max:2000'],
            'form.event_title' => ['nullable', 'string', 'max:255'],
            'form.discovery_url' => ['required', 'url:http,https', 'max:2048'],
            'form.source_title' => ['required', 'string', 'max:255'],
            'form.publisher' => ['required', 'string', 'max:255'],
            'form.checked_on' => ['required', 'date_format:Y-m-d'],
            'form.summary' => ['required', 'string', 'max:2000'],
            'form.legal_name' => ['required', 'string', 'max:255'],
            'form.original_url' => ['nullable', 'url:http,https', 'max:2048'],
            'form.original_publisher' => ['nullable', 'string', 'max:255'],
            'form.announced_on' => ['nullable', 'date_format:Y-m-d'],
            'form.entity_role' => ['nullable', 'string', 'max:255'],
            'form.existing_event_id' => ['nullable', 'integer', 'min:1'],
            'form.existing_entity_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function editRules(): array
    {
        return [
            'editForm.theme' => ['required', 'string', 'max:100'],
            'editForm.theme_relation' => ['required', 'string', 'max:2000'],
            'editForm.entity_role' => ['nullable', 'string', 'max:255'],
            'editForm.evidence_stage' => ['nullable', 'in:unknown,research,product,regulatory,plan,operation,order,revenue'],
            'editForm.counter_evidence' => ['nullable', 'string', 'max:2000'],
            'editForm.counter_evidence_checked_on' => ['nullable', 'date_format:Y-m-d'],
            'editForm.original_url' => ['nullable', 'url:http,https', 'max:2048'],
            'editForm.original_publisher' => ['nullable', 'string', 'max:255'],
            'editForm.event_title' => ['required', 'string', 'max:255'],
            'editForm.announced_on' => ['nullable', 'date_format:Y-m-d'],
            'editForm.verified_on' => ['nullable', 'date_format:Y-m-d'],
            'editForm.verification_status' => ['required', 'in:unverified,verified,unavailable'],
            'editForm.legal_name' => ['required', 'string', 'max:255'],
            'editForm.identification_status' => ['required', 'in:unidentified,identified,unlisted,foreign_only,ambiguous'],
            'editForm.market' => ['required_if:editForm.identification_status,identified', 'required_with:editForm.symbol_code', 'nullable', 'in:jp,us'],
            'editForm.symbol_code' => ['required_with:editForm.market', 'nullable', 'string', 'max:20'],
            'editForm.listed_entity_name' => ['required_with:editForm.market,editForm.symbol_code', 'nullable', 'string', 'max:255'],
            'editForm.listing_source_url' => ['required_with:editForm.market,editForm.symbol_code', 'nullable', 'url:http,https', 'max:2048'],
            'editForm.listing_confirmed_on' => ['required_with:editForm.market,editForm.symbol_code', 'nullable', 'date_format:Y-m-d'],
            'editForm.claim' => ['nullable', 'string', 'max:2000'],
            'editForm.claim_evidence_url' => ['nullable', 'url:http,https', 'max:2048'],
            'editForm.claim_checked_on' => ['nullable', 'date_format:Y-m-d'],
            'editForm.claim_status' => ['nullable', 'in:unverified,verified,contradicted,unavailable'],
            'editForm.summary' => ['nullable', 'string', 'max:2000'],
            'editForm.source_title' => ['nullable', 'string', 'max:255'],
            'editForm.publisher' => ['nullable', 'string', 'max:255'],
            'editForm.checked_on' => ['nullable', 'date_format:Y-m-d'],
            'editForm.sponsorship_note' => ['nullable', 'string', 'max:2000'],
            'editForm.identification_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function attributes(): array
    {
        return [
            'form.theme' => 'テーマ', 'form.theme_relation' => 'テーマとの関係',
            'form.discovery_url' => '発見経路URL', 'form.source_title' => '資料名',
            'form.publisher' => '発行者', 'form.checked_on' => '確認日',
            'form.summary' => '自分の要約', 'form.legal_name' => '法人名',
        ];
    }
}
