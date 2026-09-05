{{--
    判定チェックリスト（売買シグナル画面、CHG-0007）の1項目チップ。
    docs/product/ui-guidelines.md「判定チェックリストのチップ」参照:
    達成=濃い緑／あと一歩=達成より薄い緑／未達=グレー／データなし=薄いグレー。
--}}
@props(['item'])
@php
    $variantClasses = match ($item['status']) {
        'met' => 'bg-green-100 text-green-800 border-green-200',
        'near' => 'bg-green-50 text-green-700 border-green-100',
        'unmet' => 'bg-slate-50 text-slate-500 border-app-border',
        default => 'bg-slate-50 text-slate-400 border-app-border', // unavailable
    };
@endphp
<div {{ $attributes->class(['flex flex-col items-start gap-0.5 rounded border px-1.5 py-1 text-[10px] leading-tight w-full break-words', $variantClasses]) }}>
    <span class="font-medium">{{ $item['label'] }}</span>
    <span class="text-[11px] font-semibold tabular-nums">{{ $item['value_label'] }}</span>
    <span class="text-text-secondary">{{ $item['threshold_label'] }}</span>
</div>
