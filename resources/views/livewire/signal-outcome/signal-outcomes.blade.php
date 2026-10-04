{{--
    UC-014 シグナル検証画面（ADR-0017 D7/D8）。発生元×シグナル種別×評価期間ごとの
    超過リターン集計と、行ごとの個別発生（<details>で展開）を表示する。
    判定ラベルは実際の判定値に対してのみ出す（凡例として全ラベルを並べない）。
--}}
@php
    $sourceLabels = ['take_profit' => '利確検討', 'buy' => '買い増し', 'watchlist_buy' => 'ウォッチリスト押し目買い'];
    $marketLabels = ['jp' => '日本株', 'us' => '米国株'];
    $verdictLabels = ['working' => '機能している', 'pending' => '判断保留', 'not_working' => '機能していない', 'suspicious' => '異常を疑う'];
    $verdictVariants = ['working' => 'success', 'pending' => 'neutral', 'not_working' => 'danger', 'suspicious' => 'warning'];
    $signedPercent = fn ($value) => sprintf('%+.2f%%', $value);
    $tValue = fn ($value) => $value !== null ? number_format($value, 2) : '—';
    $statusLabels = ['pending' => '結果待ち', 'unavailable' => '算出不可'];
    $filterHref = function (?string $source, ?string $market) {
        $query = http_build_query(array_filter(['source' => $source, 'market' => $market], fn ($v) => $v !== null));

        return '/signal-outcomes'.($query !== '' ? '?'.$query : '');
    };
    $filterClass = fn (bool $active) => 'px-2.5 py-1 rounded border '.($active ? 'bg-primary text-white border-primary' : 'border-app-border text-text-secondary hover:text-text');
@endphp
<div>
    <x-page-header title="シグナル検証" caption="出たシグナルのその後の値動きを、同期間の指数（日本株は日経平均、米国株はS&P500）を差し引いた超過リターンで集計しています。" />
    <x-signal-outcome-tabs current="outcomes" />

    <div class="flex flex-wrap items-center gap-2 mb-3 text-[13px]">
        <span class="text-text-secondary">発生元:</span>
        <a href="{{ $filterHref(null, $activeMarket) }}" wire:navigate class="{{ $filterClass($activeSource === null) }}">すべて</a>
        @foreach ($sourceLabels as $key => $label)
            <a href="{{ $filterHref($key, $activeMarket) }}" wire:navigate class="{{ $filterClass($activeSource === $key) }}">{{ $label }}</a>
        @endforeach
    </div>
    <div class="flex flex-wrap items-center gap-2 mb-3 text-[13px]">
        <span class="text-text-secondary">市場:</span>
        <a href="{{ $filterHref($activeSource, null) }}" wire:navigate class="{{ $filterClass($activeMarket === null) }}">すべて</a>
        @foreach ($marketLabels as $key => $label)
            <a href="{{ $filterHref($activeSource, $key) }}" wire:navigate class="{{ $filterClass($activeMarket === $key) }}">{{ $label }}</a>
        @endforeach
    </div>

    @if (! $outcomes['has_occurrences'])
        <x-card>
            <x-empty-state>まだシグナルの記録がありません。次回のCSV取込から記録が始まります</x-empty-state>
        </x-card>
    @else
        <x-card>
            <ul class="text-xs text-text-secondary space-y-1">
                <li>※ 超過リターン＝銘柄の騰落率−同期間の指数の騰落率（配当を含まない価格ベース）。基準週: {{ $outcomes['as_of_week'] }}</li>
                <li>※ 平均・t値・判定は、同じ週の発生は1件として判定しています（週ごとの平均を1件として扱う）。判定には結果到来30件以上、かつ評価期間の3倍の週数（+4週は13週、+13週は39週、+26週は78週）が必要です。+13週・+26週は続けて出た週どうしで評価期間が重なるため、判定は控えめに読んでください。</li>
                <li>※ 評価期間が経過していない発生、および最後のCSV取込の週（週の途中の値）以降に終わる発生は「結果待ち」、確定済みの週なのに週次価格履歴が欠けている発生は「算出不可」として集計から除いています。</li>
                <li>※ この画面の結果で閾値・判定ロジックが自動で変わることはありません。変更は実測を根拠に別途判断します。</li>
            </ul>
        </x-card>

        @if (empty($outcomes['groups']))
            <x-card>
                <x-empty-state>絞り込み条件に該当するシグナルの記録はありません</x-empty-state>
            </x-card>
        @endif

        @foreach ($outcomes['groups'] as $group)
            <x-card>
                <h2 class="text-lg font-semibold mb-1">{{ $sourceLabels[$group['source']] ?? $group['source'] }} / {{ $group['signal_type'] }}</h2>
                <p class="text-[13px] text-text-secondary mb-3">発生 {{ $group['occurrence_count'] }}件</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="text-left text-text-secondary border-b border-app-border">
                                <th scope="col" class="py-2 pr-4">評価期間</th>
                                <th scope="col" class="py-2 pr-4 text-right">結果到来</th>
                                <th scope="col" class="py-2 pr-4 text-right">平均超過リターン</th>
                                <th scope="col" class="py-2 pr-4 text-right">中央値</th>
                                <th scope="col" class="py-2 pr-4 text-right">t値</th>
                                <th scope="col" class="py-2 pr-4 text-right">的中率</th>
                                <th scope="col" class="py-2 pr-4">判定</th>
                                <th scope="col" class="py-2 pr-4">除外</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['horizons'] as $horizon => $h)
                                <tr class="border-b border-app-border last:border-b-0">
                                    <td class="py-2 pr-4 whitespace-nowrap">+{{ $horizon }}週</td>
                                    <td class="py-2 pr-4 text-right">{{ $h['matured_count'] }}件（{{ $h['matured_week_count'] }}週）</td>
                                    <td class="py-2 pr-4 text-right">{{ $h['mean'] !== null ? $signedPercent($h['mean']) : '—' }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $h['median'] !== null ? $signedPercent($h['median']) : '—' }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $tValue($h['t_value']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $h['hit_rate'] !== null ? number_format($h['hit_rate'], 1).'%' : '—' }}</td>
                                    <td class="py-2 pr-4">
                                        <x-badge :variant="$verdictVariants[$h['verdict']] ?? 'neutral'">{{ ($verdictLabels[$h['verdict']] ?? $h['verdict']).($h['verdict'] === 'working' && $h['provisional'] ? '（暫定）' : '') }}</x-badge>
                                    </td>
                                    <td class="py-2 pr-4 text-text-secondary">
                                        @if ($h['pending_count'] > 0)
                                            <div>結果待ち {{ $h['pending_count'] }}件</div>
                                        @endif
                                        @if ($h['unavailable_count'] > 0)
                                            <div>算出不可 {{ $h['unavailable_count'] }}件</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <details class="mt-3">
                    <summary class="text-[13px] text-primary hover:underline">個別の発生を表示（{{ count($group['occurrences']) }}件・新しい順）</summary>
                    <div class="overflow-x-auto mt-2">
                        <table class="w-full text-[13px]">
                            <thead>
                                <tr class="text-left text-text-secondary border-b border-app-border">
                                    <th scope="col" class="py-2 pr-4">銘柄</th>
                                    <th scope="col" class="py-2 pr-4">発生週</th>
                                    @foreach (array_keys($group['horizons']) as $horizon)
                                        <th scope="col" class="py-2 pr-4 text-right">+{{ $horizon }}週</th>
                                    @endforeach
                                    <th scope="col" class="py-2 pr-4">発生時点の根拠値</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($group['occurrences'] as $row)
                                    <tr class="border-b border-app-border last:border-b-0 align-top">
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ $row['symbol_code'] }} {{ $row['symbol_name'] }}（{{ $marketLabels[$row['market']] ?? $row['market'] }}）</td>
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ $row['observed_week'] }}</td>
                                        @foreach (array_keys($group['horizons']) as $horizon)
                                            <td class="py-2 pr-4 text-right whitespace-nowrap">
                                                @if ($row['statuses'][$horizon] === 'matured' && $row['excess_returns'][$horizon] !== null)
                                                    {{ $signedPercent($row['excess_returns'][$horizon]) }}
                                                @else
                                                    {{ $statusLabels[$row['statuses'][$horizon]] ?? '—' }}
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="py-2 pr-4 text-xs text-text-secondary break-words">
                                            @if ($row['metrics'] === null)
                                                記録なし
                                            @else
                                                @foreach (\App\Support\SignalOccurrenceMetricLabels::format($row['metrics']) as $item)
                                                    <span class="whitespace-nowrap">{{ $item }}</span>@if (! $loop->last) / @endif
                                                @endforeach
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </x-card>
        @endforeach
    @endif
</div>
