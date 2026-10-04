{{--
    UC-014 (ADR-0017 D8, CHG-0020 Cycle5): 売買シグナル <-> シグナル検証 の切替タブ。
    グローバルナビ（6タブ）は変えず、両画面のヘッダー直下に置く。
--}}
@props(['current'])
@php
    $tabs = ['signals' => ['売買シグナル', '/signals'], 'outcomes' => ['シグナル検証', '/signal-outcomes']];
@endphp
<nav aria-label="売買シグナルとシグナル検証の切替" class="flex gap-1 mb-4 border-b border-app-border">
    @foreach ($tabs as $key => [$label, $href])
        <a href="{{ $href }}" wire:navigate
           @if ($current === $key) aria-current="page" @endif
           class="px-3 py-2 rounded-md text-[13px] font-medium {{ $current === $key ? 'text-primary bg-blue-50' : 'text-text-secondary hover:bg-app-bg' }}">{{ $label }}</a>
    @endforeach
</nav>
