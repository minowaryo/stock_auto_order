<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** UC-016 / ADR-0031 D1: only child commands are replaced, never app:on-start. */
function uc016RegisterStartupChild(string $signature, Closure $callback): void
{
    $command = new class($signature, $callback) extends Command
    {
        public function __construct(string $signature, private readonly Closure $callback)
        {
            $this->signature = $signature;
            parent::__construct();
        }

        public function handle(): int
        {
            return ($this->callback)($this);
        }
    };
    app(Kernel::class)->registerCommand($command);
}

test('UC-016 市場からの調査候補発見・確認は価格追跡が完了してから情報源確認を実行する', function () {
    Http::preventStrayRequests();
    $events = [];
    uc016RegisterStartupChild('price:track', function () use (&$events) {
        $events[] = 'price-start';
        $events[] = 'price-finished';

        return 0;
    });
    uc016RegisterStartupChild('research:check-sources {--force}', function () use (&$events) {
        $events[] = 'research';

        return 0;
    });
    $this->artisan('app:on-start')->assertSuccessful();
    expect($events)->toBe(['price-start', 'price-finished', 'research']);
});

test('UC-016 市場からの調査候補発見・確認は価格追跡の失敗やロック中でも情報源確認へ進む', function (string $priceResult) {
    Http::preventStrayRequests();
    $events = [];
    uc016RegisterStartupChild('price:track', function () use (&$events, $priceResult) {
        $events[] = 'price-'.$priceResult;
        if ($priceResult === 'exception') {
            throw new RuntimeException('価格取得失敗');
        }

        return $priceResult === 'failed' ? 1 : 0;
    });
    uc016RegisterStartupChild('research:check-sources {--force}', function () use (&$events) {
        $events[] = 'research';

        return 0;
    });
    Artisan::call('app:on-start');
    expect($events)->toBe(['price-'.$priceResult, 'research']);
})->with(['failed', 'locked', 'exception']);

test('UC-016 市場からの調査候補発見・確認は実行役のforceを情報源確認へ渡す', function () {
    $forced = null;
    uc016RegisterStartupChild('price:track', fn () => 0);
    uc016RegisterStartupChild('research:check-sources {--force}', function (Command $command) use (&$forced) {
        $forced = $command->option('force');

        return 0;
    });
    $this->artisan('app:on-start', ['--force' => true])->assertSuccessful();
    expect($forced)->toBeTrue();
});
