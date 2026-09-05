<div>
    <x-page-header title="取込後サマリーレポート" :caption="$importedAtLabel ? '取込日時: '.$importedAtLabel : null" />

    @if (empty($report))
        <x-card>
            <x-empty-state>
                まだCSVの取込がありません<br>
                <a href="/csv-import" wire:navigate class="text-primary hover:underline">CSV取込画面へ</a>
            </x-empty-state>
        </x-card>
    @else
        <x-summary-report-body :report="$report" />
    @endif
</div>
