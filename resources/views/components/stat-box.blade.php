{{-- _shared.css の .stat-box 相当。$tone で値の色を切り替える。 --}}
@props(['label', 'tone' => null])
@php
    $toneClasses = match ($tone) {
        'success' => 'text-success',
        'danger' => 'text-danger',
        default => 'text-text',
    };
@endphp
<div class="px-5 py-4">
    <div class="text-xs text-text-secondary mb-1">{{ $label }}</div>
    <div class="text-[22px] font-bold {{ $toneClasses }}" style="font-variant-numeric: tabular-nums;">
        {{ $slot }}
    </div>
</div>
