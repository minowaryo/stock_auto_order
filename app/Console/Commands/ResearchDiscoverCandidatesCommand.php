<?php

namespace App\Console\Commands;

use App\Services\Research\ResearchCandidateAutomationService;
use Illuminate\Console\Command;

class ResearchDiscoverCandidatesCommand extends Command
{
    protected $signature = 'research:discover-candidates';

    protected $description = '取得済みFDA資料から未確認の企業下書きを生成する';

    public function handle(ResearchCandidateAutomationService $service): int
    {
        $result = $service->run(config('research_automation.trigger', 'manual'));
        $this->info('企業下書き: '.$result['processed'].'資料、失敗 '.$result['failed'].'件（'.$result['status'].'）。');

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
