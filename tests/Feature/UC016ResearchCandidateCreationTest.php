<?php

namespace Tests\Feature;

use App\Livewire\Candidate\CandidateResearch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * UC-016「市場からの調査候補発見・確認」: 手動記録の最初の Red サイクル。
 *
 * Gate 4 で確認する画面契約: /candidate-research の CandidateResearch が
 * form 配列を受け取り saveCandidate() で保存する。
 * 本テストでは元資料・上場主体が未確認の段階だけを扱い、監視への受け渡しは
 * 後続の Red サイクルで検証する。
 */
function uc016UnidentifiedCandidateInput(): array
{
    return [
        'theme' => 'フィジカルAI',
        'theme_relation' => '搬送ロボットの量産に関係する可能性がある',
        'event_title' => 'ロボット関連の紹介',
        'discovery_url' => 'https://example.org/articles/robot-introduction',
        'source_title' => 'ロボット関連の公開記事',
        'publisher' => '公開記事の発行者',
        'checked_on' => '2026-10-04',
        'summary' => '紹介記事に社名が出た。元発表と上場主体は未確認。',
        'legal_name' => '未同定ロボット株式会社',
        'original_url' => null,
        'original_publisher' => null,
        'announced_on' => null,
        'entity_role' => null,
    ];
}

test('UC-016 市場からの調査候補発見・確認は認証済み本人に調査候補画面を表示する', function () {
    $this->actingAs(User::factory()->create())
        ->get('/candidate-research')
        ->assertOk()
        ->assertSee('調査候補');
});

test('UC-016 市場からの調査候補発見・確認は未認証者をログインへ戻す', function () {
    $this->get('/candidate-research')->assertRedirect('/login');
});

test('UC-016 市場からの調査候補発見・確認は未同定の法人を出典付きで記録し監視銘柄にしない', function () {
    Http::fake();

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->set('form', uc016UnidentifiedCandidateInput())
        ->call('saveCandidate')
        ->assertHasNoErrors();

    $event = DB::table('research_events')->sole();
    expect($event->announced_on)->toBeNull()
        ->and($event->original_url)->toBeNull()
        ->and($event->verification_status)->toBe('unverified');

    $link = DB::table('research_discovery_links')->sole();
    expect($link->research_event_id)->toBe($event->id)
        ->and($link->route_type)->toBe('manual')
        ->and($link->url)->toBe('https://example.org/articles/robot-introduction')
        ->and($link->source_title)->toBe('ロボット関連の公開記事')
        ->and($link->publisher)->toBe('公開記事の発行者')
        ->and($link->checked_on)->toBe('2026-10-04')
        ->and($link->summary)->toBe('紹介記事に社名が出た。元発表と上場主体は未確認。');

    $entity = DB::table('research_entities')->sole();
    expect($entity->legal_name)->toBe('未同定ロボット株式会社')
        ->and($entity->identification_status)->toBe('unidentified');

    $candidate = DB::table('research_candidates')->sole();
    expect($candidate->research_event_id)->toBe($event->id)
        ->and($candidate->research_entity_id)->toBe($entity->id)
        ->and($candidate->theme)->toBe('フィジカルAI')
        ->and($candidate->theme_relation)->toBe('搬送ロボットの量産に関係する可能性がある')
        ->and($candidate->status)->toBe('investigating')
        ->and($candidate->evidence_stage)->toBe('unknown')
        ->and($candidate->lock_version)->toBe(1);

    $revision = DB::table('research_candidate_revisions')->sole();
    expect($revision->research_candidate_id)->toBe($candidate->id)
        ->and($revision->change_type)->toBe('created')
        ->and($revision->lock_version)->toBe(1);

    expect(DB::table('research_entity_listings')->count())->toBe(0)
        ->and(DB::table('holdings')->count())->toBe(0)
        ->and(DB::table('watchlist_items')->count())->toBe(0)
        ->and(DB::table('research_watchlist_handoffs')->count())->toBe(0);

    Http::assertNothingSent();
});

test('UC-016 市場からの調査候補発見・確認は必須の出典と法人名がない記録を拒否する', function () {
    $input = uc016UnidentifiedCandidateInput();
    $input['theme'] = '';
    $input['theme_relation'] = '';
    $input['discovery_url'] = '';
    $input['source_title'] = '';
    $input['publisher'] = '';
    $input['checked_on'] = '';
    $input['summary'] = '';
    $input['legal_name'] = '';

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->set('form', $input)
        ->call('saveCandidate')
        ->assertHasErrors([
            'form.theme' => 'required',
            'form.theme_relation' => 'required',
            'form.discovery_url' => 'required',
            'form.source_title' => 'required',
            'form.publisher' => 'required',
            'form.checked_on' => 'required',
            'form.summary' => 'required',
            'form.legal_name' => 'required',
        ]);

    expect(DB::table('research_candidates')->count())->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認は不正なURLと確認日と文字数超過を拒否する', function () {
    $input = uc016UnidentifiedCandidateInput();
    $input['discovery_url'] = 'file:///etc/passwd';
    $input['checked_on'] = '2026-02-30';
    $input['summary'] = str_repeat('あ', 2001);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->set('form', $input)
        ->call('saveCandidate')
        ->assertHasErrors([
            'form.discovery_url',
            'form.checked_on',
            'form.summary' => 'max',
        ]);

    expect(DB::table('research_candidates')->count())->toBe(0);
});

test('UC-016 市場からの調査候補発見・確認は2048文字を超える発見経路URLを拒否する', function () {
    $input = uc016UnidentifiedCandidateInput();
    $input['discovery_url'] = 'https://example.org/'.str_repeat('a', 2030);

    Livewire::actingAs(User::factory()->create())
        ->test(CandidateResearch::class)
        ->set('form', $input)
        ->call('saveCandidate')
        ->assertHasErrors(['form.discovery_url' => 'max']);

    expect(DB::table('research_candidates')->count())->toBe(0);
});
