{{--
    UC-015 集中度ダッシュボード（ADR-0019 D6/D7）。値を並べて見せるだけで、
    判定・バッジ・目安値・色分けは付けない。
--}}
@php
    $percent = fn ($value) => number_format($value, 1).'%';
    $decimal2 = fn ($value) => number_format($value, 2);
    $reasonLabels = ['not_stock' => '株式以外', 'insufficient_history' => '週足不足'];
    $enoughIncluded = $dashboard['included_count'] >= 2;
@endphp
<div>
    <x-page-header title="集中度ダッシュボード" />

    @if (! $dashboard['has_snapshot'])
        <x-card>
            <x-empty-state>まだ保有データがありません。CSVを取り込むと表示されます</x-empty-state>
        </x-card>
    @else
        <x-card>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                <x-stat-box label="PC1寄与率">{{ $dashboard['pc1_share'] !== null ? $percent($dashboard['pc1_share']) : '算出不可' }}</x-stat-box>
                <x-stat-box label="実効ベット数">{{ $dashboard['effective_number_of_bets'] !== null ? $decimal2($dashboard['effective_number_of_bets']) : '算出不可' }}</x-stat-box>
                <x-stat-box label="対SOXベータ（ポートフォリオ）">
                    @if ($dashboard['portfolio_sox_beta'] !== null)
                        {{ $decimal2($dashboard['portfolio_sox_beta']) }}
                    @elseif ($enoughIncluded)
                        取得不可（—）
                    @else
                        算出不可
                    @endif
                </x-stat-box>
                <x-stat-box label="上位5銘柄ウェイト">{{ $dashboard['top5_weight'] !== null ? $percent($dashboard['top5_weight']) : '算出不可' }}</x-stat-box>
                <x-stat-box label="計算対象">{{ $dashboard['included_count'] }}銘柄</x-stat-box>
                <x-stat-box label="カバー率">{{ $dashboard['coverage_rate'] !== null ? $percent($dashboard['coverage_rate']) : '算出不可' }}</x-stat-box>
            </div>
            @if ($dashboard['window_start_week'] !== null && $dashboard['window_end_week'] !== null)
                <p class="text-[13px] text-text-secondary mt-3">計算窓: {{ $dashboard['window_start_week'] }} 〜 {{ $dashboard['window_end_week'] }}</p>
            @endif
            @if ($dashboard['included_count'] === 0 && in_array('insufficient_history', array_column($dashboard['excluded'], 'reason'), true))
                <p class="text-[13px] text-text-secondary mt-2">週足がそろった銘柄がありません。次回のCSV取込後に算出できます</p>
            @endif
            <ul class="text-xs text-text-secondary mt-3 space-y-1">
                <li>※ リターンは現地通貨建てで計算しています（為替の変動は含みません）。</li>
                @if ($dashboard['included_count'] > 52)
                    <li>※ 計算対象の銘柄数が週数（52）を上回るため、相関・PC1寄与率・実効ベット数の推定誤差が大きくなります。</li>
                @endif
            </ul>
        </x-card>

        <x-card>
            <h2 class="text-lg font-semibold mb-4">相関行列</h2>
            @if (empty($dashboard['correlation_matrix']))
                <x-empty-state>算出不可</x-empty-state>
            @else
                <div class="overflow-x-auto">
                    <table data-testid="correlation-matrix" class="text-[13px]">
                        <thead>
                            <tr class="text-left text-text-secondary border-b border-app-border">
                                <th class="py-2 pr-4">銘柄</th>
                                @foreach ($dashboard['correlation_matrix'] as $column)
                                    <th class="py-2 px-2 text-right">{{ $column['symbol_code'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dashboard['correlation_matrix'] as $row)
                                <tr class="border-b border-app-border last:border-b-0">
                                    <td class="py-2 pr-4">{{ $row['symbol_code'] }} {{ $row['symbol_name'] }}</td>
                                    @foreach ($row['correlations'] as $value)
                                        <td class="py-2 px-2 text-right">{{ $value !== null ? $decimal2($value) : '—' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($dashboard['hidden_count'] > 0)
                <p class="text-[13px] text-text-secondary mt-2">表示外 {{ $dashboard['hidden_count'] }}銘柄</p>
            @endif
        </x-card>

        <x-card>
            <h2 class="text-lg font-semibold mb-4">銘柄別の対SOXベータ</h2>
            @if (empty($dashboard['sox_betas']))
                <x-empty-state>算出不可</x-empty-state>
            @else
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-text-secondary border-b border-app-border">
                            <th class="py-2 pr-4">銘柄</th>
                            <th class="py-2 pr-4 text-right">ウェイト（計算対象内）</th>
                            <th class="py-2 pr-4 text-right">対SOXベータ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dashboard['sox_betas'] as $row)
                            <tr class="border-b border-app-border last:border-b-0">
                                <td class="py-2 pr-4">{{ $row['symbol_code'] }} {{ $row['symbol_name'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $percent($row['weight']) }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row['sox_beta'] !== null ? $decimal2($row['sox_beta']) : '取得不可（—）' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>

        @if (! empty($dashboard['excluded']))
            <x-card>
                <h2 class="text-lg font-semibold mb-4">計算対象外の銘柄</h2>
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-text-secondary border-b border-app-border">
                            <th class="py-2 pr-4">銘柄</th>
                            <th class="py-2 pr-4">理由</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dashboard['excluded'] as $row)
                            <tr class="border-b border-app-border last:border-b-0">
                                <td class="py-2 pr-4">{{ $row['symbol_code'] }} {{ $row['symbol_name'] }}</td>
                                <td class="py-2 pr-4">{{ $reasonLabels[$row['reason']] ?? $row['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    @endif
</div>
