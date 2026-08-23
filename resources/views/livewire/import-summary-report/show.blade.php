@php
    $badgeVariant = fn (string $type) => match ($type) {
        '利確検討' => 'warning',
        'リバランス' => 'danger',
        '新規投資候補' => 'info',
        default => 'neutral',
    };
    $rowHref = fn (array $item) => $item['recommendation_type'] === 'リバランス'
        ? '/sector-dashboard'
        : '/holdings?symbol_code='.($item['symbol_code'] ?? '');
@endphp
<div>
    <x-page-header title="取込後サマリーレポート" />

    <x-card>
        <p class="text-sm">{{ $report['portfolio_headline'] }}</p>
    </x-card>

    @if (empty($report['top_recommendations']) && empty($report['supplementary_recommendations']))
        <x-card>
            <x-empty-state>現時点でおすすめできる項目はありません</x-empty-state>
        </x-card>
    @else
        <x-card>
            <h2 class="text-base font-semibold mb-4">おすすめ上位10件</h2>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-2 pr-4">順位</th>
                        <th class="py-2 pr-4">種別</th>
                        <th class="py-2 pr-4">対象</th>
                        <th class="py-2 pr-4">アクション</th>
                        <th class="py-2 pr-4">理由</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['top_recommendations'] as $item)
                        <tr class="border-b border-app-border last:border-b-0">
                            <td class="py-2 pr-4">{{ $item['rank'] }}</td>
                            <td class="py-2 pr-4"><x-badge :variant="$badgeVariant($item['recommendation_type'])">{{ $item['recommendation_type'] }}</x-badge></td>
                            <td class="py-2 pr-4">
                                <a href="{{ $rowHref($item) }}" wire:navigate class="text-primary hover:underline">{{ $item['target'] }}</a>
                            </td>
                            <td class="py-2 pr-4">{{ $item['action_suggestion'] }}</td>
                            <td class="py-2 pr-4">{{ $item['reason_summary'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>

        @if (! empty($report['supplementary_recommendations']))
            <x-card>
                <h2 class="text-base font-semibold mb-4">補足レコメンド（11〜20位）</h2>
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-text-secondary border-b border-app-border">
                            <th class="py-2 pr-4">順位</th>
                            <th class="py-2 pr-4">種別</th>
                            <th class="py-2 pr-4">対象</th>
                            <th class="py-2 pr-4">アクション</th>
                            <th class="py-2 pr-4">理由</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['supplementary_recommendations'] as $item)
                            <tr class="border-b border-app-border last:border-b-0">
                                <td class="py-2 pr-4">{{ $item['rank'] }}</td>
                                <td class="py-2 pr-4"><x-badge :variant="$badgeVariant($item['recommendation_type'])">{{ $item['recommendation_type'] }}</x-badge></td>
                                <td class="py-2 pr-4">
                                    <a href="{{ $rowHref($item) }}" wire:navigate class="text-primary hover:underline">{{ $item['target'] }}</a>
                                </td>
                                <td class="py-2 pr-4">{{ $item['action_suggestion'] }}</td>
                                <td class="py-2 pr-4">{{ $item['reason_summary'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    @endif
</div>
