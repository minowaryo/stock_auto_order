<?php

namespace App\Console\Commands;

use App\Services\Research\ResearchSourceCheckService;
use Illuminate\Console\Command;

class ResearchCheckSourcesCommand extends Command
{
    protected $signature = 'research:check-sources {--force : 最低間隔を無視して手動確認する}';

    protected $description = '公開情報源の新着を確認し、項目と取得試行を記録する';

    public function handle(ResearchSourceCheckService $service): int
    {
        $summary = $service->check((bool) $this->option('force'));
        $this->info("情報源確認: {$summary['checked']}件、失敗: {$summary['failed']}件、省略: {$summary['skipped']}件");

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
