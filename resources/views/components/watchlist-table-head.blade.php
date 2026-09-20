{{--
    新規投資候補（UC-012、CHG-0016）専用のテーブルヘッダー。この<thead>は
    <colgroup>のみを含むヘッダー専用<table>に入れ、外側のラッパーdiv自身に
    overflow-x-auto + sticky top-0を付与することで縦スクロールへの固定を行う
    （<thead>自身やその祖先のoverflow-x-autoラッパーにsticky/overflowを付けても
    効かないため。詳細はdocs/ai-context/known-pitfalls.md「position: sticky と
    横スクロール用テーブルの分割」参照。横スクロール位置はresources/js/app.jsで
    本文用<table>から同期する）。

    ★・銘柄の2列は横スクロールしても常に見えるよう sticky left-0 / left-10 にする
    （★列幅がw-10=40pxのため銘柄列はleft-10で隣接。watchlist-table-colgroup.blade.php
    と列順・列数を一致させること）。

    RSI・ROE・自己資本比率・営業利益率の単独列は判定チェックリストのチップ
    （実測値・基準・達成色を持つ上位互換の表示）と完全に重複するため置かず、
    判定チェックリストの2段見出し（グループ見出し+項目名）にのみ出す（CHG-0016）。
--}}
@props(['criteria'])
@php
    $labels = [
        ['label' => '★', 'align' => 'text-center', 'sticky' => 'left-0'],
        ['label' => '銘柄', 'align' => 'text-left', 'sticky' => 'left-10'],
        ['label' => '市場', 'align' => 'text-left', 'sticky' => null],
        ['label' => 'フォルダ', 'align' => 'text-left', 'sticky' => null],
        ['label' => '現在値', 'align' => 'text-right', 'sticky' => null],
        ['label' => '52週内位置', 'align' => 'text-right', 'sticky' => null],
        ['label' => '同ｾｸﾀｰ保有比率', 'align' => 'text-right', 'sticky' => null],
        ['label' => '押し目', 'align' => 'text-right', 'sticky' => null],
        ['label' => '財務健全性', 'align' => 'text-left', 'sticky' => null],
        ['label' => 'PER', 'align' => 'text-right', 'sticky' => null],
        ['label' => 'PBR', 'align' => 'text-right', 'sticky' => null],
    ];
@endphp
<thead>
    <tr class="text-left text-text-secondary border-b border-app-border">
        @foreach ($labels as $col)
            <th class="py-1.5 px-1.5 break-words {{ $col['align'] }} {{ $col['sticky'] ? 'sticky '.$col['sticky'].' z-20 bg-surface' : '' }}" rowspan="2">{{ $col['label'] }}</th>
        @endforeach
        <th class="py-1.5 px-1.5 text-center break-words" colspan="{{ count($criteria['technical']) }}">判定チェックリスト（テクニカル）</th>
        <th class="py-1.5 px-1.5 text-center break-words" colspan="{{ count($criteria['fundamental']) }}">判定チェックリスト（財務）</th>
    </tr>
    <tr class="text-left text-text-secondary border-b border-app-border">
        @foreach ($criteria['technical'] as $item)
            <th class="py-1.5 px-1.5 break-words">{{ $item['label'] }}</th>
        @endforeach
        @foreach ($criteria['fundamental'] as $item)
            <th class="py-1.5 px-1.5 break-words">{{ $item['label'] }}</th>
        @endforeach
    </tr>
</thead>
