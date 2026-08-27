<div>
    <x-page-header title="利確検討" />

    @if (empty($signals))
        <x-card>
            <x-empty-state>利確検討が必要な銘柄はありません</x-empty-state>
        </x-card>
    @else
        <x-card>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-2 pr-4">銘柄</th>
                        <th class="py-2 pr-4">含み益率</th>
                        <th class="py-2 pr-4">発生シグナル</th>
                        <th class="py-2 pr-4">理由サマリ</th>
                        <th class="py-2 pr-4">分割指値の提案</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($signals as $row)
                        <tr class="border-b border-app-border last:border-b-0">
                            <td class="py-2 pr-4">
                                <a href="/holdings/{{ $row['id'] }}" wire:navigate class="text-primary hover:underline">{{ $row['symbol_name'] }}</a>
                                {{ $row['symbol_code'] }}
                            </td>
                            <td class="py-2 pr-4">{{ $row['unrealized_gain_rate'] }}</td>
                            <td class="py-2 pr-4">
                                @foreach ($row['signal_types'] as $signalType)
                                    <x-badge>{{ $signalType }}</x-badge>
                                @endforeach
                            </td>
                            <td class="py-2 pr-4">{{ $row['signal_reason_summary'] }}</td>
                            <td class="py-2 pr-4">
                                <div>+20%地点: {{ $row['split_limit_suggestion'][0]['price'] }} / {{ $row['split_limit_suggestion'][0]['quantity'] }}</div>
                                <div>+35%地点: {{ $row['split_limit_suggestion'][1]['price'] }} / {{ $row['split_limit_suggestion'][1]['quantity'] }}</div>
                                <div>現在値以降: {{ $row['split_limit_suggestion'][2]['quantity'] }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    @endif
</div>
