{{--
    docs/product/ui-guidelines.md「Primary=1画面に1つまで／Secondary=
    サブアクション」。$tag で <button>（デフォルト、wire:click等と併用）
    か <a>（ナビゲーション用）かを切り替える。
--}}
@props(['variant' => 'primary', 'tag' => 'button'])
@php
    $variantClasses = $variant === 'secondary'
        ? 'bg-surface border border-app-border text-text hover:bg-app-bg'
        : 'bg-primary text-white hover:bg-blue-700';
@endphp
<{{ $tag }} {{ $attributes->class(['inline-flex items-center justify-center px-4 py-2 rounded-md text-[13px] font-medium disabled:opacity-50 disabled:cursor-not-allowed', $variantClasses]) }}>
    {{ $slot }}
</{{ $tag }}>
