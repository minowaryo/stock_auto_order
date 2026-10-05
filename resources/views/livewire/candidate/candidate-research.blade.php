<div class="space-y-6">
    <x-page-header title="調査候補" caption="公開資料を本人が確認して記録します。売買推奨や順位付けは行いません（UC-016）" />

    <nav class="flex gap-2 border-b border-app-border pb-2 text-[13px]" aria-label="新規投資候補の切替">
        <a href="/candidate-check" wire:navigate class="rounded px-3 py-1.5 text-text-secondary hover:bg-app-bg">ウォッチリスト</a>
        <a href="/candidate-research" wire:navigate aria-current="page" class="rounded bg-blue-50 px-3 py-1.5 font-semibold text-primary">調査候補</a>
    </nav>

    @if ($notice)
        <p role="status" class="rounded border border-green-200 bg-green-50 p-3 text-[13px] text-green-800">{{ $notice }}</p>
    @endif

    <div class="grid gap-3 text-[13px] sm:grid-cols-4">
        <x-card><div class="text-text-secondary">元発表の件数</div><div class="mt-1 text-xl font-semibold">{{ $stats['original_events'] ?? 0 }}</div></x-card>
        <x-card><div class="text-text-secondary">確認済み上場企業</div><div class="mt-1 text-xl font-semibold">{{ $stats['verified_listed_entities'] ?? 0 }}</div></x-card>
        <x-card><div class="text-text-secondary">今週の新規発見</div><div class="mt-1 text-xl font-semibold">{{ $stats['this_week_new_discoveries'] ?? 0 }}</div></x-card>
        <x-card><div class="text-text-secondary">過去の発表</div><div class="mt-1 text-xl font-semibold">{{ $stats['past_announcements'] ?? 0 }}</div></x-card>
    </div>
    <p class="text-[12px] text-text-secondary">記録経路: 手動記録。情報源の定期確認・全市場の網羅を示す数値ではありません。元発表の転載は同じ出来事として数えます。</p>

    <x-card>
        <h2 class="mb-3 text-base font-semibold">公開資料から候補を記録</h2>
        <form wire:submit="saveCandidate" class="space-y-3 text-[13px]">
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block">テーマ <span class="text-danger">必須</span>
                    <input wire:model.blur="form.theme" list="research-themes" class="mt-1 w-full rounded border border-app-border p-2" maxlength="100" placeholder="例: 医療AI">
                    <datalist id="research-themes"><option value="AI基盤"></option><option value="フィジカルAI"></option><option value="医療AI"></option></datalist>
                    @error('form.theme') <span class="block text-danger">{{ $message }}</span> @enderror
                </label>
                <label class="block">言及された法人 <span class="text-danger">必須</span>
                    <input wire:model.blur="form.legal_name" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255">
                    @error('form.legal_name') <span class="block text-danger">{{ $message }}</span> @enderror
                </label>
            </div>
            <label class="block">テーマとの関係 <span class="text-danger">必須</span>
                <textarea wire:model.blur="form.theme_relation" class="mt-1 w-full rounded border border-app-border p-2" rows="2" maxlength="2000"></textarea>
                @error('form.theme_relation') <span class="block text-danger">{{ $message }}</span> @enderror
            </label>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="block">発見経路URL <span class="text-danger">必須</span>
                    <input wire:model.blur="form.discovery_url" type="url" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2048" placeholder="https://...">
                    @error('form.discovery_url') <span class="block text-danger">{{ $message }}</span> @enderror
                </label>
                <label class="block">資料名 <span class="text-danger">必須</span>
                    <input wire:model.blur="form.source_title" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255">
                    @error('form.source_title') <span class="block text-danger">{{ $message }}</span> @enderror
                </label>
                <label class="block">発行者 <span class="text-danger">必須</span>
                    <input wire:model.blur="form.publisher" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255">
                    @error('form.publisher') <span class="block text-danger">{{ $message }}</span> @enderror
                </label>
                <label class="block">本人の確認日 <span class="text-danger">必須</span>
                    <input wire:model.blur="form.checked_on" type="date" class="mt-1 w-full rounded border border-app-border p-2">
                    @error('form.checked_on') <span class="block text-danger">{{ $message }}</span> @enderror
                </label>
            </div>
            <label class="block">自分の要約 <span class="text-danger">必須</span>
                <textarea wire:model.blur="form.summary" class="mt-1 w-full rounded border border-app-border p-2" rows="3" maxlength="2000" placeholder="紹介元の記述と一次資料で確認した事実を分けて記入"></textarea>
                @error('form.summary') <span class="block text-danger">{{ $message }}</span> @enderror
            </label>
            <details class="rounded border border-app-border p-3">
                <summary class="cursor-pointer font-medium">元発表・既存記録への関連付け（任意）</summary>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <label>出来事名<input wire:model.blur="form.event_title" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255"></label>
                    <label>元発表URL<input wire:model.blur="form.original_url" type="url" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2048"></label>
                    <label>元発表の発行主体<input wire:model.blur="form.original_publisher" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255"></label>
                    <label>元発表日<input wire:model.blur="form.announced_on" type="date" class="mt-1 w-full rounded border border-app-border p-2"></label>
                    <label>企業の役割<input wire:model.blur="form.entity_role" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255" placeholder="例: 供給者"></label>
                    <label>既存の元発表ID<input wire:model.blur="form.existing_event_id" type="number" min="1" class="mt-1 w-full rounded border border-app-border p-2" placeholder="同じ出来事と本人が確認した場合のみ"></label>
                    <label>既存の法人ID<input wire:model.blur="form.existing_entity_id" type="number" min="1" class="mt-1 w-full rounded border border-app-border p-2" placeholder="同じ法人と本人が確認した場合のみ"></label>
                </div>
                @error('form.original_url') <p class="text-danger">{{ $message }}</p> @enderror
                @error('form.announced_on') <p class="text-danger">{{ $message }}</p> @enderror
            </details>
            @if ($matchingEvents->isNotEmpty() || $matchingEntities->isNotEmpty())
                <div class="rounded border border-amber-200 bg-amber-50 p-3 text-[12px]">
                    <p class="font-semibold">既存記録の候補を確認してください。自動では統合しません。</p>
                    @foreach ($matchingEvents as $matchingEvent)
                        <p>同じ発見URLの元発表 ID {{ $matchingEvent->id }}・{{ $matchingEvent->title }}</p>
                    @endforeach
                    @foreach ($matchingEntities as $matchingEntity)
                        <p>同名法人 ID {{ $matchingEntity->id }}・{{ $matchingEntity->legal_name }}@foreach ($matchingEntity->listings as $knownListing)／{{ strtoupper($knownListing->market) }} {{ $knownListing->symbol_code }}@endforeach</p>
                    @endforeach
                </div>
            @endif
            @error('form') <p role="alert" class="text-danger">{{ $message }}</p> @enderror
            <x-btn type="submit" wire:loading.attr="disabled">調査候補を記録</x-btn>
        </form>
    </x-card>

    <x-card>
        <h2 class="mb-3 text-base font-semibold">調査候補一覧</h2>
        <div class="flex flex-wrap items-end gap-3 text-[13px]">
            <label>テーマ<select wire:model.live="themeFilter" class="mt-1 block rounded border border-app-border p-2"><option value="">すべて</option>@foreach ($themes as $theme)<option value="{{ $theme }}">{{ $theme }}</option>@endforeach</select></label>
            <label>状態<select wire:model.live="statusFilter" class="mt-1 block rounded border border-app-border p-2"><option value="">すべて</option><option value="investigating">調査中</option><option value="on_hold">保留</option><option value="rejected">見送り</option><option value="watchlisted">監視登録済み</option></select></label>
            <label>市場<select wire:model.live="marketFilter" class="mt-1 block rounded border border-app-border p-2"><option value="">すべて</option><option value="jp">日本株</option><option value="us">米国株</option></select></label>
            <label>情報源<select wire:model.live="sourceFilter" class="mt-1 block rounded border border-app-border p-2"><option value="">すべて</option>@foreach ($publishers as $publisher)<option value="{{ $publisher }}">{{ $publisher }}</option>@endforeach</select></label>
            <label class="flex items-center gap-1 pb-2"><input type="checkbox" wire:model.live="unregisteredOnly">監視未登録</label>
            <label class="flex items-center gap-1 pb-2"><input type="checkbox" wire:model.live="showArchived">アーカイブを表示</label>
        </div>
    </x-card>

    @forelse ($candidates as $candidate)
        @php
            $event = $candidate->event;
            $entity = $candidate->entity;
            $listing = $entity->listings->first();
            $link = $event->discoveryLinks->first();
            $statusLabel = ['investigating' => '調査中', 'on_hold' => '保留', 'rejected' => '見送り', 'watchlisted' => '監視登録済み'][$candidate->status] ?? $candidate->status;
            $stageLabel = ['unknown' => '未確認', 'research' => '研究・採択', 'product' => '製品', 'regulatory' => '規制承認', 'plan' => '導入計画', 'operation' => '稼働', 'order' => '受注', 'revenue' => '売上'][$candidate->evidence_stage] ?? '未確認';
        @endphp
        <x-card>
            <div class="flex flex-wrap justify-between gap-2">
                <div>
                    <button type="button" wire:click="selectCandidate({{ $candidate->id }})" class="text-left text-base font-semibold text-primary hover:underline">{{ $entity->legal_name }}</button>
                    @if ($listing)<span class="ml-2 text-[12px] text-text-secondary">{{ strtoupper($listing->market) }} {{ $listing->symbol_code }}・{{ $listing->listed_entity_name }}</span>@else <span class="ml-2 text-[12px] text-amber-700">同定未了</span>@endif
                </div>
                <div class="text-[12px] text-text-secondary">#{{ $candidate->id }}・記録 {{ $candidate->created_at?->format('Y-m-d H:i') }}・版 {{ $candidate->lock_version }}</div>
            </div>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[12px]">
                <span>テーマ: {{ $candidate->theme }}</span>
                <span>役割: {{ $candidate->entity_role ?: '未確認' }}</span>
                <span>元発表日: {{ $event->announced_on?->format('Y-m-d') ?? '不明' }}</span>
                <span>確認日: {{ $link?->checked_on?->format('Y-m-d') ?? '不明' }}</span>
                <span>証拠段階: {{ $stageLabel }}</span>
                <span>状態: {{ $statusLabel }}</span>
                <span>{{ $candidate->handoffs->isEmpty() ? '照合未実施' : '受け渡し履歴あり' }}</span>
                @if ($candidate->needs_recheck)<span class="font-semibold text-amber-700">要再確認</span>@endif
            </div>
            <p class="mt-2 text-[13px]">{{ $candidate->theme_relation }}</p>
            <div class="mt-3 flex flex-wrap gap-2 text-[12px]">
                <button type="button" wire:click="selectCandidate({{ $candidate->id }})" class="rounded border border-app-border px-2 py-1">{{ $selectedCandidateId === $candidate->id ? '詳細を閉じる' : '詳細・履歴' }}</button>
                @unless ($candidate->archived_at)
                    <button type="button" wire:click="editCandidate({{ $candidate->id }})" class="rounded border border-app-border px-2 py-1">訂正</button>
                    <button type="button" wire:click="beginStatusChange({{ $candidate->id }}, 'on_hold')" class="rounded border border-app-border px-2 py-1">保留</button>
                    <button type="button" wire:click="beginStatusChange({{ $candidate->id }}, 'rejected')" class="rounded border border-app-border px-2 py-1">見送り</button>
                    <button type="button" wire:click="prepareHandoff({{ $candidate->id }})" class="rounded border border-app-border px-2 py-1">監視に追加</button>
                    <button type="button" wire:click="requestArchive({{ $candidate->id }})" class="rounded border border-app-border px-2 py-1 text-danger">アーカイブ</button>
                @else
                    <button type="button" wire:click="restoreCandidate({{ $candidate->id }})" class="rounded border border-app-border px-2 py-1">復元</button>
                @endunless
            </div>

            @if ($selectedCandidateId === $candidate->id)
                <div class="mt-4 space-y-3 border-t border-app-border pt-3 text-[12px]">
                    <div>出来事: {{ $event->title }}（元発表ID {{ $event->id }}）／元資料: {{ $event->original_url ?: '未確認' }}／発行主体: {{ $event->original_publisher ?: '未確認' }}</div>
                    <div>発見経路:@foreach ($event->discoveryLinks as $discoveryLink) <span class="block">{{ $discoveryLink->source_title }}・{{ $discoveryLink->publisher }}・{{ $discoveryLink->url }}・確認 {{ $discoveryLink->checked_on?->format('Y-m-d') ?? '不明' }}</span><span class="block text-text-secondary">{{ $discoveryLink->summary }}</span>@endforeach</div>
                    <div>上場主体の確認: {{ $entity->identification_note ?: '未確認' }}@foreach ($entity->listings as $entityListing)<span class="block">{{ $entityListing->market }} {{ $entityListing->symbol_code }}／{{ $entityListing->source_url }}／確認 {{ $entityListing->confirmed_on?->format('Y-m-d') }}</span>@endforeach</div>
                    <div>主張と根拠:@forelse ($candidate->claims as $claim)<span class="block">{{ $claim->status }}・{{ $claim->is_primary_source ? '一次資料' : '紹介資料' }}・{{ $claim->claim }}／{{ $claim->evidence_url ?: 'URLなし' }}</span>@empty <span>未記録</span>@endforelse</div>
                    <div>反証・未確認事項: {{ $candidate->counter_evidence ?: '未記録' }}／確認日 {{ $candidate->counter_evidence_checked_on?->format('Y-m-d') ?? '不明' }}</div>
                    <div>状態理由: {{ $candidate->status_reason ?: '未記録' }}</div>
                    <div class="space-y-2">
                        <p>編集履歴</p>
                        @php $previousPayload = null; @endphp
                        @foreach ($candidate->revisions->sortBy('lock_version') as $revision)
                            <div class="rounded border border-app-border p-2">
                                <p class="font-medium">版 {{ $revision->lock_version }}・{{ $revision->change_type }}・{{ $revision->created_at?->format('Y-m-d H:i') }}</p>
                                @if ($previousPayload === null)
                                    <p>初回記録</p>
                                @else
                                    @forelse (\App\Support\ResearchRevisionDisplay::changes($previousPayload, $revision->payload ?? []) as $change)
                                        <p class="mt-1">{{ $change['label'] }}: 変更前「{{ $change['before'] }}」→ 変更後「{{ $change['after'] }}」</p>
                                    @empty
                                        <p>表示対象の項目に変更はありません。</p>
                                    @endforelse
                                @endif
                            </div>
                            @php $previousPayload = $revision->payload ?? []; @endphp
                        @endforeach
                    </div>
                    <div>受け渡し履歴:@forelse ($candidate->handoffs as $handoffRecord)<span class="block">{{ $handoffRecord->outcome }}・{{ $handoffRecord->created_at?->format('Y-m-d H:i') }}</span>@empty <span>なし</span>@endforelse</div>
                    <div class="space-y-2 border-t border-app-border pt-2">
                        @if ($event->merged_into_event_id)
                            <p>元発表ID {{ $event->merged_into_event_id }} に関連付け済み</p><button type="button" wire:click="unmergeEvent({{ $event->id }})" class="rounded border border-app-border px-2 py-1">誤統合を解除</button>
                        @else
                            <label>同じ出来事として関連付ける先の元発表ID <input wire:model.blur="mergeTargetEventId" type="number" min="1" class="rounded border border-app-border p-1"></label>
                            <button type="button" wire:click="mergeSelectedEvent({{ $event->id }})" class="rounded border border-app-border px-2 py-1">関連付け</button>
                        @endif
                        @error('merge') <p class="text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif
        </x-card>
    @empty
        <x-empty-state>この確認範囲では候補がありません。未確認の情報源、対象外のテーマ、照合元の欠測を確認してください。</x-empty-state>
    @endforelse

    @if ($candidates->hasPages())<div>{{ $candidates->links() }}</div>@endif

    @if ($editingCandidateId !== null)
        <x-card>
            <h2 class="mb-3 text-base font-semibold">候補 #{{ $editingCandidateId }} を訂正（版 {{ $editingVersion }}）</h2>
            <form wire:submit="saveChanges" class="space-y-3 text-[13px]">
                <div class="grid gap-3 md:grid-cols-2">
                    <label>テーマ<input wire:model.blur="editForm.theme" class="mt-1 w-full rounded border border-app-border p-2" maxlength="100"></label>
                    <label>法人名<input wire:model.blur="editForm.legal_name" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255"></label>
                    <label>企業の役割<input wire:model.blur="editForm.entity_role" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255"></label>
                    <label>証拠段階<select wire:model="editForm.evidence_stage" class="mt-1 w-full rounded border border-app-border p-2"><option value="unknown">未確認</option><option value="research">研究・採択</option><option value="product">製品</option><option value="regulatory">規制承認</option><option value="plan">導入計画</option><option value="operation">稼働</option><option value="order">受注</option><option value="revenue">売上</option></select></label>
                </div>
                <label class="block">テーマとの関係<textarea wire:model.blur="editForm.theme_relation" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2000"></textarea>@error('editForm.theme_relation')<span class="text-danger">{{ $message }}</span>@enderror</label>
                <div class="grid gap-3 rounded border border-app-border p-3 md:grid-cols-2">
                    <label>出来事名<input wire:model.blur="editForm.event_title" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255"></label>
                    <label>紹介資料名<input wire:model.blur="editForm.source_title" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255"></label>
                    <label>紹介資料の発行者<input wire:model.blur="editForm.publisher" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255"></label>
                    <label>紹介資料の確認日<input wire:model.blur="editForm.checked_on" type="date" class="mt-1 w-full rounded border border-app-border p-2"></label>
                    <label class="md:col-span-2">自分の要約<textarea wire:model.blur="editForm.summary" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2000"></textarea></label>
                    <label class="md:col-span-2">スポンサー・利益相反の注記<textarea wire:model.blur="editForm.sponsorship_note" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2000"></textarea></label>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <label>元発表日<input wire:model.blur="editForm.announced_on" type="date" class="mt-1 w-full rounded border border-app-border p-2"></label>
                    <label>元発表URL<input wire:model.blur="editForm.original_url" type="url" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2048"></label>
                    <label>元発表の発行主体<input wire:model.blur="editForm.original_publisher" class="mt-1 w-full rounded border border-app-border p-2"></label>
                    <label>元資料の確認状態<select wire:model="editForm.verification_status" class="mt-1 w-full rounded border border-app-border p-2"><option value="unverified">未確認</option><option value="verified">確認済み</option><option value="unavailable">確認不能</option></select></label>
                    <label>元資料の確認日<input wire:model.blur="editForm.verified_on" type="date" class="mt-1 w-full rounded border border-app-border p-2"></label>
                    <label>上場主体の同定状態<select wire:model="editForm.identification_status" class="mt-1 w-full rounded border border-app-border p-2"><option value="unidentified">未同定</option><option value="identified">同定済み</option><option value="unlisted">非上場</option><option value="foreign_only">日米以外のみ</option><option value="ambiguous">同名・親子関係など未確定</option></select></label>
                    <label>市場<select wire:model="editForm.market" class="mt-1 w-full rounded border border-app-border p-2"><option value="">未確認</option><option value="jp">日本株</option><option value="us">米国株</option></select></label>
                    <label>証券コード<input wire:model.blur="editForm.symbol_code" class="mt-1 w-full rounded border border-app-border p-2" maxlength="20"></label>
                    <label>上場主体名<input wire:model.blur="editForm.listed_entity_name" class="mt-1 w-full rounded border border-app-border p-2"></label>
                    <label>上場確認元URL<input wire:model.blur="editForm.listing_source_url" type="url" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2048"></label>
                    <label>上場確認日<input wire:model.blur="editForm.listing_confirmed_on" type="date" class="mt-1 w-full rounded border border-app-border p-2"></label>
                </div>
                <label class="block">同定の根拠・保留理由<textarea wire:model.blur="editForm.identification_note" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2000"></textarea></label>
                <div class="grid gap-3 md:grid-cols-2">
                    <label>主張<input wire:model.blur="editForm.claim" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2000"></label>
                    <label>根拠URL<input wire:model.blur="editForm.claim_evidence_url" type="url" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2048"></label>
                    <label>主張の確認状態<select wire:model="editForm.claim_status" class="mt-1 w-full rounded border border-app-border p-2"><option value="unverified">未確認</option><option value="verified">確認済み</option><option value="contradicted">不一致</option><option value="unavailable">確認不能</option></select></label>
                    <label>主張の確認日<input wire:model.blur="editForm.claim_checked_on" type="date" class="mt-1 w-full rounded border border-app-border p-2"></label>
                    <label class="flex items-center gap-2"><input wire:model="editForm.claim_primary" type="checkbox">一次資料で確認した主張</label>
                </div>
                <label class="block">反証・未確認事項<textarea wire:model.blur="editForm.counter_evidence" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2000"></textarea></label>
                <label class="block">反証の確認日<input wire:model.blur="editForm.counter_evidence_checked_on" type="date" class="mt-1 w-full rounded border border-app-border p-2"></label>
                @foreach ($errors->getMessages() as $field => $messages)
                    @if (str_starts_with($field, 'editForm.') && $field !== 'editForm.theme_relation')
                        @foreach ($messages as $message)<p role="alert" class="text-danger">{{ $message }}</p>@endforeach
                    @endif
                @endforeach
                @error('editForm')<p role="alert" class="text-danger">{{ $message }}</p>@enderror
                <x-btn type="submit" wire:loading.attr="disabled">訂正を保存</x-btn>
            </form>
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <form wire:submit="addClaim" class="space-y-2 rounded border border-app-border p-3 text-[13px]">
                    <h3 class="font-semibold">主張と根拠を追加</h3>
                    <p class="text-text-secondary">既存の主張を残して、新しい主張を別の版として記録します。</p>
                    <label class="block">主張 <span class="text-danger">必須</span><textarea wire:model.blur="claimForm.claim" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2000"></textarea></label>
                    <label class="block">根拠URL <span class="text-danger">必須</span><input wire:model.blur="claimForm.evidence_url" type="url" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2048"></label>
                    <label class="block">確認状態<select wire:model="claimForm.status" class="mt-1 w-full rounded border border-app-border p-2"><option value="unverified">未確認</option><option value="verified">確認済み</option><option value="contradicted">不一致</option><option value="unavailable">確認不能</option></select></label>
                    <label class="block">確認日<input wire:model.blur="claimForm.checked_on" type="date" class="mt-1 w-full rounded border border-app-border p-2"></label>
                    <label class="flex items-center gap-2"><input wire:model="claimForm.is_primary_source" type="checkbox">一次資料で確認した主張</label>
                    @foreach ($errors->getMessages() as $field => $messages)
                        @if ($field === 'claimForm' || str_starts_with($field, 'claimForm.'))
                            @foreach ($messages as $message)<p role="alert" class="text-danger">{{ $message }}</p>@endforeach
                        @endif
                    @endforeach
                    <x-btn type="submit" wire:loading.attr="disabled">主張を追加</x-btn>
                </form>
                <form wire:submit="addListing" class="space-y-2 rounded border border-app-border p-3 text-[13px]">
                    <h3 class="font-semibold">上場先と確認元を追加</h3>
                    <p class="text-text-secondary">同じ法人に複数の上場先がある場合、それぞれの確認元を記録します。</p>
                    <label class="block">市場 <span class="text-danger">必須</span><select wire:model="listingForm.market" class="mt-1 w-full rounded border border-app-border p-2"><option value="">選択してください</option><option value="jp">日本株</option><option value="us">米国株</option></select></label>
                    <label class="block">証券コード <span class="text-danger">必須</span><input wire:model.blur="listingForm.symbol_code" class="mt-1 w-full rounded border border-app-border p-2" maxlength="20"></label>
                    <label class="block">上場主体名 <span class="text-danger">必須</span><input wire:model.blur="listingForm.listed_entity_name" class="mt-1 w-full rounded border border-app-border p-2" maxlength="255"></label>
                    <label class="block">上場確認元URL <span class="text-danger">必須</span><input wire:model.blur="listingForm.source_url" type="url" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2048"></label>
                    <label class="block">上場確認日 <span class="text-danger">必須</span><input wire:model.blur="listingForm.confirmed_on" type="date" class="mt-1 w-full rounded border border-app-border p-2"></label>
                    @foreach ($errors->getMessages() as $field => $messages)
                        @if ($field === 'listingForm' || str_starts_with($field, 'listingForm.'))
                            @foreach ($messages as $message)<p role="alert" class="text-danger">{{ $message }}</p>@endforeach
                        @endif
                    @endforeach
                    <x-btn type="submit" wire:loading.attr="disabled">上場先を追加</x-btn>
                </form>
            </div>
        </x-card>
    @endif

    @if ($statusCandidateId !== null)
        <x-card>
            <h2 class="mb-2 font-semibold">候補 #{{ $statusCandidateId }} の状態を変更</h2>
            <p class="mb-2 text-[13px]">{{ ['on_hold' => '保留', 'rejected' => '見送り', 'investigating' => '調査中'][$newStatus] ?? $newStatus }}にする理由を記録してください。</p>
            <form wire:submit="saveStatus" class="space-y-2"><textarea wire:model.blur="statusReason" class="w-full rounded border border-app-border p-2" maxlength="2000"></textarea>@error('statusReason')<p class="text-danger">{{ $message }}</p>@enderror<x-btn type="submit">状態を保存</x-btn></form>
        </x-card>
    @endif

    @if ($archiveCandidateId !== null)
        <x-card>
            <h2 class="mb-2 font-semibold">候補 #{{ $archiveCandidateId }} をアーカイブ</h2>
            <p class="mb-3 text-[13px]">通常一覧と発見集計から除外します。元資料や他候補、既存のウォッチリストは削除せず復元できます。</p>
            <div class="flex gap-2"><x-btn wire:click="confirmArchive">アーカイブを確定</x-btn><x-btn variant="secondary" wire:click="$set('archiveCandidateId', null)">キャンセル</x-btn></div>
        </x-card>
    @endif

    @if (isset($handoff['candidate_id']))
        <x-card>
            <h2 class="mb-2 text-base font-semibold">監視への受け渡し確認</h2>
            <p class="text-[13px]">候補 #{{ $handoff['candidate_id'] }}／確認した版 {{ $handoff['lock_version'] ?? '—' }}。一次資料、上場主体、反証、CSVと保有の照合時点を確認して確定します。</p>
            @if ($handoffCandidate)
                <dl class="mt-3 grid gap-x-4 gap-y-2 text-[12px] md:grid-cols-2">
                    <div><dt class="font-medium">元資料・元発表日</dt><dd>{{ $handoffCandidate->event->original_url ?: '未確認' }}／{{ $handoffCandidate->event->announced_on?->format('Y-m-d') ?? '不明' }}</dd></div>
                    <div><dt class="font-medium">法人・役割</dt><dd>{{ $handoffCandidate->entity->legal_name }}／{{ $handoffCandidate->entity_role ?: '未確認' }}</dd></div>
                    <div><dt class="font-medium">テーマとの関係</dt><dd>{{ $handoffCandidate->theme }}／{{ $handoffCandidate->theme_relation }}</dd></div>
                    <div><dt class="font-medium">反証・未確認事項</dt><dd>{{ $handoffCandidate->counter_evidence ?: '未記録' }}／確認 {{ $handoffCandidate->counter_evidence_checked_on?->format('Y-m-d') ?? '不明' }}</dd></div>
                    <div><dt class="font-medium">保有スナップショット</dt><dd>{{ $handoff['matched_snapshot_at'] ?? '照合未実施' }}</dd></div>
                    <div><dt class="font-medium">お気に入りCSV取込</dt><dd>{{ $handoff['favorites_last_imported_at'] ?? '照合未実施' }}</dd></div>
                </dl>
                <div class="mt-3 text-[12px]"><span class="font-medium">確認した主張</span>@forelse ($handoffCandidate->claims as $handoffClaim)<p>{{ $handoffClaim->status }}・{{ $handoffClaim->is_primary_source ? '一次資料' : '紹介資料' }}・{{ $handoffClaim->claim }}／{{ $handoffClaim->evidence_url ?: '根拠URLなし' }}</p>@empty <p>確認した主張はありません。</p>@endforelse</div>
            @endif
            @if (count($handoff['listings'] ?? []) > 0)
                <fieldset class="mt-3 space-y-1 text-[13px]">
                    <legend class="font-medium">登録する上場主体・市場・銘柄コード</legend>
                    @foreach ($handoff['listings'] as $listingChoice)
                        <label class="flex items-center gap-2">
                            <input type="radio" name="handoff-listing" value="{{ $listingChoice['id'] }}" wire:click="selectHandoffListing({{ $listingChoice['id'] }})" @checked(($handoff['listing_id'] ?? null) === $listingChoice['id'])>
                            {{ $listingChoice['listed_entity_name'] }}／{{ strtoupper($listingChoice['market']) }} {{ $listingChoice['symbol_code'] }}
                        </label>
                    @endforeach
                </fieldset>
            @endif
            @if (! empty($handoff['error']))<p class="mt-2 text-[13px] text-amber-700">{{ $handoff['error'] }}</p>@endif
            @error('handoff')<p role="alert" class="mt-2 text-[13px] text-danger">{{ $message }}</p>@enderror
            @if (empty($handoff['error']))
                <form wire:submit="confirmPreparedHandoff" class="mt-3 space-y-2 text-[13px]">
                    <label class="block">監視を選択する理由<textarea wire:model.blur="handoffReason" class="mt-1 w-full rounded border border-app-border p-2" maxlength="2000"></textarea></label>
                    <div class="flex gap-2"><x-btn type="submit" wire:loading.attr="disabled">確認して監視に追加</x-btn><x-btn variant="secondary" wire:click="$set('handoff', [])">キャンセル</x-btn></div>
                </form>
            @endif
            <a href="/candidate-check" wire:navigate class="mt-3 inline-block text-[12px] text-primary underline">ウォッチリストを開く</a>
        </x-card>
    @endif
</div>
