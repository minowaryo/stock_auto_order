<?php

namespace App\Services\Research;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Reads result links from the official AI list, without following article/PDF links. */
class ResearchSourceNedoParser
{
    private const HEADERS = ['事業・プロジェクト名', '予告掲載日', '公募開始日', '公募締切日', '結果'];

    public function parse(string $body, ?float $deadline = null): array
    {
        $this->assertTimeRemaining($deadline);
        if (! mb_check_encoding($body, 'UTF-8')) {
            throw new RuntimeException('NEDO一覧の応答形式を確認できません。');
        }
        // Allow the ordinary HTML5 doctype, but no DTD/entity declarations.
        if (preg_match('/<!ENTITY|<!DOCTYPE(?!\s+html\s*>)/i', $body)) {
            throw new RuntimeException('外部エンティティを含む応答は取得できません。');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $flags = LIBXML_NONET | LIBXML_COMPACT | (defined('LIBXML_NO_XXE') ? LIBXML_NO_XXE : 0);
            // libxml's HTML4 parser does not recognize HTML5's meta charset.
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$body, $flags);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $this->assertTimeRemaining($deadline);
        if (! $loaded) {
            throw new RuntimeException('NEDO一覧の応答形式を確認できません。');
        }

        $xpath = new DOMXPath($document);
        $tables = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " bunya-table ")]');
        if ($tables->length !== 1) {
            throw new RuntimeException('NEDO一覧の応答形式を確認できません。');
        }
        $table = $tables->item(0);
        $headings = $xpath->query('./thead/tr/th', $table);
        $actualHeadings = [];
        foreach ($headings as $heading) {
            $actualHeadings[] = $this->text($heading->textContent);
        }
        $rows = $xpath->query('./tbody/tr', $table);
        if ($actualHeadings !== self::HEADERS || $xpath->query('./thead/tr', $table)->length !== 1
            || $xpath->query('./tbody', $table)->length !== 1 || $rows->length === 0) {
            throw new RuntimeException('NEDO一覧の応答形式を確認できません。');
        }

        $items = [];
        foreach ($rows as $row) {
            $this->assertTimeRemaining($deadline);
            $cells = $xpath->query('./td', $row);
            if ($cells->length !== 5 || $xpath->query('./th', $row)->length !== 0
                || ! preg_match('/^【(P\d{5})】\s*(.+)$/uD', $this->text($cells->item(0)?->textContent ?? ''), $project)) {
                throw new RuntimeException('NEDO一覧の応答形式を確認できません。');
            }
            $result = $cells->item(4);
            $links = $xpath->query('.//a', $result);
            $dateText = $this->text($result->textContent);
            if ($links->length === 0 && $dateText === '') {
                continue;
            }
            if ($links->length !== 1 || ! $links->item(0) instanceof DOMElement) {
                throw new RuntimeException('NEDO一覧の応答形式を確認できません。');
            }
            $url = $this->resultUrl($links->item(0)->getAttribute('href'));
            $date = $this->resultDate($dateText);
            $metadata = ['project_code' => $project[1], 'project_name' => trim($project[2]), 'result_date' => $date];
            // Preserve all required fields; include conservative JSON whitespace overhead.
            if (strlen(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) > 4096) {
                throw new RuntimeException('NEDOの付帯情報が保存上限を超えました。');
            }
            $suffix = ' / 結果 '.$date;
            $name = $metadata['project_code'].' / '.$metadata['project_name'];
            $budget = 255 - mb_strlen($suffix);
            $title = (mb_strlen($name) > $budget ? mb_substr($name, 0, $budget - 1).'…' : $name).$suffix;
            $items[$url] ??= [
                'url' => $url, 'url_hash' => hash('sha256', $url), 'title' => $title,
                'published_at' => null, 'metadata' => $metadata,
            ];
        }
        $this->assertTimeRemaining($deadline);

        return array_values($items);
    }

    private function resultUrl(string $url): string
    {
        // These are the three forms relative to the configured /koubo/ list.
        // Restrict the full value, so credentials, query strings and other hosts
        // cannot be smuggled into an apparently official result URL.
        if (! preg_match('~^(?:https://www\.nedo\.go\.jp/koubo/|/koubo/)?(CD3_\d+\.html)$~D', trim($url), $parts)) {
            throw new RuntimeException('NEDO一覧の応答形式を確認できません。');
        }

        return 'https://www.nedo.go.jp/koubo/'.$parts[1];
    }

    private function resultDate(string $date): string
    {
        $date = preg_replace('/\s+/u', '', $date);
        if (! preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日$/uD', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new RuntimeException('NEDO一覧の応答形式を確認できません。');
        }

        return sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]);
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }

    private function assertTimeRemaining(?float $deadline): void
    {
        if ($deadline !== null && hrtime(true) / 1_000_000_000 >= $deadline) {
            throw new RuntimeException('接続または応答がタイムアウトしました。');
        }
    }
}
