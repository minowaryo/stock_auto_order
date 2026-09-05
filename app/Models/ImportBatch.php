<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'status',
    'jp_stock_filename',
    'us_stock_filename',
    'mutual_fund_filename',
    'imported_count',
    'error_count',
    'failure_reason',
    'imported_at',
])]
class ImportBatch extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(Snapshot::class);
    }

    public function summaryReport(): HasOne
    {
        return $this->hasOne(ImportSummaryReport::class);
    }

    /**
     * UC-009 (CHG-0008)における「最新の取込バッチ」の定義: スナップショットが
     * 存在するバッチのうち取込日時が最も新しいもの（同時刻はid降順でタイブレーク）。
     * スナップショットが無い（取込自体は完了扱いだがパース失敗等で作成されな
     * かった）バッチはスキップされ、その1つ前の成功バッチが対象になる。
     */
    public function scopeLatestWithSnapshot(Builder $query): Builder
    {
        return $query->whereHas('snapshot')
            ->orderByDesc('imported_at')
            ->orderByDesc('id');
    }
}
