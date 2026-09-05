<div>
    <x-page-header title="取込後サマリーレポート" :caption="$importedAtLabel ? '取込日時: '.$importedAtLabel : null" />

    <x-summary-report-body :report="$report" />
</div>
