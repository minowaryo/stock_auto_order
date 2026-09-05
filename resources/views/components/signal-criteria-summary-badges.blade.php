{{--
    売買シグナル画面（利確検討・買い増し候補）の銘柄セル（sticky left-0）内に表示する
    判定チェックリストの達成数サマリ（例: 「技術 3/7」「財務 2/3」）。
    列を横スクロールしてチップが見えなくなっても、銘柄名と一緒に常に見える位置に
    全体の達成度を出しておくためのもの。色は達成率の目安（全達成=緑／0件=グレー／
    それ以外=黄）で、個々のチップの厳密な基準判定とは独立した簡易表示。
--}}
@props(['criteria'])
@php
    $classFor = fn (array $summary) => match (true) {
        $summary['met'] === $summary['total'] => 'text-green-700',
        $summary['met'] === 0 => 'text-slate-400',
        default => 'text-amber-600',
    };
    $technicalSummary = $criteria['summary']['technical'];
    $fundamentalSummary = $criteria['summary']['fundamental'];
@endphp
<div class="mt-0.5 text-[10px] {{ $classFor($technicalSummary) }}">技術 {{ $technicalSummary['met'] }}/{{ $technicalSummary['total'] }}</div>
<div class="text-[10px] {{ $classFor($fundamentalSummary) }}">財務 {{ $fundamentalSummary['met'] }}/{{ $fundamentalSummary['total'] }}</div>
