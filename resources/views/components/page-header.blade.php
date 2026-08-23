{{-- 各画面共通の見出し（h1 + キャプション）。UC-003等は $backTo で戻るリンクを出す。 --}}
@props(['title', 'caption' => null, 'backTo' => null, 'backLabel' => null])
<div class="mb-6">
    @if ($backTo)
        <a href="{{ $backTo }}" wire:navigate class="text-text-secondary text-[13px] hover:text-text">
            ← {{ $backLabel ?? '戻る' }}
        </a>
    @endif
    <h1 class="text-2xl font-bold mt-1 mb-1">{{ $title }}</h1>
    @if ($caption)
        <p class="text-[13px] text-text-secondary">{{ $caption }}</p>
    @endif
</div>
