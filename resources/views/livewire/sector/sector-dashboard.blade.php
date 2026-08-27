<div>
    <x-page-header title="セクター配分" />

    <x-card>
        <h2 class="text-lg font-semibold mb-4">セクター配分</h2>

        @foreach ($sectors as $sector)
            <div class="mb-4 last:mb-0">
                <div class="flex items-center justify-between mb-1">
                    <span class="font-medium">{{ $sector['sector_name'] }}</span>
                    <span class="text-text-secondary text-[13px]">{{ $sector['allocation_rate'] }}%</span>
                    @if ($sector['allocation_status'] === '偏り警告')
                        <x-badge variant="danger">偏り警告</x-badge>
                    @elseif ($sector['allocation_status'] === 'やや偏り')
                        <x-badge variant="warning">やや偏り</x-badge>
                    @endif
                </div>
                <div class="w-full bg-slate-100 rounded h-2">
                    <div class="bg-primary rounded h-2" style="width: {{ $sector['allocation_rate'] }}%"></div>
                </div>

                @if ($sector['is_overweight'])
                    <div class="text-[13px] text-text-secondary mt-1">
                        売却提案: ¥{{ number_format($sector['suggested_sell_amount']) }} / {{ $sector['suggested_sell_quantity'] }}株
                    </div>
                @endif
            </div>
        @endforeach
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
