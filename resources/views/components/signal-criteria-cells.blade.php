{{--
    売買シグナル画面（利確検討・買い増し候補）の1行分の判定チェックリストセル群。
    signal-table-head.blade.php とペアで両テーブルに共通の列数・列順を保証する。
--}}
@props(['criteria'])
@foreach ($criteria['technical'] as $item)
    <td class="py-1.5 px-1.5"><x-criteria-chip :item="$item" /></td>
@endforeach
@foreach ($criteria['fundamental'] as $item)
    <td class="py-1.5 px-1.5"><x-criteria-chip :item="$item" /></td>
@endforeach
