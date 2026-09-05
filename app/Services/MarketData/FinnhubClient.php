<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fetches US-stock fundamentals from the Finnhub API (`stock/metric` /
 * `stock/financials-reported`).
 *
 * docs/adr/ADR-0009-us-stock-fundamentals-finnhub.md
 */
final class FinnhubClient implements FinnhubClientInterface
{
    private const METRIC_URL = 'https://finnhub.io/api/v1/stock/metric';

    private const FINANCIALS_URL = 'https://finnhub.io/api/v1/stock/financials-reported';

    /**
     * Below this remaining-quota count, the next outbound call self-throttles
     * (waits) before issuing its HTTP request.
     */
    private const LOW_REMAINING_THRESHOLD = 10;

    private const THROTTLE_SLEEP_MICROSECONDS = 1_000_000;

    private const RETRY_SLEEP_MICROSECONDS = 1_000_000;

    private const MAX_ATTEMPTS = 3;

    /**
     * Callable invoked in place of a real wait, as `($sleeper)(int $microseconds)`.
     */
    private mixed $sleeper;

    /**
     * Most recently observed `X-Ratelimit-Remaining` response header value.
     */
    private ?int $remaining = null;

    public function __construct(?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function fetchMetrics(string $symbolCode): ?array
    {
        $response = $this->requestWithRetry(self::METRIC_URL, [
            'symbol' => $symbolCode,
            'metric' => 'all',
            'token' => config('services.finnhub.key'),
        ]);

        if ($response === null) {
            return null;
        }

        return $response->json('metric');
    }

    public function fetchReportedFinancials(string $symbolCode, int $periods = 2): array
    {
        $response = $this->requestWithRetry(self::FINANCIALS_URL, [
            'symbol' => $symbolCode,
            'freq' => 'annual',
            'token' => config('services.finnhub.key'),
        ]);

        if ($response === null) {
            return [];
        }

        $data = $response->json('data') ?? [];

        if (empty($data)) {
            return [];
        }

        $data = array_slice($data, 0, $periods);

        return array_map(function (array $report): array {
            $bs = $report['report']['bs'] ?? [];
            $ic = $report['report']['ic'] ?? [];

            return [
                'operating_income' => $this->findConceptValue($ic, 'us-gaap_OperatingIncomeLoss'),
                'total_assets' => $this->findConceptValue($bs, 'us-gaap_Assets'),
                'total_equity' => $this->findConceptValue($bs, 'us-gaap_StockholdersEquity'),
            ];
        }, $data);
    }

    /**
     * @param  array<int, array{concept?: string, value?: mixed}>  $concepts
     */
    private function findConceptValue(array $concepts, string $concept): ?float
    {
        foreach ($concepts as $item) {
            if (($item['concept'] ?? null) === $concept) {
                $value = $item['value'] ?? null;

                return $value === null ? null : (float) $value;
            }
        }

        return null;
    }

    /**
     * Issues the HTTP GET request, self-throttling beforehand if the
     * previous response reported a low remaining-quota count, and
     * transparently retrying (without ever raising an exception to the
     * caller) on HTTP 429. Returns null once retries are exhausted and the
     * request still returns 429.
     *
     * @param  array<string, mixed>  $query
     */
    private function requestWithRetry(string $url, array $query): ?Response
    {
        if ($this->remaining !== null && $this->remaining <= self::LOW_REMAINING_THRESHOLD) {
            ($this->sleeper)(self::THROTTLE_SLEEP_MICROSECONDS);
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(10)->get($url, $query);
            } catch (\Throwable $e) {
                // Unlike JQuantsClient (which sends its key via an x-api-key
                // header), FinnhubClient sends `token` as a query parameter,
                // so the underlying HTTP client's exception message can embed
                // the full request URI (including the token) — never safe to
                // propagate $e->getMessage() as-is here (.claude/rules/
                // 40-security.md "ログに出してはいけないもの").
                throw new \RuntimeException("FinnhubClient: HTTP request to {$url} failed (connection error)");
            }

            if ($response->status() !== 429) {
                $this->updateRemaining($response);

                return $response;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                ($this->sleeper)(self::RETRY_SLEEP_MICROSECONDS);
            }
        }

        return null;
    }

    private function updateRemaining(Response $response): void
    {
        $header = $response->header('X-Ratelimit-Remaining');

        if ($header !== null && $header !== '') {
            $this->remaining = (int) $header;
        }
    }
}
