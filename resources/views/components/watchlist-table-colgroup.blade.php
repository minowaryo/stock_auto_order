{{--
    新規投資候補（UC-012、CHG-0016）専用のテーブル列幅定義。ヘッダー用・本文用の
    両方の<table>がこの<colgroup>を共有することで、table-fixedの列幅を内容量に
    関わらず完全に一致させる（docs/ai-context/known-pitfalls.md「table-fixed + w-max」参照）。
    列を増減・変更する場合は、呼び出し側テーブルの固定幅（w-[1594px]）も必ず同時に更新すること
    （CHG-0012で売買シグナル画面のw-[1440px]→w-[1512px]を更新した先例と同じ）。
--}}
@props(['technicalCount', 'fundamentalCount'])
<colgroup>
    <col class="w-10">     {{-- ★ --}}
    <col class="w-[130px]"> {{-- 銘柄 --}}
    <col class="w-14">     {{-- 市場 --}}
    <col class="w-[120px]"> {{-- フォルダ --}}
    <col class="w-20">     {{-- 現在値 --}}
    <col class="w-16">     {{-- 52週内位置 --}}
    <col class="w-[72px]"> {{-- 同ｾｸﾀｰ保有比率 --}}
    <col class="w-12">     {{-- 押し目 --}}
    <col class="w-20">     {{-- 財務健全性 --}}
    <col class="w-14">     {{-- PER --}}
    <col class="w-14">     {{-- PBR --}}
    @for ($i = 0; $i < $technicalCount; $i++)
        <col class="w-[72px]">
    @endfor
    @for ($i = 0; $i < $fundamentalCount; $i++)
        <col class="w-[72px]">
    @endfor
</colgroup>
