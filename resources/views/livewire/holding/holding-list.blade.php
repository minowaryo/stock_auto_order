@php
    $indexLabels = [
        'nikkei225' => '日経平均',
        'sp500' => 'S&P500',
        'us10y' => '米国10年債利回り',
        'vix' => 'VIX指数',
        'usdjpy' => 'USD/JPY',
    ];
@endphp
<div>
    <x-page-header title="保有銘柄一覧" />

    <x-card>
        <h2 class="text-base font-semibold mb-2">市場全体指標</h2>
        <div class="grid grid-cols-5 gap-2">
            @foreach ($marketIndicators as $indicator)
                {{-- ラベルはハードコードされたindexLabels配列由来（ユーザー入力ではない）ため、
                     "S&P500" のようなHTML特殊文字を含むラベルもエスケープせずそのまま出す。 --}}
                <x-stat-box>
                    <x-slot:label>{!! $indexLabels[$indicator['index_name']] ?? $indicator['index_name'] !!}</x-slot:label>
                    {{ $indicator['value'] ?? '取得不可' }}
                </x-stat-box>
            @endforeach
        </div>
    </x-card>

    <x-card>
        <div class="flex items-center gap-4">
            <label class="text-[13px]">
                セクター
                <select wire:model.live="sector" class="ml-1 border border-app-border rounded px-2 py-1 text-[13px]">
                    <option value="">すべて</option>
                    @foreach ($sectorOptions as $sectorName)
                        <option value="{{ $sectorName }}">{{ $sectorName }}</option>
                    @endforeach
                    <option value="未分類">未分類</option>
                </select>
            </label>
            <label class="text-[13px] flex items-center gap-1">
                <input type="checkbox" wire:model.live="signalOnly">
                シグナルのみ表示
            </label>
        </div>
    </x-card>

    @if (empty($holdings))
        <x-card>
            <x-empty-state>
                CSV取込が必要です<br>
                <a href="/csv-import" wire:navigate class="text-primary hover:underline">CSV取込画面へ</a>
            </x-empty-state>
        </x-card>
    @else
        <x-card>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-2 pr-4">銘柄</th>
                        <th class="py-2 pr-4">コード</th>
                        <th class="py-2 pr-4">市場</th>
                        <th class="py-2 pr-4">種別</th>
                        <th class="py-2 pr-4">数量</th>
                        <th class="py-2 pr-4">平均取得単価</th>
                        <th class="py-2 pr-4">現在値</th>
                        <th class="py-2 pr-4">含み損益率</th>
                        <th class="py-2 pr-4">主要指標</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($holdings as $holding)
                        <tr class="border-b border-app-border last:border-b-0">
                            <td class="py-2 pr-4">
                                @if ($holding['instrument_type'] === 'stock')
                                    <a href="/holdings/{{ $holding['id'] }}" wire:navigate class="text-primary hover:underline">{{ $holding['symbol_name'] }}</a>
                                @else
                                    {{ $holding['symbol_name'] }}
                                @endif
                                @if ($holding['is_newly_detected'])
                                    <a href="/candidate-check?symbol_code={{ $holding['symbol_code'] }}" wire:navigate class="ml-1"><x-badge variant="info">NEW</x-badge></a>
                                @endif
                            </td>
                            <td class="py-2 pr-4">{{ $holding['symbol_code'] }}</td>
                            <td class="py-2 pr-4">{{ $holding['market'] }}</td>
                            <td class="py-2 pr-4">{{ $holding['instrument_type'] }}</td>
                            <td class="py-2 pr-4">{{ $holding['quantity'] }}</td>
                            <td class="py-2 pr-4">{{ number_format($holding['average_cost'], 2) }}</td>
                            <td class="py-2 pr-4">{{ number_format($holding['current_price'], 2) }}</td>
                            <td class="py-2 pr-4">{{ sprintf('%+.1f%%', $holding['unrealized_gain_rate']) }}</td>
                            <td class="py-2 pr-4">
                                @if ($holding['instrument_type'] !== 'stock')
                                    対象外
                                @else
                                    @if ($holding['rsi'] !== null)
                                        <x-badge>RSI {{ $holding['rsi'] }}</x-badge>
                                    @endif
                                    @if ($holding['per'] !== null)
                                        <x-badge>PER {{ $holding['per'] }}</x-badge>
                                    @endif
                                    @if ($holding['revenue_growth'] !== null)
                                        <x-badge>売上成長 {{ number_format($holding['revenue_growth'], 1) }}%</x-badge>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    @endif
</div>
