<?php

namespace Tests\Feature;

/** Synthetic names only; six columns mirror the official FDA download. */
function uc016FdaHeaders(): array
{
    return ['Date of Final Decision', 'Submission Number', 'Device', 'Company', 'Panel (Lead)', 'Primary Product Code'];
}

function uc016FdaRow(string $submission = 'K253628', array $overrides = []): array
{
    return array_replace([
        'Date of Final Decision' => '06/29/2026',
        'Submission Number' => $submission,
        'Device' => 'Synthetic Imaging Assistant',
        'Company' => 'Synthetic Medical Research Co.',
        'Panel (Lead)' => 'Radiology',
        'Primary Product Code' => 'QAS',
    ], $overrides);
}

function uc016FdaCsv(array $rows = [], array $headers = [], bool $bom = false): string
{
    $headers = $headers ?: uc016FdaHeaders();
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, $headers, escape: '');
    foreach ($rows as $row) {
        fputcsv($stream, array_values($row), escape: '');
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return ($bom ? "\xEF\xBB\xBF" : '').$csv;
}

function uc016FdaDefinition(array $overrides = []): array
{
    return uc016SourceDefinition(array_replace([
        'name' => 'FDA AI medical devices',
        'method' => 'file',
        'url' => 'https://www.fda.gov/media/178541/download?attachment=',
        'min_interval_hours' => 168,
        'min_request_interval_seconds' => 30,
        'max_response_bytes' => 1048576,
    ], $overrides));
}
