<?php

namespace Tests\Feature;

/** Synthetic content; layout mirrors the official NEDO AI list's five columns. */
function uc016NedoDefinition(array $overrides = []): array
{
    return uc016SourceDefinition(array_replace([
        'name' => 'NEDO公募・採択結果',
        'method' => 'html_list',
        'url' => 'https://www.nedo.go.jp/koubo/2026_list_05_03.html',
        'min_interval_hours' => 24,
        'max_response_bytes' => 1048576,
        'coverage_days' => 365,
    ], $overrides));
}

function uc016NedoDate(string $date): string
{
    $parts = explode('-', $date);

    return count($parts) === 3 ? $parts[0].'年<br>'.(int) $parts[1].'月'.(int) $parts[2].'日' : htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
}

function uc016NedoRow(array $overrides = []): string
{
    $row = array_replace([
        'project_code' => 'P26013',
        'project_name' => '合成AI基盤研究開発事業',
        'result_url' => '/koubo/CD3_199901.html',
        'result_date' => '2026-09-09',
    ], $overrides);
    $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $result = $row['result_url'] === null ? '&nbsp;' : '<a href="'.$escape($row['result_url']).'">'.uc016NedoDate($row['result_date']).'</a>';

    return '<tr><td>【'.$escape($row['project_code']).'】<br />'.$escape($row['project_name']).'</td>'
        .'<td class="centerPosT nw"><a href="/koubo/CD1_199901.html">2026年<br>3月18日</a></td>'
        .'<td class="centerPosT nw"><a href="/koubo/CD2_199901.html">2026年<br>5月22日</a></td>'
        .'<td class="centerPosT nw">2026年<br>6月22日</td>'
        .'<td class="centerPosT nw">'.$result.'</td></tr>';
}

function uc016NedoHtml(?array $rows = null): string
{
    $rows ??= [uc016NedoRow()];

    return '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>合成の公募一覧</title></head><body>'
        .'<nav><a href="/koubo/CD3_188888.html">本文外のナビゲーション</a></nav>'
        .'<table class="bunya-table"><caption class="hidden">2026年度 AI 公募一覧</caption>'
        .'<thead><tr><th scope="col">事業・プロジェクト名</th><th scope="col">予告掲載日</th>'
        .'<th scope="col">公募開始日</th><th scope="col">公募締切日</th><th scope="col">結果</th></tr></thead>'
        .'<tbody>'.implode('', $rows).'</tbody></table>'
        .'<a href="/content/999999999.pdf">ARTICLE_BODY_MUST_NOT_BE_FETCHED</a></body></html>';
}
