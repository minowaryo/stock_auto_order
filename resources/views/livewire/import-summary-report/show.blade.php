<div>
    <x-page-header title="取込後サマリーレポート" :caption="$importedAtLabel ? '分類俯瞰の基準日時: '.$importedAtLabel : null" />

    <x-summary-report-body :report="$report" />
</div>
