<?php

namespace App\Services\Research;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Requests one source, streams at most the configured limit and never follows links. */
class ResearchSourceFetcher
{
    public function fetch(array $source, float $timeout): Response
    {
        $url = $source['url'] ?? '';
        if (! is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException('情報源URLの設定を確認してください。');
        }
        $limit = (int) ($source['max_response_bytes'] ?? 1048576);
        $accept = match ($source['method'] ?? '') {
            'file' => 'text/csv, application/csv, application/octet-stream',
            'html_list' => 'text/html, application/xhtml+xml',
            default => 'application/rss+xml, application/atom+xml, application/xml, text/xml',
        };

        return Http::timeout($timeout)->connectTimeout($timeout)->withoutRedirecting()
            ->withHeaders(['User-Agent' => 'StockAutoOrder-ResearchSourceCheck/1.0', 'Accept' => $accept])
            ->withOptions([
                'stream' => true,
                'read_timeout' => $timeout,
                'on_headers' => function (ResponseInterface $response) use ($limit): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                        throw new RuntimeException('応答サイズが上限を超えました。');
                    }
                },
            ])->get($url);
    }

    public function read(Response $response, int $limit, float $deadline): string
    {
        if ($limit < 1 || (int) $response->header('Content-Length') > $limit) {
            $response->toPsrResponse()->getBody()->close();
            throw new RuntimeException('応答サイズが上限を超えました。');
        }
        $stream = $response->toPsrResponse()->getBody();
        $resource = $stream->detach();
        if (! is_resource($resource)) {
            $stream->close();
            throw new RuntimeException('応答の読み取りに失敗しました。');
        }
        $body = '';
        try {
            while (! feof($resource)) {
                $remaining = $deadline - hrtime(true) / 1_000_000_000;
                if ($remaining <= 0) {
                    throw new RuntimeException('接続または応答がタイムアウトしました。');
                }
                // The stream handler's timeout is per read. Reduce it before
                // each read so a slow body cannot reset the total deadline.
                $seconds = (int) $remaining;
                $microseconds = max(1, (int) (($remaining - $seconds) * 1_000_000));
                stream_set_timeout($resource, $seconds, $microseconds);
                $chunk = fread($resource, min(8192, $limit - strlen($body) + 1));
                if (hrtime(true) / 1_000_000_000 >= $deadline || (stream_get_meta_data($resource)['timed_out'] ?? false)) {
                    throw new RuntimeException('接続または応答がタイムアウトしました。');
                }
                if ($chunk === false || ($chunk === '' && ! feof($resource))) {
                    throw new RuntimeException('応答の読み取りに失敗しました。');
                }
                $body .= $chunk;
                if (strlen($body) > $limit) {
                    throw new RuntimeException('応答サイズが上限を超えました。');
                }
            }
        } finally {
            fclose($resource);
        }

        return $body;
    }
}
