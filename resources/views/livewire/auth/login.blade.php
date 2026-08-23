{{-- Livewireのフルページコンポーネントは単一ルート要素のみ許容されるため、
     レイアウト（<html>全体）はコンポーネントクラス側の#[Layout]属性で
     指定し、このビューはコンポーネント自身のマークアップのみを持つ。 --}}
<div class="max-w-sm mx-auto mt-16">
    <x-card>
        <h1 class="text-lg font-semibold mb-4">ログイン</h1>
        <form wire:submit="login" class="flex flex-col gap-4">
            <div>
                <label for="email" class="block text-[13px] font-medium mb-1">メールアドレス</label>
                <input
                    type="email"
                    id="email"
                    wire:model="email"
                    autofocus
                    class="w-full px-3 py-2 border border-app-border rounded-md text-sm"
                >
                @error('email')
                    <p class="text-danger text-[13px] mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="password" class="block text-[13px] font-medium mb-1">パスワード</label>
                <input
                    type="password"
                    id="password"
                    wire:model="password"
                    class="w-full px-3 py-2 border border-app-border rounded-md text-sm"
                >
            </div>
            <x-btn type="submit" class="w-full">ログイン</x-btn>
        </form>
    </x-card>
</div>
