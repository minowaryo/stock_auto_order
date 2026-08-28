<div>
    <x-page-header title="売買シグナル" />

    <x-card>
        <h2 class="text-lg font-semibold mb-1">買い増し候補（UC-010）</h2>
        <p class="text-[13px] text-text-secondary mb-3">一時的な下落で割安になっており、かつファンダメンタルズ（ROE・自己資本比率・成長率等）が良好な保有銘柄です。分割買い下がりの指値提案とともに表示しています。</p>

        @if (empty($buySignals))
            <x-empty-state>買い増しを検討できる押し目銘柄はありません</x-empty-state>
        @else
            <table class="w-full text-[13px] border border-app-border [&_th]:border [&_th]:border-app-border [&_td]:border [&_td]:border-app-border [&_td]:align-top">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-2 px-2">銘柄</th>
                        <th class="py-2 px-2">含み益率</th>
                        <th class="py-2 px-2">発生シグナル</th>
                        <th class="py-2 px-2">財務健全性</th>
                        <th class="py-2 px-2">分割買い下がりの提案</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($buySignals as $row)
                        <tr class="border-b border-app-border last:border-b-0">
                            <td class="py-2 px-2">
                                {{ $row['symbol_name'] }}
                                {{ $row['symbol_code'] }}
                            </td>
                            <td class="py-2 px-2">{{ sprintf('%+.1f%%', $row['unrealized_gain_rate']) }}</td>
                            <td class="py-2 px-2">
                                @foreach ($row['buy_signal_types'] as $buySignalType)
                                    <x-badge variant="success">{{ $buySignalType }}</x-badge>
                                @endforeach
                                <div>{{ $row['buy_signal_reason_summary'] }}</div>
                            </td>
                            <td class="py-2 px-2">
                                @if ($row['fundamental_status'] === 'unavailable')
                                    <x-badge variant="neutral">財務指標 取得不可</x-badge>
                                @else
                                    <div>{{ $row['fundamental_summary'] }}</div>
                                @endif
                                @if ($row['nisa_recommended'])
                                    <x-badge variant="info">NISA推奨</x-badge>
                                    <div>{{ $row['nisa_recommended_reason'] }}</div>
                                @endif
                            </td>
                            <td class="py-2 px-2">
                                <div>現在値: {{ number_format($row['split_buy_down_suggestion'][0]['price'], 2) }} / {{ $row['split_buy_down_suggestion'][0]['quantity'] }}</div>
                                <div>-7%地点: {{ number_format($row['split_buy_down_suggestion'][1]['price'], 2) }} / {{ $row['split_buy_down_suggestion'][1]['quantity'] }}</div>
                                <div>-15%地点: {{ number_format($row['split_buy_down_suggestion'][2]['price'], 2) }} / {{ $row['split_buy_down_suggestion'][2]['quantity'] }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>

    <x-card>
        <h2 class="text-lg font-semibold mb-4">利確検討</h2>

        @if (empty($signals))
            <x-empty-state>利確検討が必要な銘柄はありません</x-empty-state>
        @else
            <table class="w-full text-[13px] border border-app-border [&_th]:border [&_th]:border-app-border [&_td]:border [&_td]:border-app-border [&_td]:align-top">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-2 px-2">銘柄</th>
                        <th class="py-2 px-2">含み益率</th>
                        <th class="py-2 px-2">発生シグナル</th>
                        <th class="py-2 px-2">理由サマリ</th>
                        <th class="py-2 px-2">分割指値の提案</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($signals as $row)
                        <tr class="border-b border-app-border last:border-b-0">
                            <td class="py-2 px-2">
                                <a href="/holdings/{{ $row['id'] }}" wire:navigate class="text-primary hover:underline">{{ $row['symbol_name'] }}</a>
                                {{ $row['symbol_code'] }}
                            </td>
                            <td class="py-2 px-2">{{ sprintf('%+.1f%%', $row['unrealized_gain_rate']) }}</td>
                            <td class="py-2 px-2">
                                @foreach ($row['signal_types'] as $signalType)
                                    <x-badge variant="warning">{{ $signalType }}</x-badge>
                                @endforeach
                            </td>
                            <td class="py-2 px-2">{{ $row['signal_reason_summary'] }}</td>
                            <td class="py-2 px-2">
                                <div>+20%地点: {{ number_format($row['split_limit_suggestion'][0]['price'], 2) }} / {{ $row['split_limit_suggestion'][0]['quantity'] }}</div>
                                <div>+35%地点: {{ number_format($row['split_limit_suggestion'][1]['price'], 2) }} / {{ $row['split_limit_suggestion'][1]['quantity'] }}</div>
                                <div>現在値以降: {{ $row['split_limit_suggestion'][2]['quantity'] }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>
</div>
