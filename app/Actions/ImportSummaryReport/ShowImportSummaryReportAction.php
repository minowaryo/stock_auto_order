<?php

namespace App\Actions\ImportSummaryReport;

use App\Actions\Portfolio\ClassifyHoldingsAction;
use App\Models\ImportBatch;

/**
 * UC-009 (取込後サマリーレポート): re-projects UC-013's portfolio
 * classification (ClassifyHoldingsAction) into the summary report tab's
 * top section. ADR-0014 D9: this Action previously ran its own
 * composite-score candidate selection (買い増し/リバランス/新規投資候補,
 * ADR-0003) independently of UC-004/UC-005/UC-010/UC-011/UC-012 — that
 * duplicated logic is retired in favor of re-using ClassifyHoldingsAction's
 * output directly (D9-1). No new thresholds are introduced here (D2).
 *
 * D9-2: unlike the previous version, this Action no longer writes to
 * import_summary_reports / import_summary_report_items — it is a pure,
 * read-only re-computation on every call (same convention as
 * ClassifyHoldingsAction itself).
 */
class ShowImportSummaryReportAction
{
    public function __construct(
        private readonly ClassifyHoldingsAction $classifyHoldingsAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(ImportBatch $importBatch): array
    {
        $classification = $this->classifyHoldingsAction->execute();

        return [
            'portfolio_headline' => $this->buildHeadline($classification),
            'generated_at' => now(),
            'classification' => $classification,
        ];
    }

    /**
     * ADR-0014 D9-3: バケツ件数・積立コア比率の集計文に変更する（旧: 単一
     * 候補ハイライト文）。
     *
     * @param  array<string, mixed>  $classification
     */
    private function buildHeadline(array $classification): string
    {
        $buckets = collect($classification['buckets'])->keyBy('bucket');
        $bucketCount = fn (string $bucket) => count($buckets->get($bucket)['holdings'] ?? []);

        $coreRate = $classification['hold_breakdown']['core_accumulation']['allocation_rate'] ?? 0.0;

        return sprintf(
            '整理検討%d件・利確検討%d件・買い増し候補%d件、積立コア比率%s%%',
            $bucketCount('loss_review'),
            $bucketCount('take_profit'),
            $bucketCount('add_on'),
            $this->fmt((float) $coreRate),
        );
    }

    private function fmt(float $value): string
    {
        return (string) round($value, 1);
    }
}
