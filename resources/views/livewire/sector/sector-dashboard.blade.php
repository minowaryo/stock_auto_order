<div>
    <x-page-header title="セクター配分" />

    <div class="mb-4 text-[13px]">
        <a href="/concentration-dashboard" wire:navigate class="text-primary hover:underline">集中度ダッシュボード（相関・実効ベット数・対SOXベータ）→</a>
    </div>

    <x-card>
        <h2 class="text-lg font-semibold mb-4">セクター配分</h2>

        @foreach ($marketGroups as $group)
            <div class="mb-6 last:mb-0">
                <div class="flex items-center justify-between border-b border-app-border pb-1 mb-3">
                    <h3 class="font-semibold">{{ $group['label'] }}</h3>
                    <span class="text-[13px] text-text-secondary">小計 ¥{{ number_format($group['subtotal']) }}</span>
                </div>

            @foreach ($group['sectors'] as $sector)
                <div class="mb-4 last:mb-0">
                    <div class="flex items-center justify-between mb-1">
                        <span class="font-medium">{{ $sector['sector_name'] }}</span>
                        <span class="text-text-secondary text-[13px]">¥{{ number_format($sector['allocation_amount']) }} / {{ number_format($sector['allocation_rate'], 1) }}%</span>
                        @if ($sector['allocation_status'] === '偏り警告')
                            <x-badge variant="danger">偏り警告</x-badge>
                        @elseif ($sector['allocation_status'] === 'やや偏り')
                            <x-badge variant="warning">やや偏り</x-badge>
                        @endif
                    </div>
                    <div class="w-full bg-slate-100 rounded h-2">
                        <div class="bg-primary rounded h-2" style="width: {{ round($sector['allocation_rate'], 1) }}%"></div>
                    </div>

                    @if ($sector['is_overweight'])
                        <div class="text-[13px] text-text-secondary mt-1">
                            売却提案: ¥{{ number_format($sector['suggested_sell_amount']) }} / {{ number_format($sector['suggested_sell_quantity'], 1) }}株
                        </div>
                    @endif
                </div>
            @endforeach
            </div>
        @endforeach

        <div class="flex items-center justify-between border-t border-app-border pt-2 font-semibold">
            <span>合計</span>
            <span>¥{{ number_format($grandTotal) }}</span>
        </div>
    </x-card>

    <x-card>
        <h2 class="text-lg font-semibold mb-4">リバランス候補</h2>

        @if (empty($rebalanceCandidates))
            <x-empty-state>リバランス候補はありません</x-empty-state>
        @else
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-2 pr-4">銘柄</th>
                        <th class="py-2 pr-4">セクター</th>
                        <th class="py-2 pr-4">理由</th>
                        <th class="py-2 pr-4">推奨購入額</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rebalanceCandidates as $candidate)
                        <tr class="border-b border-app-border last:border-b-0">
                            <td class="py-2 pr-4">
                                <a href="/candidate-check?symbol_code={{ $candidate['symbol_code'] }}" wire:navigate class="text-primary hover:underline">{{ $candidate['symbol_name'] }}</a>
                                {{ $candidate['symbol_code'] }}
                                @if ($candidate['nisa_recommended'])
                                    <x-badge variant="info">NISA推奨</x-badge>
                                @endif
                            </td>
                            <td class="py-2 pr-4">{{ $candidate['sector_name'] }}</td>
                            <td class="py-2 pr-4">{{ $candidate['reason'] }}</td>
                            <td class="py-2 pr-4">¥{{ number_format($candidate['suggested_purchase_amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>
</div>
