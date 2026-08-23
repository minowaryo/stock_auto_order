{{--
    _shared.css の .card 相当。$accent を指定すると左ボーダー付きの
    強調カード（利確シグナル・偏り警告等の注意喚起セクション）になる。
--}}
@props(['accent' => null])
@php
    $accentClasses = match ($accent) {
        'warning' => 'border-l-4 border-l-warning',
        'danger' => 'border-l-4 border-l-danger',
        default => '',
    };
@endphp
<div {{ $attributes->class(['bg-surface border border-app-border rounded-lg p-5 mb-5', $accentClasses]) }}>
    {{ $slot }}
</div>
