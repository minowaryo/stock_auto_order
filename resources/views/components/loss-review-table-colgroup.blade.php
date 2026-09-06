{{--
    整理検討（含み損）候補一覧（UC-011 / F-011, CHG-0010）専用の列幅定義。
    UC-004/UC-010 の共有 signal-table-colgroup とは列構成が異なるため
    （評価額列は持たず、損失の実額・整理判断の要点を持つ）、また CHG-0011 が
    共有 colgroup を編集中のため衝突回避も兼ねて F-011 専用に分離する。
    テーブル側は table-fixed 前提。
--}}
@props(['technicalCount', 'fundamentalCount'])
<colgroup>
    <col class="w-[150px]">
    <col class="w-[70px]">
    <col class="w-[130px]">
    <col class="w-[150px]">
    <col class="w-[220px]">
    @for ($i = 0; $i < $technicalCount; $i++)
        <col class="w-[72px]">
    @endfor
    @for ($i = 0; $i < $fundamentalCount; $i++)
        <col class="w-[72px]">
    @endfor
</colgroup>
