<section class="space-y-4" aria-label="Webから見つかった企業候補">
    <div class="grid gap-3 sm:grid-cols-2">
        <x-card><p class="text-[13px] text-text-secondary">抽出された企業候補（資料ごとの仮の候補数）</p><p class="text-xl font-semibold">{{ $automation['draft_count'] }}</p></x-card>
        <x-card><p class="text-[13px] text-text-secondary">参照した資料</p><p class="text-xl font-semibold">{{ $automation['document_count'] }}</p></x-card>
    </div>
    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-base font-semibold">企業候補</h2><p class="text-[12px] text-text-secondary">検出日が新しい順です。掲載は投資の推奨順位ではありません。初回取得・過去資料は新着に数えません。</p></div>
            <label class="text-[13px]">状態<select wire:model.live="draftDispositionFilter" class="ml-2 rounded border border-app-border p-2"><option value="">確認対象</option><option value="unreviewed">未確認</option><option value="reviewing">確認中</option><option value="imported">取り込み済み</option><option value="on_hold">保留</option><option value="rejected">見送り</option><option value="out_of_scope">対象外</option></select></label>
        </div>
        <div class="mt-3 max-w-full overflow-x-auto">
            <table class="w-full min-w-[780px] text-left text-[13px]">
                <thead><tr class="border-b border-app-border"><th class="p-2">企業・上場主体</th><th class="p-2">テーマ・役割</th><th class="p-2">資料・状態</th><th class="p-2">次の操作</th></tr></thead>
                <tbody>
                @forelse($automation['drafts'] as $draft)
                    @php $fields = json_decode($draft->extracted_fields, true); @endphp
                    <tr wire:key="draft-{{ $draft->id }}" class="border-b border-app-border align-top">
                        <td class="p-2"><strong>{{ $draft->raw_name }}</strong><p class="text-[12px] text-text-secondary">上場主体の本人確認待ち</p></td>
                        <td class="p-2">{{ implode('、', $fields['themes'] ?? ['未分類']) }}<p class="text-[12px]">{{ implode('、', $fields['roles'] ?? []) }}</p><p class="text-[12px] text-text-secondary">資料の掲載・採択だけでは売上を確認できません。</p></td>
                        <td class="p-2">{{ match($draft->discovery_kind) { 'new' => '前回取得以降の新着', 'baseline' => '初回取得・過去資料', default => '既存資料の後処理' } }}<p>{{ match($draft->disposition) { 'imported' => '調査記録へ取り込み済み', 'on_hold' => '保留', 'rejected' => '見送り', 'out_of_scope' => '対象外', 'reviewing' => '確認中', default => '自動抽出・本人未確認' } }}</p>@if($draft->presence === 'removed')<p class="text-warning">最新資料から言及がなくなりました。</p>@endif</td>
                        <td class="p-2"><x-btn variant="secondary" type="button" wire:click="showDraft({{ $draft->id }})" wire:loading.attr="disabled">候補を確認</x-btn></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-3">この確認範囲では、表示対象の企業候補がありません。未解析・未対応の情報源を含め、市場全体を確認した結果ではありません。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $automation['drafts']->links() }}</div>
    </x-card>

    @if($draftReview !== [])
        <x-card>
            <div class="mb-4 flex flex-wrap justify-between gap-2"><h2 class="text-lg font-semibold">{{ $draftReview['draft']['raw_name'] }} — 候補の確認</h2><button type="button" wire:click="closeDraft" class="text-[13px] text-primary">一覧へ戻る</button></div>
            <p class="mb-4 text-[13px] text-text-secondary">取得した企業・出典を入力し直す必要はありません。取り込みは監視登録とは別の操作です。</p>
            @if($draftReview['draft']['presence'] === 'removed')<p class="mb-3 rounded border border-amber-200 p-3 text-warning">最新資料からこの法人への言及がなくなりました。以前の抽出値を保持しています。掲載の消失と調査記録の確認事項を確認してください。</p>@endif
            @if($draftReview['draft']['reviewed_version_id'] !== null && $draftReview['draft']['reviewed_version_id'] !== $draftReview['document_version_id'])<p class="mb-3 text-warning">前回確認した後に元資料が更新されています。最新資料と既存の調査記録を再確認してください。</p>@endif
            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-2">
                    <section><h3 class="font-semibold">Webから取得した情報</h3><dl class="mt-2 grid grid-cols-[7rem_minmax(0,1fr)] gap-2 text-[13px]">
                        <dt>言及された法人</dt><dd class="break-words">{{ $draftReview['draft']['raw_name'] }}</dd>
                        <dt>テーマ</dt><dd>{{ implode('、', $draftReview['fields']['themes'] ?? ['未分類']) }}</dd>
                        <dt>資料内の役割</dt><dd>{{ implode('、', $draftReview['fields']['roles'] ?? []) }}</dd>
                        <dt>資料からの要点</dt><dd class="break-words">{{ $draftReview['fields']['generated_summary'] ?? $draftReview['fields']['summary'] ?? '下記の取得フィールドを確認してください。' }}</dd>
                        <dt>本人の訂正</dt><dd>{{ implode('、', $draftReview['overrides']['roles'] ?? []) ?: 'なし' }}</dd>
                        <dt>資料名</dt><dd class="break-words">{{ $draftReview['source']['source_title'] }}</dd>
                        <dt>公開日</dt><dd>{{ $draftReview['source']['published_at'] ?? '不明' }}（決定日とは別）</dd>
                    </dl></section>
                    <section><h3 class="font-semibold">出典と抽出の根拠</h3><a href="{{ $draftReview['source']['source_url'] }}" target="_blank" rel="noopener noreferrer" class="break-all text-primary">元資料を開く</a>
                        <dl class="mt-2 space-y-1 rounded border border-app-border p-3 text-[12px]">
                        @foreach($draftReview['source']['source_fields'] ?? [] as $key => $value)
                            @if(is_scalar($value) || $value === null)<div><dt class="inline font-semibold">{{ $key }}：</dt><dd class="inline break-words">{{ $value ?? '不明' }}</dd></div>@endif
                        @endforeach
                        </dl>
                    </section>
                    <section><h3 class="font-semibold">上場主体・登録状況</h3><p class="mt-2 text-[13px]">上場先候補（本人確認待ち）</p>
                        @forelse($draftReview['matches'] as $match)<p class="text-[13px]">{{ $match['legal_name'] }} / {{ strtoupper($match['market']) }} {{ $match['symbol_code'] }}・{{ $match['exchange'] }}</p><p class="text-[12px] text-text-secondary">{{ $match['reason'] }}</p>@empty<p class="text-[13px]">上場先は未同定です。</p>@endforelse
                        @foreach($draftReview['warnings'] as $warning)<p class="mt-1 text-[13px] text-warning">{{ $warning['code'] === 'missing' ? '照合元欠測: ' : '' }}{{ $warning['message'] }}</p>@endforeach
                        <p class="mt-2 text-[12px] text-text-secondary">上場先候補は本人による上場確認を示しません。保有・お気に入りCSVとの照合は、調査記録の監視登録時に再確認します。</p>
                    </section>
                    <details class="rounded border border-app-border p-3"><summary>訂正と判断の履歴</summary><div class="mt-2 space-y-3 text-[12px]">
                        @foreach($draftReview['history'] as $revision)
                            @php
                                $change = json_decode($revision['payload'], true);
                                $before = $change['before'] ?? [];
                                $after = $change['after'] ?? $change;
                            @endphp
                            <div class="border-b border-app-border pb-2"><p>版{{ $revision['lock_version'] }}・{{ match($revision['change_type']) { 'corrected' => '本人による訂正・再確認', 'imported' => '調査記録へ取り込み', 'disposition_changed' => '判断を変更', 'restored' => '未確認へ戻す', 'source_changed' => '資料の更新', 'source_removed' => '資料から言及が消失', default => '資料から抽出' } }}・{{ $revision['created_at'] }}</p>
                            @if(isset($before['disposition']))<p>変更前: {{ match($before['disposition']) { 'on_hold' => '保留', 'rejected' => '見送り', 'out_of_scope' => '対象外', 'imported' => '取り込み済み', default => '確認中・未確認' } }} / {{ $before['reason'] ?? '理由の記録なし' }}</p>@endif
                            @if(isset($after['disposition']))<p>変更後: {{ match($after['disposition']) { 'on_hold' => '保留', 'rejected' => '見送り', 'out_of_scope' => '対象外', 'imported' => '取り込み済み', default => '確認中・未確認' } }} / {{ $after['reason'] ?? '理由の記録なし' }}</p>@endif
                            @foreach(['before' => '訂正前', 'after' => '訂正後'] as $side => $label)
                                @php $correction = $change[$side]['human_overrides'] ?? []; if(is_string($correction)) { $correction = json_decode($correction, true) ?? []; } @endphp
                                @if(isset($correction['roles']))<p>{{ $label }}の役割: {{ implode('、', $correction['roles']) }}</p>@endif
                            @endforeach
                            </div>
                        @endforeach
                    </div></details>
                </div>
                <aside class="space-y-4 text-[13px]">
                    <section class="rounded border border-app-border p-3"><h3 class="font-semibold">{{ $draftReview['draft']['imported_candidate_id'] ? '最新資料を再確認' : '調査記録へ取り込む' }}</h3>
                        <label class="mt-3 flex items-start gap-2"><input type="checkbox" wire:model.live="draftConfirmed" class="mt-1"><span>企業名・製品・資料からの要点を、元資料と照合しました。</span></label>
                        @error('draftConfirmed')<p role="alert" class="text-danger">元資料との照合を確認してください。</p>@enderror
                        <p class="mt-2 text-[12px] text-text-secondary">確認日はこの操作の実行日です。取得日を本人の確認日には使いません。</p>
                        @if($draftReview['draft']['imported_candidate_id'])
                            <x-btn type="button" class="mt-3" wire:click="reconfirmDraft" wire:loading.attr="disabled" :disabled="!$draftConfirmed">最新資料の本人確認を記録</x-btn>
                            <p class="mt-2 text-[12px]">取り込み済み本文や主張は更新しません。必要な訂正は調査記録で行ってください。</p>
                        @else
                            <label class="mt-3 block">本人の追記（任意）<textarea wire:model.blur="draftNote" maxlength="2000" rows="3" class="mt-1 w-full rounded border border-app-border p-2"></textarea></label>
                            <details class="mt-2"><summary>同じ元発表・法人へ関連付ける</summary><label class="mt-2 block">既存の元発表<select wire:model="draftExistingEventId" class="w-full rounded border border-app-border p-2"><option value="">新しい元発表として記録</option>@foreach($draftReview['existing_events'] as $option)<option value="{{ $option['id'] }}">{{ $option['title'] }}{{ $option['announced_on'] ? '（'.substr($option['announced_on'], 0, 10).'）' : '' }}</option>@endforeach</select></label><label class="mt-2 block">既存の法人<select wire:model="draftExistingEntityId" class="w-full rounded border border-app-border p-2"><option value="">新しい法人として記録</option>@foreach($draftReview['existing_entities'] as $option)<option value="{{ $option['id'] }}">{{ $option['legal_name'] }}</option>@endforeach</select></label><p class="mt-1 text-[12px]">直近200件の記録から、同じ発表・法人と確認できた場合に選択してください。</p></details>
                            <x-btn type="button" class="mt-3" wire:click="importDraft" wire:loading.attr="disabled" :disabled="!$draftConfirmed">確認して調査記録へ</x-btn>
                        @endif
                    </section>
                    <section class="rounded border border-amber-200 bg-amber-50 p-3"><h3 class="font-semibold">監視登録までの確認事項</h3><p class="mt-2">上場主体と元発表日、一次資料の主張、反証の確認が必要です。</p><button type="button" disabled class="mt-3 rounded border border-app-border px-3 py-2 opacity-60">監視に追加（調査記録で確認）</button></section>
                    <details class="rounded border border-app-border p-3"><summary>保留・見送り・対象外</summary><label class="mt-2 block">判断理由<textarea wire:model.blur="draftDispositionReason" maxlength="2000" class="mt-1 w-full rounded border border-app-border p-2"></textarea></label><div class="mt-2 flex flex-wrap gap-2"><x-btn variant="secondary" type="button" wire:click="saveDraftDisposition('on_hold')">保留</x-btn><x-btn variant="secondary" type="button" wire:click="saveDraftDisposition('rejected')">見送り</x-btn><x-btn variant="secondary" type="button" wire:click="saveDraftDisposition('out_of_scope')">対象外</x-btn><x-btn variant="secondary" type="button" wire:click="restoreDraft({{ $draftReview['id'] }}, {{ $draftReview['lock_version'] }})">未確認へ戻す</x-btn></div></details>
                    <details class="rounded border border-app-border p-3"><summary>取得内容を訂正する</summary><p class="mt-2 text-[12px]">抽出値を保持し、本人の訂正を別に保存します。</p><label class="mt-2 block">企業の役割<input wire:model.blur="draftRoleCorrection" maxlength="255" class="mt-1 w-full rounded border border-app-border p-2"></label><x-btn variant="secondary" type="button" class="mt-2" wire:click="saveDraftCorrection">訂正を保存</x-btn></details>
                </aside>
            </div>
            @error('draft')<p role="alert" class="mt-3 text-danger">{{ $message }}</p>@enderror
        </x-card>
    @endif
    <x-card><h2 class="text-base font-semibold">資料のみ</h2><p class="mb-3 text-[12px] text-text-secondary">解析待ち・企業未抽出・未対応・失敗を区別します。企業候補数には含めません。</p>
        @forelse($automation['documents'] as $document)<article class="border-t border-app-border py-2 text-[13px]"><a href="{{ $document->url }}" target="_blank" rel="noopener noreferrer" class="break-words text-primary">{{ $document->title }}</a><p>{{ match($document->extraction_state) { 'pending' => '企業抽出は未解析です。次の処理で続けます。', 'failed' => '解析に失敗しています。候補なしとは扱いません。', 'unsupported' => '企業抽出は未対応です。', default => 'この資料から企業を抽出できませんでした。' } }}</p></article>@empty<p class="text-[13px] text-text-secondary">表示対象の資料はありません。未対応の情報源まで確認した結果ではありません。</p>@endforelse
    </x-card>
</section>
