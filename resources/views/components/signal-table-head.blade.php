{{--
    売買シグナル画面（利確検討・買い増し候補、CHG-0007/CHG-0009）の共通テーブルヘッダー。
    両テーブルがこのコンポーネントを共有することでヘッダー構造・列幅・スクロール追従の
    挙動を完全に一致させる（個別にBladeへ書くと表ごとに少しずつずれていく問題への対処）。

    このコンポーネント自体は<thead>の中身（2段の<tr>）のみを描画する。ページスクロールに
    対する縦方向のsticky化は、呼び出し側でこの<thead>を「本文行を含まない、ヘッダー専用の
    <table>」に入れ、その外側のラッパーdivにsticky top-0を付与する形で行う（詳細は
    docs/ai-context/known-pitfalls.md「position: sticky と横スクロール用テーブルの分割」参照。
    <thead>自身やその祖先のoverflow-x-autoラッパーにsticky/overflowを付けても効かないため、
    ヘッダー用とボディ用で<table>を分離し、横スクロール位置はJS（resources/js/app.js）で
    同期している）。
    先頭列（$labels[0]、通常は「銘柄」）はこのヘッダー用<table>自体の横スクロール内で
    追従して見えるよう sticky left-0 にする。
--}}
@props(['labels', 'criteria'])
<thead>
    <tr class="text-left text-text-secondary border-b border-app-border">
        @foreach ($labels as $label)
            <th class="py-1.5 px-1.5 break-words {{ $loop->first ? 'sticky left-0 z-10 bg-surface' : '' }}" rowspan="2">{{ $label }}</th>
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
