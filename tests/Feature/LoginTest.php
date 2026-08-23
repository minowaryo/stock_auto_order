<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| ログイン画面 — Red phase Feature Test
|--------------------------------------------------------------------------
|
| stock_auto_order-frontend-implementation-phase.md Phase0: Breeze/Fortify
| 等は導入せず、最小限の自作Livewireログイン画面を追加する（単一利用者の
| 個人アプリのため、既存シード〔database/seeders/DatabaseSeeder.php、
| test@example.com / password〕をそのまま使う）。
|
| App\Livewire\Auth\Login は存在しないため、Livewire::test(Login::class)
| は「class not found」で全テストがRedになる。
|--------------------------------------------------------------------------
*/

describe('ログイン画面', function () {
    test('ゲストが/loginにアクセスするとログインフォームが表示される', function () {
        $this->get('/login')->assertOk();
    });

    test('正しいメールアドレス・パスワードでログインすると認証され保有一覧へリダイレクトされる', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        Livewire::test(Login::class)
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect('/holdings');

        $this->assertAuthenticatedAs($user);
    });

    test('誤ったパスワードでログインするとエラーになり認証されない', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        Livewire::test(Login::class)
            ->set('email', 'test@example.com')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    });

    test('存在しないメールアドレスでログインするとエラーになる', function () {
        Livewire::test(Login::class)
            ->set('email', 'nobody@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    });
});

describe('ログアウト', function () {
    test('認証済みユーザーはログアウトできる', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    });

    test('未認証ユーザーがログアウトを実行しても500エラーにならない', function () {
        // auth middleware配下のためリダイレクト（302）になり、5xxにはならない。
        $response = $this->post('/logout');

        expect($response->status())->toBeLessThan(500);
    });
});
