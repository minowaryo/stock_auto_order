{{--
    売買シグナル画面（利確検討・買い増し候補、CHG-0007/CHG-0009）の判定チェックリスト
    付きテーブルで共有する列幅定義。両テーブルが同じ<colgroup>を使うことで、内容が
    異なる列（財務健全性/理由サマリ等）でも見た目の列幅を完全に一致させ、フォーマットの
    見た目のばらつきを無くす。テーブル側は table-fixed 前提（colgroupの幅がそのまま
    列幅になり、内容量では変化しない）。
--}}
@props(['technicalCount', 'fundamentalCount'])
<colgroup>
    <col class="w-[90px]">
    <col class="w-14">
    <col class="w-[130px]">
    <col class="w-[150px]">
    <col class="w-[150px]">
    @for ($i = 0; $i < $technicalCount; $i++)
        <col class="w-[72px]">
    @endfor
    @for ($i = 0; $i < $fundamentalCount; $i++)
        <col class="w-[72px]">
    @endfor
</colgroup>
