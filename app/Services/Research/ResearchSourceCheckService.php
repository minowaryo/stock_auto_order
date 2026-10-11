<?php

namespace App\Services\Research;

use App\Models\ResearchSourceCheck;
use App\Models\ResearchSourceItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

class ResearchSourceCheckService
{
    public function __construct(private ResearchSourceFetcher $fetcher, private ResearchSourceFeedParser $parser) {}

    /** @return array{checked: int, failed: int, skipped: int} */
    public function check(bool $force = false): array
    {
        $summary = ['checked' => 0, 'failed' => 0, 'skipped' => 0];
        $sources = config('research_sources', []);
        $lock = Cache::store('database')->lock('research-source-check', 600);
        if (! $lock->get()) {
            $summary['skipped'] = count($sources);

            return $summary;
        }

        $startedAt = now();
        $monotonicStart = hrtime(true);
        $requested = false;
        try {
            foreach ($sources as $key => $source) {
                $last = ResearchSourceCheck::where('source_key', $key)->latest('started_at')->latest('id')->first();
                if (! ($source['enabled'] ?? false)
                    || (! $force && $last && now()->lt($last->started_at->addHours((int) ($source['min_interval_hours'] ?? 24))))) {
                    $summary['skipped']++;

                    continue;
                }
                if ($requested) {
                    if ($this->remaining($startedAt, $monotonicStart) <= 1.5) {
                        $summary['skipped']++;

                        continue;
                    }
                    Sleep::for(1500)->milliseconds();
                }
                $remaining = $this->remaining($startedAt, $monotonicStart);
                if ($remaining <= 0) {
                    $summary['skipped']++;

                    continue;
                }
                $requested = true;
                $summary['checked']++;
                if (! $this->checkSource($key, $source, $force, min(15, $remaining))) {
                    $summary['failed']++;
                }
            }
        } finally {
            $lock->release();
        }

        return $summary;
    }

    private function remaining($startedAt, int $monotonicStart): float
    {
        return 120 - max($startedAt->diffInSeconds(now()), (hrtime(true) - $monotonicStart) / 1_000_000_000);
    }

    private function checkSource(string $key, array $source, bool $force, float $timeout): bool
    {
        $check = ResearchSourceCheck::create([
            'source_key' => $key, 'trigger' => $force ? 'manual' : 'startup', 'started_at' => now(),
        ]);
        $deadline = hrtime(true) / 1_000_000_000 + $timeout;
        try {
            $response = $this->fetcher->fetch($source, $timeout);
            $check->http_status = $response->status();
            if (! $response->successful()) {
                $response->toPsrResponse()->getBody()->close();
                throw new RuntimeException('HTTPエラーで取得に失敗しました。');
            }
            $body = $this->fetcher->read($response, (int) ($source['max_response_bytes'] ?? 1048576), $deadline);
            if (($source['method'] ?? '') !== 'rss') {
                throw new RuntimeException('この取得方式の応答構造は未確認です。');
            }
            $items = $this->parser->parse($body, $deadline);
            $baseline = ! ResearchSourceCheck::where('source_key', $key)->whereIn('result', ['new_items', 'no_new', 'baseline'])->exists();
            DB::transaction(function () use ($check, $items, $baseline, $deadline): void {
                $created = 0;
                foreach ($items as $item) {
                    if (hrtime(true) / 1_000_000_000 >= $deadline) {
                        throw new RuntimeException('接続または応答がタイムアウトしました。');
                    }
                    if (! ResearchSourceItem::where('source_key', $check->source_key)->where('url_hash', $item['url_hash'])->exists()) {
                        ResearchSourceItem::create($item + [
                            'source_key' => $check->source_key, 'first_check_id' => $check->id, 'is_baseline' => $baseline,
                        ]);
                        $created++;
                    }
                }
                $check->fill([
                    'result' => $baseline ? 'baseline' : ($created > 0 ? 'new_items' : 'no_new'),
                    'item_count' => count($items), 'new_item_count' => $baseline ? 0 : $created, 'finished_at' => now(),
                ])->save();
            });

            return true;
        } catch (Throwable $exception) {
            $check->fill(['result' => 'failed', 'finished_at' => now(), 'error_message' => $this->safeError($exception)])->save();

            return false;
        }
    }

    private function safeError(Throwable $exception): string
    {
        // Exception text can contain request URLs, tokens or response bodies.
        if ($exception instanceof ConnectionException) {
            return '接続または応答がタイムアウトしました。';
        }
        $safeMessages = [
            '応答サイズが上限を超えました。', '情報源URLの設定を確認してください。',
            '外部エンティティを含む応答は取得できません。', 'RSSの応答形式を確認できません。',
            '未対応のフィード形式です。', '応答の読み取りに失敗しました。',
            'HTTPエラーで取得に失敗しました。', 'この取得方式の応答構造は未確認です。',
            '接続または応答がタイムアウトしました。',
        ];
        // Guzzle wraps exceptions raised by on_headers in a RequestException.
        for ($cause = $exception; $cause; $cause = $cause->getPrevious()) {
            if (in_array($cause->getMessage(), $safeMessages, true)) {
                return $cause->getMessage();
            }
        }

        return '情報源の取得または保存に失敗しました。';
    }
}
