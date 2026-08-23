{{--
    _shared.css の .badge 相当。success/danger/warning/neutral に加え、
    UC-009の「新規投資候補」種別表示用にinfoバリアントを正式化する
    （調査時点で_shared.cssに未定義だった独自インラインスタイルの正式化）。
--}}
@props(['variant' => 'neutral'])
@php
    $variantClasses = match ($variant) {
        'success' => 'bg-green-100 text-green-700',
        'danger' => 'bg-red-100 text-red-700',
        'warning' => 'bg-amber-100 text-amber-700',
        'info' => 'bg-blue-50 text-primary',
        default => 'bg-slate-100 text-text-secondary',
    };
@endphp
<span {{ $attributes->class(['inline-block px-2 py-0.5 rounded text-xs font-medium', $variantClasses]) }}>
    {{ $slot }}
</span>
