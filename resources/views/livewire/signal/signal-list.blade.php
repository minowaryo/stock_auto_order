<div>
    <x-page-header title="売買シグナル" />

    <x-card>
        <h2 class="text-lg font-semibold mb-1">買い増し候補（UC-010）</h2>
        <p class="text-[13px] text-text-secondary mb-3">一時的な下落で割安になっており、かつファンダメンタルズ（ROE・自己資本比率・成長率等）が良好な保有銘柄です。分割買い下がりの指値提案とともに表示しています。</p>

        @if (empty($buySignals))
            <x-empty-state>買い増しを検討できる押し目銘柄はありません</x-empty-state>
        @else
            {{--
                ヘッダー用・本文用で<table>を分離し、ヘッダー側だけをsticky top-0にする
                （経緯はdocs/ai-context/known-pitfalls.md「position: sticky と横スクロール
                用テーブルの分割」参照）。横スクロール位置はresources/js/app.jsで本文側から
                ヘッダー側へ同期する。ヘッダーtableはborder-b-0にして本文tableの上端境界と
                二重線にならないようにしている。
            --}}
            <div id="buy-signals-header-scroll" class="overflow-x-auto sticky top-0 z-20 bg-surface [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <table class="table-fixed w-[1296px] text-[11px] border border-app-border border-b-0 [&_th]:border [&_th]:border-app-border">
                    <x-signal-table-colgroup
                        :technical-count="count($buySignals[0]['criteria']['technical'])"
                        :fundamental-count="count($buySignals[0]['criteria']['fundamental'])"
                    />
                    <x-signal-table-head
                        :labels="['銘柄', '含み益率', '発生シグナル', '財務健全性', '分割買い下がりの提案']"
                        :criteria="$buySignals[0]['criteria']"
                    />
                </table>
            </div>
            <div class="overflow-x-auto" data-scroll-sync-with="buy-signals-header-scroll">
                <table class="table-fixed w-[1296px] text-[11px] border border-app-border [&_td]:border [&_td]:border-app-border [&_td]:align-top [&_td]:break-words">
                    <x-signal-table-colgroup
                        :technical-count="count($buySignals[0]['criteria']['technical'])"
                        :fundamental-count="count($buySignals[0]['criteria']['fundamental'])"
                    />
                    <tbody>
                        @foreach ($buySignals as $row)
                            <tr class="border-b border-app-border last:border-b-0">
                                <td class="py-1.5 px-1.5 sticky left-0 z-10 bg-surface">
                                    <div>{{ $row['symbol_name'] }} {{ $row['symbol_code'] }}</div>
                                    <x-signal-criteria-summary-badges :criteria="$row['criteria']" />
                                </td>
                                <td class="py-1.5 px-1.5">{{ sprintf('%+.1f%%', $row['unrealized_gain_rate']) }}</td>
                                <td class="py-1.5 px-1.5">
                                    @foreach ($row['buy_signal_types'] as $buySignalType)
                                        <x-badge variant="success">{{ $buySignalType }}</x-badge>
                                    @endforeach
                                    <div>{{ $row['buy_signal_reason_summary'] }}</div>
                                </td>
                                <td class="py-1.5 px-1.5">
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
                                <td class="py-1.5 px-1.5">
                                    <div>現在値: {{ number_format($row['split_buy_down_suggestion'][0]['price'], 2) }} / {{ $row['split_buy_down_suggestion'][0]['quantity'] }}</div>
                                    <div>-7%地点: {{ number_format($row['split_buy_down_suggestion'][1]['price'], 2) }} / {{ $row['split_buy_down_suggestion'][1]['quantity'] }}</div>
                                    <div>-15%地点: {{ number_format($row['split_buy_down_suggestion'][2]['price'], 2) }} / {{ $row['split_buy_down_suggestion'][2]['quantity'] }}</div>
                                </td>
                                <x-signal-criteria-cells :criteria="$row['criteria']" />
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <x-card>
        <h2 class="text-lg font-semibold mb-4">利確検討</h2>

        @if (empty($signals))
            <x-empty-state>利確検討が必要な銘柄はありません</x-empty-state>
        @else
            <div id="take-profit-header-scroll" class="overflow-x-auto sticky top-0 z-20 bg-surface [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <table class="table-fixed w-[1296px] text-[11px] border border-app-border border-b-0 [&_th]:border [&_th]:border-app-border">
                    <x-signal-table-colgroup
                        :technical-count="count($signals[0]['criteria']['technical'])"
                        :fundamental-count="count($signals[0]['criteria']['fundamental'])"
                    />
                    <x-signal-table-head
                        :labels="['銘柄', '含み益率', '発生シグナル', '理由サマリ', '分割指値の提案']"
                        :criteria="$signals[0]['criteria']"
                    />
                </table>
            </div>
            <div class="overflow-x-auto" data-scroll-sync-with="take-profit-header-scroll">
                <table class="table-fixed w-[1296px] text-[11px] border border-app-border [&_td]:border [&_td]:border-app-border [&_td]:align-top [&_td]:break-words">
                    <x-signal-table-colgroup
                        :technical-count="count($signals[0]['criteria']['technical'])"
                        :fundamental-count="count($signals[0]['criteria']['fundamental'])"
                    />
                    <tbody>
                        @foreach ($signals as $row)
                            <tr class="border-b border-app-border last:border-b-0">
                                <td class="py-1.5 px-1.5 sticky left-0 z-10 bg-surface">
                                    <div><a href="/holdings/{{ $row['id'] }}" wire:navigate class="text-primary hover:underline">{{ $row['symbol_name'] }}</a> {{ $row['symbol_code'] }}</div>
                                    <x-signal-criteria-summary-badges :criteria="$row['criteria']" />
                                </td>
                                <td class="py-1.5 px-1.5">{{ sprintf('%+.1f%%', $row['unrealized_gain_rate']) }}</td>
                                <td class="py-1.5 px-1.5">
                                    @foreach ($row['signal_types'] as $signalType)
                                        <x-badge variant="warning">{{ $signalType }}</x-badge>
                                    @endforeach
                                </td>
                                <td class="py-1.5 px-1.5">{{ $row['signal_reason_summary'] }}</td>
                                <td class="py-1.5 px-1.5">
                                    @php
                                        // CHG-0006: label must follow the row's actual mode,
                                        // not always the normal-mode +20%/+35% wording.
                                        [$firstTierLabel, $secondTierLabel] = $row['is_high_water_mark'] ? ['+100%', '+150%'] : ['+20%', '+35%'];
                                    @endphp
                                    <div>{{ $firstTierLabel }}地点: {{ number_format($row['split_limit_suggestion'][0]['price'], 2) }} / {{ $row['split_limit_suggestion'][0]['quantity'] }}</div>
                                    <div>{{ $secondTierLabel }}地点: {{ number_format($row['split_limit_suggestion'][1]['price'], 2) }} / {{ $row['split_limit_suggestion'][1]['quantity'] }}</div>
                                    <div>現在値以降: {{ $row['split_limit_suggestion'][2]['quantity'] }}</div>
                                </td>
                                <x-signal-criteria-cells :criteria="$row['criteria']" />
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</div>
