<div>
    <x-page-header title="CSV取込" caption="楽天証券からダウンロードしたCSVファイルを取り込みます" />

    <x-card>
        @if ($importError)
            <div class="mb-4 px-4 py-3 rounded-md bg-red-50 text-danger text-[13px]">
                取込に失敗しました: {{ $importError }}
            </div>
        @endif

        <form wire:submit="import" class="flex flex-col gap-4">
            <div>
                <label for="jp_stock_file" class="block text-[13px] font-medium mb-1">国内株式CSV</label>
                <input type="file" id="jp_stock_file" wire:model="jp_stock_file" class="text-[13px]">
                @error('jp_stock_file')
                    <p class="text-danger text-[13px] mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="us_stock_file" class="block text-[13px] font-medium mb-1">米国株式CSV</label>
                <input type="file" id="us_stock_file" wire:model="us_stock_file" class="text-[13px]">
                @error('us_stock_file')
                    <p class="text-danger text-[13px] mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="mutual_fund_file" class="block text-[13px] font-medium mb-1">投資信託CSV（任意）</label>
                <input type="file" id="mutual_fund_file" wire:model="mutual_fund_file" class="text-[13px]">
                @error('mutual_fund_file')
                    <p class="text-danger text-[13px] mt-1">{{ $message }}</p>
                @enderror
            </div>
            <x-btn type="submit" class="w-fit">取込を実行</x-btn>
        </form>
    </x-card>

    <x-card>
        <h2 class="text-base font-semibold mb-4">取込履歴</h2>

        @if ($recentBatches->isEmpty())
            <x-empty-state>まだ取込履歴はありません</x-empty-state>
        @else
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-2 pr-4">取込日時</th>
                        <th class="py-2 pr-4">国内株式</th>
                        <th class="py-2 pr-4">米国株式</th>
                        <th class="py-2 pr-4">投資信託</th>
                        <th class="py-2 pr-4">状態</th>
                        <th class="py-2 pr-4">取込件数</th>
                        <th class="py-2 pr-4">エラー件数</th>
                        <th class="py-2 pr-4">新規検出</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentBatches as $batch)
                        <tr class="border-b border-app-border last:border-b-0">
                            <td class="py-2 pr-4">{{ $batch->imported_at?->format('Y-m-d H:i') }}</td>
                            <td class="py-2 pr-4">{{ $batch->jp_stock_filename }}</td>
                            <td class="py-2 pr-4">{{ $batch->us_stock_filename }}</td>
                            <td class="py-2 pr-4">{{ $batch->mutual_fund_filename ?? '-' }}</td>
                            <td class="py-2 pr-4">
                                <x-badge variant="{{ $batch->status === 'completed' ? 'success' : ($batch->status === 'failed' ? 'danger' : 'neutral') }}">
                                    {{ $batch->status }}
                                </x-badge>
                            </td>
                            <td class="py-2 pr-4">{{ $batch->imported_count }}</td>
                            <td class="py-2 pr-4">{{ $batch->error_count }}</td>
                            <td class="py-2 pr-4">{{ $newlyDetectedCounts[$batch->id] ?? 0 }}件</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>
</div>
