<div class="space-y-6">
    <x-page-header title="新規投資候補" caption="楽天証券のお気に入り銘柄のうち、まだ保有していない銘柄を新規投資候補として一覧表示します（UC-012）" />

    {{-- 取込・更新バー --}}
    <x-card>
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-[13px] font-medium mb-1">お気に入り銘柄CSV</label>
                <input type="file" wire:model="favorites_csv_file" accept=".csv"
                    class="text-[13px] file:mr-2 file:rounded file:border file:border-app-border file:bg-app-bg file:px-3 file:py-1.5 file:text-[13px]">
                @error('favorites_csv_file') <p class="mt-1 text-[12px] text-danger">{{ $message }}</p> @enderror
            </div>
            <x-btn wire:click="importFavorites" wire:loading.attr="disabled">取り込む</x-btn>
            <x-btn variant="secondary" wire:click="refreshAll" wire:loading.attr="disabled">保有＋お気に入りを一括更新</x-btn>

            <div class="ml-auto text-right text-[12px] text-text-secondary" wire:poll.10s>
                @if ($activeRun && in_array($activeRun->status, ['queued', 'processing']))
                    <span class="text-primary font-medium">更新中 {{ $activeRun->processed_count }}/{{ $activeRun->total_count }}</span>
                @elseif ($activeRun && $activeRun->finished_at)
                    最終更新: {{ $activeRun->finished_at->format('Y-m-d H:i') }}
                    @if ($activeRun->failed_count > 0)（{{ $activeRun->failed_count }}件は取得失敗）@endif
                @endif
                <div>ウォッチリスト {{ $watchlistCount }}件 / 未保有 {{ count($rows) }}件</div>
            </div>
        </div>

        @if ($importError) <p class="mt-3 text-[13px] text-danger">{{ $importError }}</p> @endif
        @if ($importMessage) <p class="mt-3 text-[13px] text-success">{{ $importMessage }}</p> @endif
    </x-card>

    @if ($watchlistCount === 0)
        <x-empty-state>お気に入り銘柄CSVを取り込んでください</x-empty-state>
    @elseif (count($rows) === 0)
        <x-empty-state>未保有のお気に入り銘柄はありません</x-empty-state>
    @else
        {{-- フィルタ --}}
        <div class="flex flex-wrap items-center gap-4 text-[13px]">
            <label class="flex items-center gap-2">
                <span>フォルダ</span>
                <select wire:model.live="folderFilter" class="rounded border border-app-border px-2 py-1 text-[13px]">
                    <option value="">すべて</option>
                    @foreach ($folders as $folder)
                        <option value="{{ $folder }}">{{ $folder }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="starredOnly">
                <span>★お気に入りのみ</span>
            </label>
            <span class="text-text-secondary">{{ count($visibleRows) }}件表示</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-[12px] border border-app-border [&_td]:border [&_td]:border-app-border [&_th]:border [&_th]:border-app-border [&_td]:px-2 [&_td]:py-1.5 [&_th]:px-2 [&_th]:py-1.5 [&_td]:align-top">
                <thead class="bg-app-bg text-left">
                    <tr>
                        <th class="w-8">★</th>
                        <th>銘柄</th>
                        <th>市場</th>
                        <th>フォルダ</th>
                        <th class="text-right">現在値</th>
                        <th class="text-right">52週内位置</th>
                        <th class="text-right">同ｾｸﾀｰ保有比率</th>
                        <th class="text-right">押し目</th>
                        <th>財務健全性</th>
                        <th class="text-right">RSI</th>
                        <th class="text-right">PER</th>
                        <th class="text-right">PBR</th>
                        <th class="text-right">ROE</th>
                        <th class="text-right">自己資本比率</th>
                        <th class="text-right">営業利益率</th>
                        <th class="w-[420px]">判定チェックリスト</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($visibleRows as $row)
                        <tr wire:key="wl-{{ $row['watchlist_item_id'] }}">
                            <td class="text-center">
                                <button wire:click="toggleStar({{ $row['watchlist_item_id'] }})"
                                    class="text-base {{ $row['is_starred'] ? 'text-amber-500' : 'text-slate-300' }}">★</button>
                            </td>
                            <td>
                                <button wire:click="toggleExpand('{{ $row['symbol_code'] }}')" class="text-left" data-symbol-code="{{ $row['symbol_code'] }}">
                                    <span class="font-medium">{{ $row['symbol_name'] }}</span>
                                    <span class="block text-[11px] text-text-secondary">{{ $row['symbol_code'] }}</span>
                                </button>
                                <x-signal-criteria-summary-badges :criteria="$row['criteria']" />
                                @unless ($row['in_rakuten_favorites'])
                                    <span class="mt-1 inline-block rounded bg-slate-100 px-1 text-[10px] text-slate-500">楽天お気に入り解除済</span>
                                @endunless
                                @if ($row['nisa_recommended'])
                                    <span class="mt-1 inline-block rounded bg-blue-50 px-1 text-[10px] text-primary">NISA推奨</span>
                                @endif
                            </td>
                            <td>{{ $row['market'] === 'jp' ? '日本株' : '米国株' }}</td>
                            <td>{{ $row['folder_name'] }}</td>
                            <td class="text-right tabular-nums">{{ $row['current_price'] !== null ? number_format($row['current_price'], 1) : '—' }}</td>
                            <td class="text-right tabular-nums">{{ $row['week52_range_position'] !== null ? number_format($row['week52_range_position'] * 100, 0).'%' : '—' }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['overlap_rate'], 1) }}%</td>
                            <td class="text-right tabular-nums">{{ $row['rebound_buy_signal_count'] }}</td>
                            <td>
                                @php
                                    $fs = $row['fundamental_status'];
                                    $fsVariant = $fs === 'passed' ? 'success' : ($fs === 'failed' ? 'danger' : 'neutral');
                                    $fsLabel = $fs === 'passed' ? '健全' : ($fs === 'failed' ? '基準割れ' : '取得不可');
                                @endphp
                                <x-badge :variant="$fsVariant">{{ $fsLabel }}</x-badge>
                                <span class="block text-[11px] text-text-secondary">{{ $row['fundamental_summary'] }}</span>
                            </td>
                            <td class="text-right tabular-nums">{{ $row['rsi'] !== null ? number_format((float) $row['rsi'], 1) : '—' }}</td>
                            <td class="text-right tabular-nums">{{ $row['per'] !== null ? number_format((float) $row['per'], 1) : '—' }}</td>
                            <td class="text-right tabular-nums">{{ $row['pbr'] !== null ? number_format((float) $row['pbr'], 2) : '—' }}</td>
                            <td class="text-right tabular-nums">{{ $row['roe'] !== null ? number_format((float) $row['roe'], 1).'%' : '—' }}</td>
                            <td class="text-right tabular-nums">{{ $row['equity_ratio'] !== null ? number_format((float) $row['equity_ratio'], 1).'%' : '—' }}</td>
                            <td class="text-right tabular-nums">{{ $row['operating_margin'] !== null ? number_format((float) $row['operating_margin'], 1).'%' : '—' }}</td>
                            <td>
                                <div class="grid grid-cols-4 gap-1">
                                    @foreach (array_merge($row['criteria']['technical'], $row['criteria']['fundamental']) as $item)
                                        <x-criteria-chip :item="$item" />
                                    @endforeach
                                </div>
                            </td>
                        </tr>

                        @if ($expandedSymbol === $row['symbol_code'] && $expandedDetail)
                            <tr wire:key="wl-detail-{{ $row['watchlist_item_id'] }}">
                                <td colspan="16" class="bg-app-bg">
                                    <div class="space-y-3 p-2">
                                        <p class="text-[13px]">{{ $expandedDetail['diversification_comment'] }}</p>

                                        @if (! empty($expandedDetail['historical_performance']))
                                            <div>
                                                <h3 class="text-[13px] font-semibold mb-1">過去の業績推移</h3>
                                                <table class="text-[12px] [&_td]:px-2 [&_td]:py-0.5 [&_th]:px-2 [&_th]:py-0.5">
                                                    <thead><tr><th>決算期</th><th class="text-right">売上高</th><th class="text-right">営業利益</th></tr></thead>
                                                    <tbody>
                                                        @foreach ($expandedDetail['historical_performance'] as $p)
                                                            <tr><td>{{ $p['fiscal_period'] }}</td><td class="text-right tabular-nums">{{ $p['revenue'] }}</td><td class="text-right tabular-nums">{{ $p['operating_income'] }}</td></tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif

                                        <div>
                                            <h3 class="text-[13px] font-semibold mb-1">ウォッチステータス・メモ</h3>
                                            @if (! empty($expandedDetail['watch_memo_history']))
                                                <ul class="mb-2 space-y-1 text-[12px] text-text-secondary">
                                                    @foreach ($expandedDetail['watch_memo_history'] as $w)
                                                        <li>{{ $w['recorded_at'] }} [{{ $w['watch_status'] }}] {{ $w['memo'] }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                            <div class="flex flex-wrap items-center gap-2">
                                                <select wire:model="watchStatus" class="rounded border border-app-border px-2 py-1 text-[13px]">
                                                    <option value="">ステータス選択</option>
                                                    @foreach ($watchStatusOptions as $opt)
                                                        <option value="{{ $opt }}">{{ $opt }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="text" wire:model="watchMemo" placeholder="メモ（任意）"
                                                    class="flex-1 rounded border border-app-border px-2 py-1 text-[13px]">
                                                <x-btn wire:click="saveWatchRecord">記録</x-btn>
                                            </div>
                                            @error('watchRecord') <p class="mt-1 text-[12px] text-danger">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
