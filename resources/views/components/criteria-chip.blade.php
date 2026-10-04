{{--
    判定チェックリスト（売買シグナル画面、CHG-0007）の1項目チップ。
    docs/product/ui-guidelines.md「判定チェックリストのチップ」参照:
    達成=濃い緑／あと一歩=達成より薄い緑／未達=グレー／データなし=薄いグレー。
--}}
@props(['item', 'tone' => 'success'])
@php
    // CHG-0046: キープ表のように1項目ごとに向きが変わる表は item 側の tone を優先する
    // （'warning'=利確寄り＝黄 / 'success'=押し目寄り＝緑）。
    $tone = $item['tone'] ?? $tone;
    // CHG-0034: 業種比較の段階色（label_only は色を付けずラベル文字のみ）／強い良好（達成時のみ）。
    $valuation = $item['valuation'] ?? null;
    $valuationInfo = $valuation ? \App\Support\ValuationDisplay::valuation($valuation['tier'] ?? null) : null;
    $labelOnly = (bool) ($item['label_only'] ?? false);
    $tierClasses = ($valuationInfo && ! $labelOnly) ? \App\Support\ValuationDisplay::chipClasses($valuation['tier']) : null;
    $strongGood = ($item['strength'] ?? null) === 'strong_good' && $item['status'] === 'met';
    $variantClasses = match (true) {
        $strongGood => \App\Support\ValuationDisplay::STRONG_GOOD_CHIP_CLASSES,
        $tierClasses !== null => $tierClasses,
        $valuationInfo !== null => 'bg-slate-50 text-slate-700 border-app-border',
        $item['status'] === 'met' && $tone === 'warning' => 'bg-amber-100 text-amber-800 border-amber-200',
        $item['status'] === 'near' && $tone === 'warning' => 'bg-amber-50 text-amber-700 border-amber-100',
        $item['status'] === 'met' && $tone === 'danger' => 'bg-red-100 text-red-800 border-red-200',
        $item['status'] === 'near' && $tone === 'danger' => 'bg-red-50 text-red-700 border-red-100',
        $item['status'] === 'met' => 'bg-green-100 text-green-800 border-green-200',
        $item['status'] === 'near' => 'bg-green-50 text-green-700 border-green-100',
        $item['status'] === 'unmet' => 'bg-slate-50 text-slate-500 border-app-border',
        // info: 判定基準を持たない実測値表示（ADR-0016 D3、PBR）。unavailableとは
        // 視覚的に区別できるニュートラルな配色にする。
        $item['status'] === 'info' => 'bg-slate-50 text-slate-700 border-app-border',
        default => 'bg-slate-50 text-slate-400 border-app-border', // unavailable
    };
@endphp
<div {{ $attributes->class(['flex flex-col items-start gap-0.5 rounded border px-1.5 py-1 text-[10px] leading-tight w-full break-words', $variantClasses]) }}>
    <span class="font-medium">{{ $item['label'] }}</span>
    <span class="text-[11px] font-semibold tabular-nums">{{ $item['value_label'] }}</span>
    <span class="text-text-secondary">{{ $item['threshold_label'] }}</span>
    @if ($valuationInfo)
        <span data-testid="chip-valuation" class="font-medium">{{ $valuationInfo['label'] }}@if (! empty($valuation['unstable'])) 基準不安定@endif</span>
    @endif
    @if ($strongGood)
        <span data-testid="chip-strength" class="font-medium">強い良好</span>
    @endif
</div>
