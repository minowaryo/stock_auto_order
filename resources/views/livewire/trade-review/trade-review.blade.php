@php
    $pct = fn (?float $rate) => $rate === null ? '—' : sprintf('%+.1f%%', $rate * 100);
    $pt = fn (?float $points) => $points === null ? '—' : sprintf('%+.1fpt', $points);
    $verdicts = [
        'below_20' => ['20%未達', 'neutral'],
        'met_20' => ['20%達成・25%未達', 'success'],
        'met_25' => ['25%達成', 'success'],
        'pending' => ['判定保留', 'warning'],
        'reference' => ['参考', 'neutral'],
        'unavailable' => ['算出不可', 'neutral'],
    ];
    // Green for a positive number, red for a negative one (zero and non-numbers stay neutral).
    $tone = function (float|string|null $value): string {
        if (is_string($value)) {
            $value = preg_match('/^[+-]\d+(?:\.\d+)?/', $value, $m) ? (float) $m[0] : null;
        }

        return match (true) {
            $value === null, round($value, 1) == 0 => '',
            $value > 0 => 'text-green-700',
            default => 'text-red-700',
        };
    };
    $marketLabels = ['all' => '合計', 'jp' => '国内株', 'us' => '米国株'];
@endphp
<div>
    <h1 class="text-xl font-semibold mb-3">売買の振り返り</h1>

    <x-signal-outcome-tabs current="review" />

    <nav aria-label="売買の振り返りの表示切替" class="flex gap-1 mb-4">
        @foreach (['performance' => '成績', 'trades' => '売買一覧', 'situations' => '状況別'] as $key => $label)
            <a href="/trade-review?tab={{ $key }}" wire:navigate
               @if ($tab === $key) aria-current="page" @endif
               class="px-3 py-1.5 rounded-md text-[13px] font-medium {{ $tab === $key ? 'text-primary bg-blue-50' : 'text-text-secondary hover:bg-app-bg' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if ($latest === null)
        <p class="text-text-secondary">売買履歴がまだありません。売買履歴CSVを取り込むと表示されます。</p>
    @elseif ($tab === 'performance')
        <p class="text-[13px] text-text-secondary mb-3">国内株・米国株の株式部分（配当除く）。投信・現金は含みません</p>

        @foreach (['直近52週' => [$latest], '暦年' => $years] as $heading => $periods)
            <h2 class="text-lg font-semibold mt-4 mb-2">{{ $heading }}</h2>
            <table class="w-full text-[13px] mb-2">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        @if ($heading === '暦年')<th class="py-1.5 px-1.5">年</th>@endif
                        <th class="py-1.5 px-1.5">範囲</th>
                        <th class="py-1.5 px-1.5 text-right">年率</th>
                        <th class="py-1.5 px-1.5 text-right">ずっと持ち続けた場合</th>
                        <th class="py-1.5 px-1.5 text-right">差</th>
                        <th class="py-1.5 px-1.5">判定</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($periods as $periodKey => $rows)
                        @foreach ($rows as $marketKey => $row)
                            <tr class="border-b border-app-border">
                                @if ($heading === '暦年')<td class="py-1.5 px-1.5">{{ $loop->first ? $periodKey.'年' : '' }}</td>@endif
                                <td class="py-1.5 px-1.5">{{ $marketLabels[$marketKey] }}</td>
                                <td class="py-1.5 px-1.5 text-right {{ $tone($row['return_rate'] === null ? null : $row['return_rate'] * 100) }}">{{ $pct($row['return_rate']) }}</td>
                                <td class="py-1.5 px-1.5 text-right {{ $tone($row['buy_hold_rate'] === null ? null : $row['buy_hold_rate'] * 100) }}">{{ $pct($row['buy_hold_rate']) }}</td>
                                <td class="py-1.5 px-1.5 text-right font-medium {{ $tone($row['diff_points']) }}">{{ $pt($row['diff_points']) }}</td>
                                <td class="py-1.5 px-1.5">
                                    <x-badge :variant="$verdicts[$row['verdict']][1]">{{ $verdicts[$row['verdict']][0] }}</x-badge>
                                    @if ($row['verdict'] === 'reference')
                                        <span class="text-text-secondary">（期間目標 {{ $pct($row['period_target_20']) }}）</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @elseif ($tab === 'situations')
        <p class="text-[13px] text-text-secondary mb-3">
            探索的結果です。成功法則・売買ルールを示すものではありません。事後再計算の区分は売買週の前週末までの株価から計算し、業績指標は使いません。
        </p>
        <p class="text-[13px] mb-3">
            対象の売買 {{ $situationSummary['trades'] }}件（定型の除外 {{ $situationSummary['excluded_routine'] }}件）／
            出所: 当時記録 {{ $situationSummary['recorded'] }}件・事後再計算 {{ $situationSummary['recomputed'] }}件
        </p>

        <div class="flex gap-2 mb-3 text-[13px]">
            <select wire:model.live="axis" aria-label="軸" class="border border-app-border rounded px-2 py-1">
                <option value="high_ratio">52週高値比</option>
                <option value="relative_strength">相対強度</option>
                <option value="holding">保有状況</option>
            </select>
            <select wire:model.live="type" aria-label="比較の種類" class="border border-app-border rounded px-2 py-1">
                <option value="sell">売却単独</option>
                <option value="switch">推定乗換え（参考）</option>
                <option value="buy">買付</option>
            </select>
            <select wire:model.live="horizon" aria-label="評価期間" class="border border-app-border rounded px-2 py-1">
                <option value="4">+4週</option>
                <option value="13">+13週</option>
                <option value="26">+26週</option>
            </select>
        </div>

        <table class="w-full text-[13px] mb-4">
            <thead>
                <tr class="text-left text-text-secondary border-b border-app-border">
                    <th class="py-1.5 px-1.5">市場</th>
                    <th class="py-1.5 px-1.5">区分</th>
                    <th class="py-1.5 px-1.5 text-right">クラスタ数</th>
                    <th class="py-1.5 px-1.5 text-right">結果あり</th>
                    <th class="py-1.5 px-1.5 text-right">結果待ち</th>
                    <th class="py-1.5 px-1.5 text-right">算出不可</th>
                    <th class="py-1.5 px-1.5 text-right">対ガチホ差の中央値</th>
                    <th class="py-1.5 px-1.5 text-right">25%〜75%点</th>
                    <th class="py-1.5 px-1.5 text-right">上回ったクラスタ数</th>
                    <th class="py-1.5 px-1.5 text-right">当時記録の件数</th>
                    <th class="py-1.5 px-1.5"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($situationRows as $row)
                    <tr class="border-b border-app-border">
                        <td class="py-1.5 px-1.5">{{ $row['market'] }}</td>
                        <td class="py-1.5 px-1.5">{{ $row['band'] }}</td>
                        <td class="py-1.5 px-1.5 text-right">{{ $row['clusters'] }}</td>
                        <td class="py-1.5 px-1.5 text-right">{{ $row['ok'] }}</td>
                        <td class="py-1.5 px-1.5 text-right">{{ $row['pending'] }}</td>
                        <td class="py-1.5 px-1.5 text-right">{{ $row['unavailable'] }}</td>
                        <td class="py-1.5 px-1.5 text-right font-medium {{ $tone($row['median']) }}">{{ $row['median'] }}</td>
                        <td class="py-1.5 px-1.5 text-right">{{ $row['range'] }}</td>
                        <td class="py-1.5 px-1.5 text-right">{{ $row['better'] }}</td>
                        <td class="py-1.5 px-1.5 text-right">{{ $row['recorded'] }}</td>
                        <td class="py-1.5 px-1.5">
                            <button type="button" wire:click="$set('cell', '{{ $row['key'] }}')" class="text-primary hover:underline">売買を見る</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($situationTrades !== [])
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-1.5 px-1.5">約定日</th>
                        <th class="py-1.5 px-1.5">銘柄コード</th>
                        <th class="py-1.5 px-1.5">銘柄名</th>
                        <th class="py-1.5 px-1.5 text-right">対ガチホ差</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($situationTrades as $situationTrade)
                        <tr class="border-b border-app-border">
                            <td class="py-1.5 px-1.5">{{ $situationTrade['date'] }}</td>
                            <td class="py-1.5 px-1.5">{{ $situationTrade['code'] }}</td>
                            <td class="py-1.5 px-1.5">{{ $situationTrade['name'] }}</td>
                            <td class="py-1.5 px-1.5 text-right {{ $tone($situationTrade['diff']) }}">{{ $situationTrade['diff'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @else
        <div class="flex gap-2 mb-3 text-[13px]">
            <select wire:model.live="market" aria-label="市場" class="border border-app-border rounded px-2 py-1">
                <option value="">市場: すべて</option>
                <option value="jp">国内株</option>
                <option value="us">米国株</option>
            </select>
            <select wire:model.live="kind" aria-label="種類" class="border border-app-border rounded px-2 py-1">
                <option value="">種類: すべて</option>
                <option value="sell">売却</option>
                <option value="buy">買付</option>
            </select>
            <select wire:model.live="year" aria-label="年" class="border border-app-border rounded px-2 py-1">
                <option value="">年: すべて</option>
                @foreach ($tradeYears as $tradeYear)
                    <option value="{{ $tradeYear }}">{{ $tradeYear }}年</option>
                @endforeach
            </select>
        </div>

        <table class="w-full text-[13px]">
            <thead>
                <tr class="text-left text-text-secondary border-b border-app-border">
                    <th class="py-1.5 px-1.5">約定日</th>
                    <th class="py-1.5 px-1.5">銘柄</th>
                    <th class="py-1.5 px-1.5">種類</th>
                    <th class="py-1.5 px-1.5 text-right">金額</th>
                    <th class="py-1.5 px-1.5">比較</th>
                    <th class="py-1.5 px-1.5 text-right">+4週</th>
                    <th class="py-1.5 px-1.5 text-right">+13週</th>
                    <th class="py-1.5 px-1.5 text-right">+26週</th>
                    <th class="py-1.5 px-1.5">直前の状況</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($trades as $trade)
                    @php $rows = $trade['comparisons'] ?: [['label' => '—', 'cells' => []]]; @endphp
                    @foreach ($rows as $comparison)
                        <tr class="{{ $loop->last ? 'border-b border-app-border' : '' }} align-top">
                            @if ($loop->first)
                                <td class="py-1.5 px-1.5" rowspan="{{ count($rows) }}">{{ $trade['date'] }}</td>
                                <td class="py-1.5 px-1.5" rowspan="{{ count($rows) }}">{{ $trade['code'] }} <span class="text-text-secondary">{{ $trade['name'] }}</span></td>
                                <td class="py-1.5 px-1.5" rowspan="{{ count($rows) }}">{{ $trade['kind'] === 'sell' ? '売却' : '買付' }}</td>
                                <td class="py-1.5 px-1.5 text-right" rowspan="{{ count($rows) }}">{{ number_format($trade['amount_jpy']) }}円</td>
                            @endif
                            <td class="py-1.5 px-1.5">{{ $comparison['label'] }}</td>
                            @foreach ([4, 13, 26] as $horizon)
                                <td class="py-1.5 px-1.5 text-right {{ $tone($comparison['cells'][$horizon] ?? null) }}">{{ $comparison['cells'][$horizon] ?? '' }}</td>
                            @endforeach
                            @if ($loop->first)
                                <td class="py-1.5 px-1.5" rowspan="{{ count($rows) }}">
                                    @foreach ($trade['context']['signals'] as $signalType)
                                        <x-badge variant="warning">{{ $signalType }}</x-badge>
                                    @endforeach
                                    @if ($trade['context']['held_label'] !== null)
                                        <span class="text-text-secondary">{{ $trade['context']['held_label'] }}</span>
                                    @endif
                                    @unless ($trade['context']['known'])
                                        <span class="text-text-secondary">状況不明</span>
                                    @endunless
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>

        <div class="mt-3">{{ $trades->links() }}</div>
    @endif
</div>
