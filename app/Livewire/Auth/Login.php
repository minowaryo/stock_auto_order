<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 単一利用者アプリの最小ログイン画面（Breeze/Fortify等は導入しない、
 * stock_auto_order-frontend-implementation-phase.md Phase0）。
 */
#[Layout('components.layouts.app', ['title' => 'ログイン'])]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(): void
    {
        $credentials = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが正しくありません',
            ]);
        }

        session()->regenerate();

        $this->redirect('/holdings', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
