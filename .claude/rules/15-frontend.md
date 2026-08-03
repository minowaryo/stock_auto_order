# 15-frontend.md — フロントエンド固有ルール

> **このファイルの位置づけ**: フロントエンド実装ルールの正本（canonical）。
> `docs/adr/ADR-0005-frontend-stack.md` の選定プロセスでこのプロジェクトが選定した
> フロントエンドスタックの実装ルールをここに記載する。
> 以下はデフォルト推奨である **Vue 3 + Inertia.js + Pinia** を選定した場合の内容。
> Blade / Livewire / React 等、他のスタックを選定した場合は、このファイル自体の内容を
> 選定したスタック用に書き換えること（ファイル名・参照パスは変更しない）。

## 前提バージョン

- **Vue**: 3.3 以上（`useTemplateRef` は 3.5+ のため `ref` を基本とし、3.5+ 環境なら `useTemplateRef` も可）
- **Inertia.js**: @inertiajs/vue3
- **状態管理**: Pinia
- **TypeScript**: 任意（使う場合は末尾の「TypeScript を使う場合の追加ルール」を適用）

---

## アーキテクチャ方針

- Pages と Components の責務を分離する（Inertia の `Pages/` ディレクトリ = ルートコンポーネント）
- ロジックは Composable（`use~`）に集約し、コンポーネントは薄く保つ
- Props のバケツリレー禁止（3段以上になる場合は Pinia か Composable に切り出す）

---

## コンポーネントルール

- 必ず `<script setup>` + Composition API を使う（**Options API 禁止**）
- `defineProps` / `defineEmits` の宣言方式はプロジェクトの言語設定に従う:
  - TypeScript 使用時: `defineProps<{ foo: string }>()` 形式（型パラメータ必須）
  - JavaScript 使用時: `defineProps({ foo: String })` 形式（runtime 宣言）
- コンポーネントは単一責務（1ファイル = 1役割）
- グローバルコンポーネントの登録は最小限に抑える

---

## Inertia.js ルール

### ディレクトリ配置
| パス | 役割 |
|---|---|
| `resources/js/Pages/` | Inertia ページコンポーネント（ルート単位） |
| `resources/js/Components/` | 汎用・共通コンポーネント |
| `resources/js/Composables/` | ロジック分離用 Composable |
| `resources/js/stores/` | Pinia Store |
| `resources/js/app.js` | エントリポイント |

### リンク・ナビゲーション
- **内部遷移**は `<Link>` / `router.visit()` を必ず使う（`<a>` タグ直接使用禁止）
- **外部 URL・`mailto:`・ファイルダウンロード**は `<a>` タグを使ってよい
  - `target="_blank"` を付ける場合は `rel="noopener noreferrer"` を必ず付ける

### データ受け渡し・バリデーション
- サーバーサイドバリデーションエラーは `useForm()` の `errors` で受け取る
- 共有 props（認証ユーザー等）は `usePage().props` で取得する
- ページコンポーネントの `props` は Controller から渡されたデータのみ受け取る

---

## Pinia ルール

- Store は `resources/js/stores/` に配置する
- Store 名は `useXxxStore` 形式（例: `useUserStore`）
- 副作用（API 呼び出し等）は `action` にのみ書く
- **コンポーネントローカルな状態を Store に入れない**（`ref` / `reactive` で管理する）

---

## 命名規則

| 対象 | 規則 | 例 |
|---|---|---|
| Page コンポーネント | PascalCase | `UserIndex.vue` |
| 汎用コンポーネント | PascalCase | `BaseButton.vue` |
| Composable | use + PascalCase | `useUserForm.js` |
| Store | use + PascalCase + Store | `useUserStore.js` |

---

## 禁止事項

- Options API の使用
- `<style scoped>` なしのグローバルスタイル定義（Tailwind 以外）
- `document.querySelector` など DOM 直接操作（テンプレート参照は `ref` を使う）

---

## TypeScript を使う場合の追加ルール

TypeScript は任意。使う場合は以下を適用する:

- `defineProps<{}>()` / `defineEmits<{}>()` で型パラメータを明示する
- `any` 型の使用禁止（型推論できない場合は `unknown` + type guard）
- Composable・Store の引数・戻り値に型注釈を付ける
- ファイル拡張子は `.ts` / `.vue`（`<script setup lang="ts">`）を使う
