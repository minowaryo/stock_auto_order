<?php

namespace App\Services\Candidate;

use App\Models\Holding;
use App\Services\Sector\SectorAllocationCalculator;

/**
 * UC-006 (新規投資候補の重複チェック): computes overlap_rate/diversification_comment
 * for a candidate holding by reusing SectorAllocationCalculator (UC-005) — no new
 * calculation formula is introduced (use-cases.md業務ルール「新規計算式を作らない」).
 */
class CandidateOverlapCalculator
{
    private const UNCLASSIFIED_NAME = '未分類';

    /**
     * @return array{overlap_rate: float, diversification_comment: string}
     */
    public function calculate(Holding $holding, SectorAllocationCalculator $calculator): array
    {
        $sectorName = $holding->sectorClassification?->name ?? self::UNCLASSIFIED_NAME;

        $row = collect($calculator->calculate())
            ->first(fn (array $row) => $row['sector_name'] === $sectorName);

        if ($row === null) {
            return [
                'overlap_rate' => 0.0,
                'diversification_comment' => '現在このセクターの保有はありません。新規投資は分散に貢献します',
            ];
        }

        return [
            'overlap_rate' => $row['allocation_rate'],
            'diversification_comment' => $this->comment($row['allocation_status']),
        ];
    }

    private function comment(string $allocationStatus): string
    {
        return match ($allocationStatus) {
            '健全' => 'このセクターへの追加投資は分散の観点で問題ありません',
            'やや偏り' => 'このセクターの保有比率はやや高めです。追加投資は慎重に検討してください',
            '偏り警告' => 'このセクターの保有比率は既に高い状態です。新規投資は分散の観点で推奨されません',
            default => '現在このセクターの保有はありません。新規投資は分散に貢献します',
        };
    }
}
