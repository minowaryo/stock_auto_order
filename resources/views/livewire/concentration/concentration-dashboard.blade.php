{{--
    UC-015 集中度ダッシュボード（ADR-0019 D7 / ADR-0021）。指標ごとの判定バッジと
    相関ヒートマップを付けるが、合成スコア・ランキングは作らない。
--}}
@php
    $percent = fn ($value) => number_format($value, 1).'%';
    $decimal2 = fn ($value) => number_format($value, 2);
    $reasonLabels = ['not_stock' => '株式以外', 'insufficient_history' => '週足不足'];
    // 投資信託は銘柄コードと銘柄名が同じ文字列のため、重複して出さない
    $label = fn ($row) => $row['symbol_code'] === $row['symbol_name'] ? $row['symbol_name'] : $row['symbol_code'].' '.$row['symbol_name'];
    $enoughIncluded = $dashboard['included_count'] >= 2;
    $verdictLabels = ['ok' => '分散OK', 'caution' => 'やや集中', 'concentrated' => '集中'];
    $verdictVariants = ['ok' => 'success', 'caution' => 'warning', 'concentrated' => 'danger'];
    $bandColors = [
        'strong' => 'rgba(220,38,38,0.35)',
        'high' => 'rgba(239,68,68,0.22)',
        'mild' => 'rgba(248,113,113,0.12)',
        'negative' => 'rgba(34,197,94,0.22)',
    ];
@endphp
<div>
    <x-page-header title="集中度ダッシュボード" />
    <x-sector-concentration-tabs current="concentration" />

    @if (! $dashboard['has_snapshot'])
        <x-card>
            <x-empty-state>まだ保有データがありません。CSVを取り込むと表示されます</x-empty-state>
        </x-card>
    @else
        <x-card>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                <x-stat-box label="PC1寄与率">{{ $dashboard['pc1_share'] !== null ? $percent($dashboard['pc1_share']) : '算出不可' }}
                    @if ($dashboard['verdicts']['pc1_share'] !== null)
                        <x-badge :variant="$verdictVariants[$dashboard['verdicts']['pc1_share']]" data-testid="verdict-pc1_share" data-verdict="{{ $dashboard['verdicts']['pc1_share'] }}">{{ $verdictLabels[$dashboard['verdicts']['pc1_share']] }}</x-badge>
                    @endif
                </x-stat-box>
                <x-stat-box label="実効ベット数">{{ $dashboard['effective_number_of_bets'] !== null ? $decimal2($dashboard['effective_number_of_bets']) : '算出不可' }}
                    @if ($dashboard['verdicts']['effective_number_of_bets'] !== null)
                        <x-badge :variant="$verdictVariants[$dashboard['verdicts']['effective_number_of_bets']]" data-testid="verdict-effective_number_of_bets" data-verdict="{{ $dashboard['verdicts']['effective_number_of_bets'] }}">{{ $verdictLabels[$dashboard['verdicts']['effective_number_of_bets']] }}</x-badge>
                    @endif
                </x-stat-box>
                <x-stat-box label="対SOXベータ（ポートフォリオ）">
                    @if ($dashboard['portfolio_sox_beta'] !== null)
                        {{ $decimal2($dashboard['portfolio_sox_beta']) }}
                    @elseif ($enoughIncluded)
                        取得不可（—）
                    @else
                        算出不可
                    @endif
                    @if ($dashboard['verdicts']['portfolio_sox_beta'] !== null)
                        <x-badge :variant="$verdictVariants[$dashboard['verdicts']['portfolio_sox_beta']]" data-testid="verdict-portfolio_sox_beta" data-verdict="{{ $dashboard['verdicts']['portfolio_sox_beta'] }}">{{ $verdictLabels[$dashboard['verdicts']['portfolio_sox_beta']] }}</x-badge>
                    @endif
                </x-stat-box>
                <x-stat-box label="上位5銘柄ウェイト">{{ $dashboard['top5_weight'] !== null ? $percent($dashboard['top5_weight']) : '算出不可' }}
                    @if ($dashboard['verdicts']['top5_weight'] !== null)
                        <x-badge :variant="$verdictVariants[$dashboard['verdicts']['top5_weight']]" data-testid="verdict-top5_weight" data-verdict="{{ $dashboard['verdicts']['top5_weight'] }}">{{ $verdictLabels[$dashboard['verdicts']['top5_weight']] }}</x-badge>
                    @endif
                </x-stat-box>
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
                <li>※ 判定のしきい値は叩き台です。PC1寄与率・上位5銘柄ウェイト・対SOXベータ（絶対値）は低いほど、実効ベット数は高いほど分散が効いています。</li>
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
                <p class="text-[13px] text-text-secondary mb-3">色の見方: 赤＝同じ方向に動く（濃いほど強い）／緑＝逆方向に動く／色なし＝ほぼ無関係</p>
                <div class="overflow-x-auto">
                    <table data-testid="correlation-matrix" class="text-[13px]">
                        <thead>
                            <tr class="text-left text-text-secondary border-b border-app-border">
                                <th scope="col" class="py-2 pr-4 whitespace-nowrap sticky left-0 z-10 bg-surface">銘柄</th>
                                @foreach ($dashboard['correlation_matrix'] as $column)
                                    <th scope="col" class="py-2 px-2 text-right">{{ $column['symbol_code'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dashboard['correlation_matrix'] as $row)
                                <tr class="border-b border-app-border last:border-b-0">
                                    <td class="py-2 pr-4 whitespace-nowrap sticky left-0 z-10 bg-surface">{{ $label($row) }}</td>
                                    @foreach ($row['correlations'] as $j => $value)
                                        @php $band = $dashboard['correlation_bands'][$loop->parent->index][$j] ?? null; @endphp
                                        <td class="py-2 px-2 text-right"@if (isset($bandColors[$band])) data-band="{{ $band }}" style="background-color: {{ $bandColors[$band] }}"@elseif ($band !== null) data-band="{{ $band }}"@endif>{{ $value !== null ? $decimal2($value) : '—' }}</td>
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
                            <th scope="col" class="py-2 pr-4">銘柄</th>
                            <th scope="col" class="py-2 pr-4 text-right">ウェイト（計算対象内）</th>
                            <th scope="col" class="py-2 pr-4 text-right">対SOXベータ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dashboard['sox_betas'] as $row)
                            <tr class="border-b border-app-border last:border-b-0">
                                <td class="py-2 pr-4">{{ $label($row) }}</td>
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
                            <th scope="col" class="py-2 pr-4">銘柄</th>
                            <th scope="col" class="py-2 pr-4">理由</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dashboard['excluded'] as $row)
                            <tr class="border-b border-app-border last:border-b-0">
                                <td class="py-2 pr-4">{{ $label($row) }}</td>
                                <td class="py-2 pr-4">{{ $reasonLabels[$row['reason']] ?? $row['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    @endif
</div>
