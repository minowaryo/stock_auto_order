# 15-frontend.md — フロントエンド固有ルール

> **このファイルの位置づけ**: フロントエンド実装ルールの正本（canonical）。
> `meta/adr/ADR-0005-frontend-stack.md` の選定プロセスでこのプロジェクトが選定した
> フロントエンドスタックの実装ルールをここに記載する。
> 本プロジェクトの選定結果は **Livewire**（`docs/adr/ADR-0001-frontend-stack-selection.md` 参照）。

## 前提バージョン

- **Laravel**: `meta/adr/ADR-0001-use-laravel.md` に準拠
- **Livewire**: 3.x
- **Alpine.js**: Livewire同梱のものを使用（追加のフロントエンドフレームワークは導入しない）
- **CSS**: Tailwind CSS

---

## アーキテクチャ方針

- ページ単位のLivewireコンポーネント（画面ルート）と、汎用・共通コンポーネントを分離する
- ロジックはLivewireコンポーネントクラス（`app/Livewire/`）に書かず、`app/Services/` や `app/Actions/` に委譲する（Fat Livewireコンポーネント禁止）
- Livewireコンポーネントは「表示状態の保持」と「ユーザー操作の受付」に責務を限定する

---

## コンポーネントルール

- 1コンポーネント = 1画面領域の単一責務を守る
- `wire:model` は必要な入力要素にのみ絞る（`wire:model.live` は入力のたびにサーバーへリクエストが飛ぶため、メモ欄など高頻度入力には `wire:model.blur` または `wire:model.live.debounce.500ms` を使う）
- バリデーションはコンポーネントクラスの `rules()` に定義する（Laravel標準のFormRequestと同様、ルールをコンポーネント外に切り出すことも可）
- 認可チェックは `authorize()` を明示的に呼ぶ（Controller同様、Policy/Gate必須。`.claude/rules/10-laravel.md` に従う）

---

## ディレクトリ配置

| パス | 役割 |
|---|---|
| `app/Livewire/` | Livewireコンポーネントクラス（PHP） |
| `resources/views/livewire/` | Livewireコンポーネントに対応するBladeビュー |
| `resources/views/components/` | 汎用・共通のBladeコンポーネント（Livewireに依存しない表示部品） |
| `resources/css/`, `resources/js/` | Tailwind設定・Alpine.jsの追加初期化処理（最小限） |

---

## データ受け渡し・バリデーション

- サーバーサイドバリデーションエラーは Livewire の `$errors` バッグ経由でBladeビューに表示する
- 認証ユーザー等の共有情報は Livewire コンポーネント内で `auth()->user()` を直接参照してよい（Inertiaのようなprops経由の共有は不要）
- 外部API（J-Quants等）や重い指標計算は Livewire コンポーネントから直接呼ばず、`app/Services/` 経由で呼ぶ

---

## 命名規則

| 対象 | 規則 | 例 |
|---|---|---|
| Livewireコンポーネントクラス | PascalCase | `HoldingList.php` |
| Livewireビュー | kebab-case | `holding-list.blade.php` |
| 汎用Bladeコンポーネント | PascalCase（呼び出しはkebab-case） | `Alert.php` / `<x-alert>` |

---

## 禁止事項

- Livewireコンポーネントクラス内へのビジネスロジック直書き（`app/Services/` / `app/Actions/` に委譲する）
- `wire:model.live` の濫用（入力のたびに全量DBクエリが走る実装を避ける）
- Alpine.js以外の追加JSフレームワーク・状態管理ライブラリの導入（必要になった場合はADRを書く）
