<?php

namespace App\Services\Research;

use RuntimeException;

/** Reads the official AI-device CSV; decision dates are not publication dates. */
class ResearchSourceFdaParser
{
    private const HEADERS = ['Date of Final Decision', 'Submission Number', 'Device', 'Company', 'Panel (Lead)', 'Primary Product Code'];

    public function parse(string $body, ?float $deadline = null): array
    {
        $this->assertTimeRemaining($deadline);
        if (! mb_check_encoding($body, 'UTF-8')) {
            throw new RuntimeException('FDA CSVの応答形式を確認できません。');
        }
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $this->assertCsvSyntax($body, $deadline);

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('FDA CSVの応答形式を確認できません。');
        }
        try {
            if (fwrite($stream, $body) !== strlen($body) || ! rewind($stream)
                || fgetcsv($stream, escape: '') !== self::HEADERS) {
                throw new RuntimeException('FDA CSVの応答形式を確認できません。');
            }

            $items = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $this->assertTimeRemaining($deadline);
                if (count($row) !== count(self::HEADERS)) {
                    throw new RuntimeException('FDA CSVの応答形式を確認できません。');
                }
                foreach ($row as $field) {
                    if (! is_string($field) || trim($field) === '') {
                        throw new RuntimeException('FDA CSVの応答形式を確認できません。');
                    }
                }
                [$date, $number, $device, $company, $panel, $productCode] = $row;
                $decisionDate = $this->decisionDate($date);
                $url = $this->submissionUrl($number);
                $metadata = [
                    'company' => $company,
                    'device_name' => $device,
                    'submission_number' => $number,
                    'date_of_final_decision' => $decisionDate,
                    'review_panel' => $panel,
                    'product_code' => $productCode,
                ];
                // Preserve complete fields. Pretty JSON conservatively includes
                // whitespace overhead from MySQL's JSON representation.
                if (strlen(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) > 4096) {
                    throw new RuntimeException('FDAの付帯情報が保存上限を超えました。');
                }
                $items[$url] ??= [
                    'url' => $url,
                    'url_hash' => hash('sha256', $url),
                    'title' => $this->title($company, $device, $number, $decisionDate),
                    'published_at' => null,
                    'metadata' => $metadata,
                ];
            }
        } finally {
            fclose($stream);
        }

        $this->assertTimeRemaining($deadline);
        // The shared screen uses descending insertion IDs. Insert older FDA
        // decisions first without changing creation timestamps or global sorting.
        usort($items, fn (array $left, array $right) => [
            $left['metadata']['date_of_final_decision'], $left['metadata']['submission_number'],
        ] <=> [
            $right['metadata']['date_of_final_decision'], $right['metadata']['submission_number'],
        ]);
        $this->assertTimeRemaining($deadline);

        return $items;
    }

    private function assertCsvSyntax(string $body, ?float $deadline): void
    {
        // fgetcsv alone accepts unclosed quotes. Validate delimiters first so a
        // malformed last column cannot become an apparently successful record.
        $state = 'start';
        $length = strlen($body);
        for ($index = 0; $index < $length; $index++) {
            if ($index % 4096 === 0) {
                $this->assertTimeRemaining($deadline);
            }
            $character = $body[$index];
            if ($state === 'quoted') {
                if ($character === '"') {
                    $state = 'after_quote';
                }

                continue;
            }
            if ($state === 'after_quote' && $character === '"') {
                $state = 'quoted';
            } elseif (in_array($character, [',', "\r", "\n"], true)) {
                $state = 'start';
            } elseif ($state === 'start' && $character === '"') {
                $state = 'quoted';
            } elseif ($state === 'after_quote' || $character === '"') {
                throw new RuntimeException('FDA CSVの応答形式を確認できません。');
            } else {
                $state = 'unquoted';
            }
        }
        if ($state === 'quoted') {
            throw new RuntimeException('FDA CSVの応答形式を確認できません。');
        }
    }

    private function decisionDate(string $date): string
    {
        if (! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/D', $date, $parts)
            || ! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[3])) {
            throw new RuntimeException('FDA CSVの応答形式を確認できません。');
        }

        return $parts[3].'-'.$parts[1].'-'.$parts[2];
    }

    private function submissionUrl(string $number): string
    {
        $base = 'https://www.accessdata.fda.gov/scripts/cdrh/cfdocs/';
        if (preg_match('/^K\d{6}$/D', $number)) {
            return $base.'cfPMN/pmn.cfm?ID='.$number;
        }
        if (preg_match('/^DEN\d{6}$/D', $number)) {
            return $base.'cfpmn/denovo.cfm?id='.$number;
        }
        if (preg_match('/^P\d{6}(?:\/S\d{3})?$/D', $number)) {
            return $base.'cfpma/pma.cfm?id='.str_replace('/', '', $number);
        }

        throw new RuntimeException('FDA CSVの応答形式を確認できません。');
    }

    private function title(string $company, string $device, string $number, string $date): string
    {
        $company = preg_replace('/\s+/u', ' ', trim($company));
        $device = preg_replace('/\s+/u', ' ', trim($device));
        $suffix = ' / '.$number.' / 決定 '.$date;
        $budget = 255 - mb_strlen($suffix) - mb_strlen(' / ');
        $companyBudget = min(mb_strlen($company), intdiv($budget, 2));
        $deviceBudget = min(mb_strlen($device), $budget - $companyBudget);
        $companyBudget = min(mb_strlen($company), $budget - $deviceBudget);

        return $this->abbreviate($company, $companyBudget).' / '.$this->abbreviate($device, $deviceBudget).$suffix;
    }

    private function abbreviate(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }

    private function assertTimeRemaining(?float $deadline): void
    {
        if ($deadline !== null && hrtime(true) / 1_000_000_000 >= $deadline) {
            throw new RuntimeException('接続または応答がタイムアウトしました。');
        }
    }
}
