<?php

namespace App\Console\Commands;

use App\Actions\Watchlist\QueueStartupWatchlistRefreshAction;
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
        $failed = ! $this->runStartupCommand('price:track');
        // UC-019: keep recording watchlist indicators/signals on startup (at most every 3 days).
        $failed = ! $this->queueWatchlistRefresh() || $failed;
        $failed = ! $this->runStartupCommand('research:check-sources') || $failed;
        $failed = ! $this->runStartupCommand('research:discover-candidates') || $failed;

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function queueWatchlistRefresh(): bool
    {
        try {
            app(QueueStartupWatchlistRefreshAction::class)->execute();

            return true;
        } catch (Throwable $exception) {
            Log::warning('Startup task failed', ['command' => 'watchlist-refresh', 'exception_type' => $exception::class]);
            $this->warn('ウォッチリスト更新を積めませんでした。次の処理へ進みます。');

            return false;
        }
    }

    private function runStartupCommand(string $command): bool
    {
        $previousTrigger = config('research_automation.trigger');
        try {
            if ($command === 'research:discover-candidates') {
                config(['research_automation.trigger' => 'startup']);
            }
            $arguments = $command === 'research:check-sources' && $this->option('force')
                ? ['--force' => true] : [];
            if ($this->call($command, $arguments) !== self::SUCCESS) {
                $this->warn($command.' が失敗しました。次の処理へ進みます。');

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('Startup task failed', ['command' => $command, 'exception_type' => $exception::class]);
            $this->warn($command.' を完了できませんでした。次の処理へ進みます。');

            return false;
        } finally {
            config(['research_automation.trigger' => $previousTrigger]);
        }
    }
}
