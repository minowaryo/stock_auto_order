{{--
    売買シグナル画面4テーブルの銘柄セルに出すNISA保有区分バッジ（CHG-0047）。
    status は App\Support\NisaHoldingStatus の値（nisa_only / nisa_partial / null）。
--}}
@props(['status'])
@if ($status === \App\Support\NisaHoldingStatus::NISA_ONLY)
    <x-badge variant="info">NISAのみ</x-badge>
@elseif ($status === \App\Support\NisaHoldingStatus::NISA_PARTIAL)
    <x-badge variant="neutral">NISA一部</x-badge>
@endif
