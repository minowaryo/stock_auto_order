<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/ResearchSourceFixtures.php';
require_once __DIR__.'/ResearchSourceFdaFixtures.php';

/** Proposed ADR-0033 settings; no production config or schema is installed here. */
function uc016AutomationConfig(array $overrides = []): void
{
    config([
        'cache.default' => 'database',
        'research_sources' => ['S10' => uc016FdaDefinition()],
        'research_automation' => array_replace_recursive([
            'enabled' => true,
            'sources' => ['S10' => ['enabled' => true], 'S09' => ['enabled' => false], 'H02' => ['enabled' => false]],
            'catalogs' => ['jpx_tse' => ['enabled' => false], 'sec_exchange' => ['enabled' => false]],
        ], $overrides),
    ]);
}

function uc016AutomationFdaCsv(array $rows): string
{
    return uc016FdaCsv($rows);
}

function uc016AutomationFakeHttp(array $responses = []): void
{
    uc016FakeSourceResponses($responses ?: [
        'https://www.fda.gov/media/178541/download?attachment=' => Http::response(
            uc016AutomationFdaCsv([uc016FdaRow('K260001')]), 200, ['Content-Type' => 'text/csv'],
        ),
    ]);
}

/** A missing command is a deliberate Red assertion, never a console infrastructure exception. */
function uc016AutomationAssertCommand(): void
{
    expect(array_key_exists('research:discover-candidates', Artisan::all()))
        ->toBeTrue('ADR-0033の公開コマンド research:discover-candidates は未実装です。');
}

/** Replace a child command only; app:on-start itself always runs unmodified. */
function uc016AutomationRegisterStartupChild(string $signature, Closure $callback): void
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

function uc016AutomationDiscover(array $arguments = []): int
{
    uc016AutomationAssertCommand();

    return Artisan::call('research:discover-candidates', $arguments);
}

function uc016AutomationCheckAndDiscover(bool $force = false): int
{
    uc016AutomationAssertCommand();
    expect(Artisan::call('research:check-sources', $force ? ['--force' => true] : []))->toBe(0);

    return uc016AutomationDiscover();
}

/** A pre-feature source item, using the already deployed schema only. */
function uc016AutomationSourceItem(array $overrides = []): int
{
    $checkId = uc016SourceCheck('S10', ['result' => 'baseline']);

    return uc016SourceItem($checkId, array_replace([
        'source_key' => 'S10',
        'url' => 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/cfPMN/pmn.cfm?ID=K260001',
        'title' => 'Synthetic Medical Research Co. / Synthetic Imaging Assistant / K260001 / 決定 2026-06-29',
        'published_at' => null,
        'is_baseline' => true,
        'metadata' => json_encode([
            'company' => 'Synthetic Medical Research Co.', 'device_name' => 'Synthetic Imaging Assistant',
            'submission_number' => 'K260001', 'date_of_final_decision' => '2026-06-29',
            'review_panel' => 'Radiology', 'product_code' => 'QAS',
        ], JSON_THROW_ON_ERROR),
    ], $overrides));
}
