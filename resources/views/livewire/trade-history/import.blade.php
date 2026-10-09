<div>
    <x-page-header
        title="売買履歴の取込"
        caption="楽天証券の国内株式・米国株式の売買履歴CSV（全期間）を取り込み、保有CSVと照合します"
        backTo="/csv-import"
        backLabel="CSV取込"
    />

    <x-card>
        @if ($importError)
            <div class="mb-4 px-4 py-3 rounded-md bg-red-50 text-danger text-[13px]">
                取込に失敗しました: {{ $importError }}
            </div>
        @endif

        <form wire:submit="runPreview" class="flex flex-col gap-4">
            <div>
                <label for="jp_trade_file" class="block text-[13px] font-medium mb-1">国内株式の売買履歴CSV</label>
                <input type="file" id="jp_trade_file" wire:model="jp_trade_file" class="text-[13px]">
                @error('jp_trade_file')
                    <p class="text-danger text-[13px] mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="us_trade_file" class="block text-[13px] font-medium mb-1">米国株式の売買履歴CSV</label>
                <input type="file" id="us_trade_file" wire:model="us_trade_file" class="text-[13px]">
                @error('us_trade_file')
                    <p class="text-danger text-[13px] mt-1">{{ $message }}</p>
                @enderror
            </div>
            <x-btn type="submit" variant="secondary" class="w-fit">プレビュー</x-btn>
        </form>
    </x-card>

    @if ($previewError)
        <x-card accent="danger">
            <p class="text-danger text-[13px]">読み取れませんでした: {{ $previewError }}</p>
        </x-card>
    @endif

    @if ($preview)
        <x-card>
            <h2 class="text-base font-semibold mb-4">プレビュー</h2>
            <p class="text-[13px] text-text-secondary mb-3">
                対象期間: {{ $preview['periodFrom'] ?? '-' }} 〜 {{ $preview['periodTo'] ?? '-' }}
            </p>
            <table class="w-full text-[13px] mb-4">
                <tbody>
                    <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">総行数</th><td class="py-2">{{ $preview['totalRows'] }}</td></tr>
                    <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">新規</th><td class="py-2">{{ $preview['newRows'] }}</td></tr>
                    <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">既存</th><td class="py-2">{{ $preview['existingRows'] }}</td></tr>
                    <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">確認待ち（前回あって今回ない行）</th><td class="py-2">{{ $preview['missingRows'] }}</td></tr>
                    <tr><th class="py-2 pr-4 text-left font-medium">読み飛ばし</th><td class="py-2">{{ $preview['errorCount'] }}</td></tr>
                </tbody>
            </table>
            <x-btn wire:click="confirm" class="w-fit">取込を確定</x-btn>
        </x-card>
    @endif

    @if ($result)
        <x-card>
            <h2 class="text-base font-semibold mb-4">取込が完了しました</h2>
            <p class="text-[13px] text-text-secondary mb-3">
                対象期間: {{ $result['periodFrom'] ?? '-' }} 〜 {{ $result['periodTo'] ?? '-' }}
            </p>
            <table class="w-full text-[13px] mb-6">
                <tbody>
                    <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">総行数</th><td class="py-2">{{ $result['totalRows'] }}</td></tr>
                    <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">新規</th><td class="py-2">{{ $result['newRows'] }}</td></tr>
                    <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">既存</th><td class="py-2">{{ $result['existingRows'] }}</td></tr>
                    <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">確認待ち（前回あって今回ない行）</th><td class="py-2">{{ $result['missingRows'] }}</td></tr>
                    <tr><th class="py-2 pr-4 text-left font-medium">読み飛ばし</th><td class="py-2">{{ $result['errorCount'] }}</td></tr>
                </tbody>
            </table>

            <h3 class="text-[15px] font-semibold mb-2">保有CSVとの照合</h3>
            @if ($result['reconciliation'] === null)
                <p class="text-[13px] text-text-secondary">保有CSVがまだ取り込まれていないため、照合は行っていません</p>
            @else
                <p class="text-[13px] text-text-secondary mb-3">照合に使った保有CSV: {{ $snapshotDate }} 時点</p>
                <table class="w-full text-[13px] mb-4">
                    <tbody>
                        <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">照合済み</th><td class="py-2">{{ $result['reconciliation']['counts']['matched'] }}</td></tr>
                        <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">保有側のみ</th><td class="py-2">{{ $result['reconciliation']['counts']['snapshot_only'] }}</td></tr>
                        <tr class="border-b border-app-border"><th class="py-2 pr-4 text-left font-medium">履歴側のみ</th><td class="py-2">{{ $result['reconciliation']['counts']['history_only'] }}</td></tr>
                        <tr><th class="py-2 pr-4 text-left font-medium">確認待ち</th><td class="py-2">{{ $result['reconciliation']['counts']['needs_review'] }}</td></tr>
                    </tbody>
                </table>

                @if ($details->isEmpty())
                    <p class="text-[13px] text-text-secondary">一致しなかった銘柄はありません</p>
                @else
                    @php
                        $statusLabels = ['snapshot_only' => '保有側のみ', 'history_only' => '履歴側のみ', 'needs_review' => '確認待ち'];
                        $accountLabels = ['specific' => '特定', 'general' => '一般', 'nisa_growth' => 'NISA成長', 'nisa_tsumitate' => 'NISAつみたて'];
                    @endphp
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="text-left text-text-secondary border-b border-app-border">
                                <th class="py-2 pr-4">銘柄コード</th>
                                <th class="py-2 pr-4">口座</th>
                                <th class="py-2 pr-4">履歴の株数</th>
                                <th class="py-2 pr-4">保有CSVの株数</th>
                                <th class="py-2 pr-4">状態</th>
                                <th class="py-2 pr-4">理由</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($details as $item)
                                <tr class="border-b border-app-border last:border-b-0">
                                    <td class="py-2 pr-4">{{ $item->holding->symbol_code }}</td>
                                    <td class="py-2 pr-4">{{ $accountLabels[$item->account_type] ?? $item->account_type }}</td>
                                    <td class="py-2 pr-4">{{ $item->history_quantity === null ? '-' : rtrim(rtrim(number_format((float) $item->history_quantity, 4, '.', ''), '0'), '.') }}</td>
                                    <td class="py-2 pr-4">{{ $item->snapshot_quantity === null ? '-' : rtrim(rtrim(number_format((float) $item->snapshot_quantity, 4, '.', ''), '0'), '.') }}</td>
                                    <td class="py-2 pr-4"><x-badge variant="{{ $item->status === 'needs_review' ? 'warning' : 'neutral' }}">{{ $statusLabels[$item->status] ?? $item->status }}</x-badge></td>
                                    <td class="py-2 pr-4">{{ $item->reason ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($moreDetails > 0)
                        <p class="text-[13px] text-text-secondary mt-2">ほか{{ $moreDetails }}件</p>
                    @endif
                @endif
            @endif
        </x-card>
    @endif

    <x-card>
        <h2 class="text-base font-semibold mb-4">取込履歴</h2>

        @if ($recentImports->isEmpty())
            <x-empty-state>まだ売買履歴の取込はありません</x-empty-state>
        @else
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-2 pr-4">取込日時</th>
                        <th class="py-2 pr-4">国内株式</th>
                        <th class="py-2 pr-4">米国株式</th>
                        <th class="py-2 pr-4">状態</th>
                        <th class="py-2 pr-4">新規</th>
                        <th class="py-2 pr-4">確認待ち</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentImports as $import)
                        <tr class="border-b border-app-border last:border-b-0">
                            <td class="py-2 pr-4">{{ \App\Support\DisplayTime::dateTime($import->imported_at) ?? '-' }}</td>
                            <td class="py-2 pr-4">{{ $import->jp_filename }}</td>
                            <td class="py-2 pr-4">{{ $import->us_filename }}</td>
                            <td class="py-2 pr-4">
                                <x-badge variant="{{ $import->status === 'completed' ? 'success' : ($import->status === 'failed' ? 'danger' : 'neutral') }}">
                                    {{ $import->status }}
                                </x-badge>
                            </td>
                            <td class="py-2 pr-4">{{ $import->new_rows }}</td>
                            <td class="py-2 pr-4">{{ $import->missing_rows }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>
</div>
