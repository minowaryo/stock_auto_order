{{--
    UC-005 / UC-015 (ADR-0021 D5): セクター配分 <-> 集中度 の切替タブ。
    グローバルナビ（6タブ）は変えず、両画面のヘッダー直下に置く。
--}}
@props(['current'])
@php
    $tabs = ['sector' => ['セクター配分', '/sector-dashboard'], 'concentration' => ['集中度', '/concentration-dashboard']];
@endphp
<nav aria-label="セクターと集中度の切替" class="flex gap-1 mb-4 border-b border-app-border">
    @foreach ($tabs as $key => [$label, $href])
        <a href="{{ $href }}" wire:navigate
           @if ($current === $key) aria-current="page" @endif
           class="px-3 py-2 rounded-md text-[13px] font-medium {{ $current === $key ? 'text-primary bg-blue-50' : 'text-text-secondary hover:bg-app-bg' }}">{{ $label }}</a>
    @endforeach
</nav>
