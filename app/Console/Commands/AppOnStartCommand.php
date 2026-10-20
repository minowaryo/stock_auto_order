<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** UC-016 / ADR-0031: complete each startup task before starting the next. */
class AppOnStartCommand extends Command
{
    protected $signature = 'app:on-start {--force : 情報源確認の最小間隔を無視する}';

    protected $description = '価格追跡、情報源確認を起動時に順番に実行する';

    public function handle(): int
    {
        $failed = false;
        foreach (['price:track', 'research:check-sources', 'research:discover-candidates'] as $command) {
            $previousTrigger = config('research_automation.trigger');
            try {
                if ($command === 'research:discover-candidates') {
                    config(['research_automation.trigger' => 'startup']);
                }
                $arguments = $command === 'research:check-sources' && $this->option('force')
                    ? ['--force' => true] : [];
                if ($this->call($command, $arguments) !== self::SUCCESS) {
                    $failed = true;
                    $this->warn($command.' が失敗しました。次の処理へ進みます。');
                }
            } catch (Throwable $exception) {
                $failed = true;
                Log::warning('Startup task failed', ['command' => $command, 'exception_type' => $exception::class]);
                $this->warn($command.' を完了できませんでした。次の処理へ進みます。');
            } finally {
                config(['research_automation.trigger' => $previousTrigger]);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
