{{--
    UC-009 (取込後サマリーレポート) / UC-013 (ポートフォリオ分類ダッシュボード,
    ADR-0014 D9): headline・分類俯瞰（3大分類・「保つ」の内訳・セクター偏り
    サマリ・各バケツの銘柄一覧）の共通描画部。$report の形状は
    App\Actions\ImportSummaryReport\ShowImportSummaryReportAction::execute()
    の戻り値に準拠する（旧・上位10件/補足レコメンドのテーブルは廃止）。
--}}
@props(['report'])
@php
    $classification = $report['classification'];
    $groupSummary = collect($classification['group_summary'])->keyBy('group');
    $totalHeldCount = collect($classification['group_summary'])->sum('holding_count');
    $bucketsByKey = collect($classification['buckets'])->keyBy('bucket');

    $groupLabel = fn (string $group) => match ($group) {
        'reduce' => '減らす',
        'hold' => '保つ',
        'increase' => '増やす',
        default => $group,
    };

    $bucketLabel = fn (string $bucket) => match ($bucket) {
        'core_accumulation' => '積立・インデックスコア',
        'loss_review' => '整理検討',
        'take_profit' => '利確検討',
        'add_on' => '買い増し検討',
        'hold' => 'キープ',
        'new_entry' => '新規購入検討（ウォッチリスト）',
        default => $bucket,
    };

    $bucketHref = fn (string $bucket) => match ($bucket) {
        'loss_review' => '/signals',
        'take_profit' => '/signals',
        'add_on' => '/buy-signals',
        'core_accumulation' => '/holdings',
        'hold' => '/holdings',
        'new_entry' => '/watchlist',
        default => null,
    };

    $fmtRate = fn ($value) => number_format((float) $value, 1);
    $fmtGain = fn ($value) => $value === null ? '-' : sprintf('%+.1f%%', (float) $value);
@endphp

<x-card>
    <p class="text-sm">{{ $report['portfolio_headline'] }}</p>
</x-card>

@if ($totalHeldCount === 0)
    <x-card>
        <x-empty-state>分類対象の保有銘柄がありません</x-empty-state>
    </x-card>
@else
    <x-card>
        <h2 class="text-base font-semibold mb-4">分類俯瞰（減らす／保つ／増やす）</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm mb-4">
            @foreach (['reduce', 'hold', 'increase'] as $group)
                @php($row = $groupSummary->get($group))
                <div class="border border-app-border rounded p-3">
                    <p class="font-semibold">{{ $groupLabel($group) }}</p>
                    <p>{{ $row['holding_count'] }}件・構成比{{ $fmtRate($row['allocation_rate']) }}%</p>
                </div>
            @endforeach
        </div>

        <p class="text-sm text-text-secondary">
            保つの内訳: 積立・インデックスコア {{ $classification['hold_breakdown']['core_accumulation']['holding_count'] }}件（構成比{{ $fmtRate($classification['hold_breakdown']['core_accumulation']['allocation_rate']) }}%） /
            キープ {{ $classification['hold_breakdown']['hold']['holding_count'] }}件（構成比{{ $fmtRate($classification['hold_breakdown']['hold']['allocation_rate']) }}%）
        </p>
    </x-card>

    @if (! empty($classification['sector_overweight_summary']))
        <x-card>
            <h2 class="text-base font-semibold mb-4">セクター偏りサマリ</h2>
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($classification['sector_overweight_summary'] as $sector)
                    <x-badge :variant="$sector['allocation_status'] === '偏り警告' ? 'danger' : ($sector['allocation_status'] === 'やや偏り' ? 'warning' : 'neutral')">
                        {{ $sector['sector_name'] }} {{ $fmtRate($sector['allocation_rate']) }}%（{{ $sector['allocation_status'] }}）
                    </x-badge>
                @endforeach
            </div>
        </x-card>
    @endif

    @foreach (['loss_review', 'take_profit', 'add_on', 'hold', 'core_accumulation'] as $bucket)
        @php($holdings = $bucketsByKey->get($bucket)['holdings'] ?? [])
        @if (! empty($holdings))
            <x-card>
                <h2 class="text-base font-semibold mb-4">
                    <a href="{{ $bucketHref($bucket) }}" wire:navigate class="hover:underline">{{ $bucketLabel($bucket) }}</a>
                    （{{ count($holdings) }}件）
                </h2>
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-text-secondary border-b border-app-border">
                            <th class="py-2 pr-4">銘柄</th>
                            <th class="py-2 pr-4">含み損益率</th>
                            <th class="py-2 pr-4">理由</th>
                            <th class="py-2 pr-4">備考</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($holdings as $holding)
                            <tr class="border-b border-app-border last:border-b-0">
                                <td class="py-2 pr-4">
                                    <a href="/holdings?symbol_code={{ $holding['symbol_code'] }}" wire:navigate class="text-primary hover:underline">
                                        {{ $holding['symbol_code'] }} {{ $holding['symbol_name'] }}
                                    </a>
                                </td>
                                <td class="py-2 pr-4">{{ $fmtGain($holding['unrealized_gain_rate']) }}</td>
                                <td class="py-2 pr-4">
                                    {{ $holding['bucket_reason'] }}
                                    @if ($bucket === 'hold' && $holding['health_line'])
                                        <br><span class="text-text-secondary">{{ $holding['health_line'] }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 flex flex-wrap gap-1">
                                    @if ($holding['overweight_sector'])
                                        <x-badge variant="danger">偏り警告セクター</x-badge>
                                    @endif
                                    @if ($bucket === 'hold' && $holding['hold_watch'])
                                        <x-badge variant="warning">要観察</x-badge>
                                    @endif
                                    @foreach ($holding['also_matched'] as $alsoMatched)
                                        <x-badge variant="neutral">{{ $bucketLabel($alsoMatched) }}にも該当</x-badge>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    @endforeach
@endif

{{--
    保有銘柄が0件でも、ウォッチリスト（未保有）候補は
    ClassifyHoldingsAction::emptyResult() が引き続きnew_entryバケツへ
    詰めて返す設計（保有0件とウォッチリスト0件は独立の状態のため）。
    上の @if ($totalHeldCount === 0) の外に置き、保有0件でも表示されるようにする
    （`/review`指摘: 以前はこのブロックが上のelse内にネストされており、
    保有0件のときウォッチリスト候補があっても一切表示されなかった）。
--}}
@php($newEntryHoldings = $bucketsByKey->get('new_entry')['holdings'] ?? [])
@if (! empty($newEntryHoldings))
    <x-card>
        <h2 class="text-base font-semibold mb-4">
            <a href="/watchlist" wire:navigate class="hover:underline">新規購入検討（ウォッチリスト）</a>
            （{{ count($newEntryHoldings) }}件・評価額構成比には非算入）
        </h2>
        <table class="w-full text-[13px]">
            <thead>
                <tr class="text-left text-text-secondary border-b border-app-border">
                    <th class="py-2 pr-4">銘柄</th>
                    <th class="py-2 pr-4">理由</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($newEntryHoldings as $holding)
                    <tr class="border-b border-app-border last:border-b-0">
                        <td class="py-2 pr-4">{{ $holding['symbol_code'] }} {{ $holding['symbol_name'] }}</td>
                        <td class="py-2 pr-4">{{ $holding['bucket_reason'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
@endif
