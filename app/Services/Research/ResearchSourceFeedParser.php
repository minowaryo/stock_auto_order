<?php

namespace App\Services\Research;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;

/** Reads feed metadata only; article content and embedded media are ignored. */
class ResearchSourceFeedParser
{
    public function parse(string $body, ?float $deadline = null): array
    {
        // No DTD or entity definitions, even when the document claims to be RSS.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            throw new RuntimeException('外部エンティティを含む応答は取得できません。');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $flags = LIBXML_NONET | LIBXML_COMPACT | (defined('LIBXML_NO_XXE') ? LIBXML_NO_XXE : 0);
            $loaded = $document->loadXML($body, $flags);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded || ! $document->documentElement) {
            throw new RuntimeException('RSSの応答形式を確認できません。');
        }
        if ($document->doctype !== null) {
            throw new RuntimeException('外部エンティティを含む応答は取得できません。');
        }
        $this->assertTimeRemaining($deadline);

        $xpath = new DOMXPath($document);
        $root = $document->documentElement;
        if ($root->localName === 'feed' && $root->namespaceURI === 'http://www.w3.org/2005/Atom') {
            $nodes = $xpath->query('/*/*[local-name()="entry"]');
            $atom = true;
        } elseif ($root->localName === 'rss') {
            if ($xpath->query('/rss/channel')->length !== 1) {
                throw new RuntimeException('RSSの応答形式を確認できません。');
            }
            $nodes = $xpath->query('/rss/channel/item');
            $atom = false;
        } else {
            throw new RuntimeException('未対応のフィード形式です。');
        }

        $items = [];
        foreach ($nodes as $node) {
            $this->assertTimeRemaining($deadline);
            if (! $node instanceof DOMElement) {
                continue;
            }
            $url = $atom ? $this->atomUrl($xpath, $node) : $this->text($xpath, $node, './link');
            if (! $this->validUrl($url)) {
                continue;
            }
            $title = mb_substr(strip_tags($this->text($xpath, $node, './*[local-name()="title"]')), 0, 500);
            $date = $this->text($xpath, $node, $atom ? './*[local-name()="published"]' : './pubDate');
            $items[] = [
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'title' => $title,
                'published_at' => $this->publishedAt($date),
                'metadata' => $this->metadata($xpath, $node, $atom),
            ];
        }

        return $items;
    }

    private function atomUrl(DOMXPath $xpath, DOMElement $node): string
    {
        foreach ($xpath->query('./*[local-name()="link"]', $node) as $link) {
            if ($link instanceof DOMElement && in_array($link->getAttribute('rel'), ['', 'alternate'], true)) {
                return trim($link->getAttribute('href'));
            }
        }

        return '';
    }

    private function validUrl(string $url): bool
    {
        return strlen($url) <= 2048 && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null;
    }

    private function text(DOMXPath $xpath, DOMElement $node, string $query): string
    {
        return trim((string) $xpath->query($query, $node)->item(0)?->textContent);
    }

    private function publishedAt(string $date): ?CarbonImmutable
    {
        if ($date === '' || ! preg_match('/^(?:[A-Za-z]{3},\s*)?\d{1,2}\s+[A-Za-z]{3}\s+\d{4}\s+\d{2}:\d{2}(?::\d{2})?\s+(?:[A-Za-z]{1,5}|[+-]\d{4})$|^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $date)) {
            return null;
        }
        try {
            // A mismatching weekday must not silently move the calendar date.
            $parsed = CarbonImmutable::parse(preg_replace('/^[A-Za-z]{3},\s*/', '', $date))->utc();
            $errors = $parsed->getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }

            // MySQL TIMESTAMP cannot represent dates outside this range.
            return $parsed->getTimestamp() > 0 && $parsed->getTimestamp() <= 2147483647 ? $parsed : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function assertTimeRemaining(?float $deadline): void
    {
        if ($deadline !== null && hrtime(true) / 1_000_000_000 >= $deadline) {
            throw new RuntimeException('接続または応答がタイムアウトしました。');
        }
    }

    private function metadata(DOMXPath $xpath, DOMElement $node, bool $atom): ?array
    {
        $metadata = [];
        $author = $this->text($xpath, $node, $atom
            ? './*[local-name()="author"]/*[local-name()="name"]'
            : './*[local-name()="creator" or local-name()="author"]');
        if ($author !== '') {
            $metadata['author'] = $author;
        }
        $categories = [];
        foreach ($xpath->query('./*[local-name()="category"]', $node) as $category) {
            $value = $atom && $category instanceof DOMElement ? $category->getAttribute('term') : $category->textContent;
            $value = trim($value);
            if ($value !== '') {
                $categories[] = $value;
            }
        }
        if ($categories !== []) {
            $metadata['categories'] = array_values(array_unique($categories));
        }

        // Keep whole provided fields, without silently truncating their values.
        // Oversized optional metadata is omitted; titles/URLs remain available.
        return $metadata !== [] && strlen(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) <= 4096 ? $metadata : null;
    }
}
