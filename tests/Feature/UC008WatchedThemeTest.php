<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WatchedTheme;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| UC-008: 注目テーマ・セクターの登録・更新 — Red phase Feature Test
|--------------------------------------------------------------------------
|
| Source of truth:
|   - docs/product/use-cases.md (UC-008 前提条件・入力表・業務ルール1行目
|     「『注目テーマ・セクター』の登録・更新は本人が手動で行う」)
|   - docs/architecture/data-model.md (watched_themes: `name` varchar(100)
|     NOT NULL, unique index on `name`, no `deleted_at` — 削除機能は
|     use-cases.md未定義のためスコープ外)
|   - C:\Users\minow\.claude\plans\stock_auto_order-uc008-implementation-phase.md
|     (Cycle 1: 注目テーマの登録・更新のみ。候補一覧〔GET /new-candidates〕は
|     次サイクルのスコープ)
|
| `app/Models/WatchedTheme.php` and its migration already exist, but there is
| currently NO Controller, Route, Action, or FormRequest for registering a
| theme at all. Every test below is therefore expected to fail with a 404
| (route not found) rather than an assertion failure — this is the intended
| Red state (same convention as UC004SignalListTest.php, which failed on a
| missing route for an already-existing model/table).
|
| Assumptions made while writing these tests (not yet confirmed by an
| implementation — flag during Gate 4 review if a different contract is
| preferred):
|   - Endpoints: `POST /watched-themes` (create) and `GET /watched-themes`
|     (list), both behind the `auth` middleware, same single-user "web"
|     session guard convention as UC-001〜UC-004.
|   - `POST /watched-themes` success response: 201 Created.
|   - `GET /watched-themes` success response body shape: `{"data": [
|     {"id": ..., "name": ...}, ... ] }` (same wrapper convention as
|     UC-002〜UC-004).
|   - Validation: `name` is required and must be a non-empty string ->
|     422 when missing or an empty string.
|   - **Duplicate registration (name already exists) — confirmed at Gate 4**:
|     re-registering an already-existing `name` is rejected as a 422
|     validation error (not a silent update, not an unhandled 500 from the
|     DB's unique constraint on `name`).
|
*/

/**
 * Register a watched theme as an authenticated user. Passing `null` for
 * `$name` omits the `name` key entirely from the request payload (to
 * exercise the "missing" validation case, as opposed to an empty string).
 */
function ucFrom008TestRegister(TestCase $test, ?User $user, ?string $name): TestResponse
{
    $user ??= User::factory()->create();

    $payload = $name === null ? [] : ['name' => $name];

    return $test->actingAs($user)->postJson('/watched-themes', $payload);
}

/**
 * Fetch the watched theme list as an authenticated user.
 */
function ucFrom008TestList(TestCase $test, ?User $user = null): TestResponse
{
    $user ??= User::factory()->create();

    return $test->actingAs($user)->getJson('/watched-themes');
}

describe('UC-008: 注目テーマ・セクターの登録・更新', function () {
    describe('正常系', function () {
        test('本人は新規テーマ・セクター名を登録できる', function () {
            $user = User::factory()->create();

            $response = ucFrom008TestRegister($this, $user, 'AI半導体');

            $response->assertCreated();

            $this->assertDatabaseHas('watched_themes', [
                'name' => 'AI半導体',
            ]);
        });

        test('本人は登録済みの注目テーマ・セクター一覧を取得できる', function () {
            $user = User::factory()->create();
            WatchedTheme::create(['name' => 'AI半導体']);
            WatchedTheme::create(['name' => '国策関連']);

            $response = ucFrom008TestList($this, $user);

            $response->assertSuccessful();

            $names = collect($response->json('data'))->pluck('name')->all();
            expect($names)->toContain('AI半導体');
            expect($names)->toContain('国策関連');

            $firstRow = $response->json('data')[0];
            expect($firstRow)->toHaveKey('id');
            expect($firstRow)->toHaveKey('name');
        });

        test('テーマが1件も登録されていない場合は空配列が返る', function () {
            $user = User::factory()->create();

            $response = ucFrom008TestList($this, $user);

            $response->assertSuccessful();
            expect($response->json('data'))->toBe([]);
        });
    });

    describe('重複登録', function () {
        test('既に登録済みのテーマ名を再登録しようとすると422エラーになり重複行は作られない（Gate4確定）', function () {
            $user = User::factory()->create();

            $firstResponse = ucFrom008TestRegister($this, $user, 'AI半導体');
            $firstResponse->assertCreated();

            $secondResponse = ucFrom008TestRegister($this, $user, 'AI半導体');
            $secondResponse->assertStatus(422);
            $secondResponse->assertJsonValidationErrors(['name']);

            expect(WatchedTheme::where('name', 'AI半導体')->count())->toBe(1);
        });
    });

    describe('異常系（バリデーション）', function () {
        test('nameが未指定の場合は422が返る', function () {
            $user = User::factory()->create();

            $response = ucFrom008TestRegister($this, $user, null);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['name']);
        });

        test('nameが空文字の場合は422が返る', function () {
            $user = User::factory()->create();

            $response = ucFrom008TestRegister($this, $user, '');

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['name']);
        });
    });

    describe('権限', function () {
        test('未認証ユーザーはテーマを登録できない', function () {
            $response = $this->postJson('/watched-themes', ['name' => 'AI半導体']);

            // Single-user app (docs/architecture/authz-authn.md): unauthenticated
            // access must be rejected, either via a redirect to login (web
            // guard) or a 401/403 (API-style guard). Exact status is an
            // implementation choice left to the Green phase (same convention
            // as UC-001〜UC-004).
            expect($response->status())->toBeIn([302, 401, 403]);

            $this->assertDatabaseMissing('watched_themes', ['name' => 'AI半導体']);
        });

        test('未認証ユーザーはテーマ一覧を取得できない', function () {
            WatchedTheme::create(['name' => 'AI半導体']);

            $response = $this->getJson('/watched-themes');

            expect($response->status())->toBeIn([302, 401, 403]);
        });
    });
});
