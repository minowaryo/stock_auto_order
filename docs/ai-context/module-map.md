# module-map.md — モジュール・ディレクトリ担当一覧

## Backend (Laravel)

| パス | 役割 | 注意 |
|---|---|---|
| `app/Http/Controllers/` | HTTPリクエスト受付・レスポンス返却 | Fat Controller禁止 |
| `app/Http/Requests/` | バリデーションルール | FormRequestを必ず使う |
| `app/Http/Middleware/` | 認証・認可・ロギング | グローバル適用は慎重に |
| `app/Services/` | ビジネスロジック | 1クラス1責務 |
| `app/Actions/` | 単一操作のアクション | `execute()` メソッドに集約 |
| `app/Models/` | Eloquentモデル・リレーション | ビジネスロジックを書かない |
| `app/Policies/` | 認可ルール | 必ずGate経由で呼ぶ |
| `app/Events/` | ドメインイベント | 過去形の名前 |
| `app/Listeners/` | イベントハンドラ | 重い処理はQueueに |
| `app/Jobs/` | 非同期ジョブ | Horizon経由で実行 |

## Frontend（`docs/adr/ADR-0005-frontend-stack.md` で選定したスタックに応じて記載する）

> 以下は選定結果が **Vue 3 + Inertia.js + Pinia**（デフォルト推奨）の場合の例。
> Blade / Livewire / React 等を選定した場合は、そのスタックのディレクトリ構成に置き換えること。

| パス | 役割 | 注意 |
|---|---|---|
| `resources/js/Pages/` | Inertia ページコンポーネント（ルート単位） | Controller の return で指定される |
| `resources/js/Components/` | 汎用・共通コンポーネント | 単一責務・再利用可能に保つ |
| `resources/js/Composables/` | ロジック分離用 Composable（`use~` 命名） | コンポーネントのロジックをここに集約 |
| `resources/js/stores/` | Pinia Store（`useXxxStore` 命名） | ローカル状態は入れない |
| `resources/js/app.js` | エントリポイント | Inertia の初期化処理 |

## Database

| パス | 役割 |
|---|---|
| `database/migrations/` | スキーマ変更履歴（変更禁止） |
| `database/factories/` | テスト用データ生成 |
| `database/seeders/` | 初期データ投入 |

## Tests

| パス | 役割 |
|---|---|
| `tests/Feature/` | 統合テスト（最優先） |
| `tests/Unit/` | 単体テスト（ビジネスロジック） |

## Docs

| パス | 役割 | 更新者 |
|---|---|---|
| `docs/ai-context/` | AI向け要約（短く正確に） | 開発者 |
| `docs/product/` | 要件・ユースケース | ビジネス側 |
| `docs/architecture/` | システム設計 | 開発者 |
| `docs/adr/` | 意思決定記録 | 開発者 |
| `docs/development/` | 開発プロセス | 開発者 |
| `docs/security/` | セキュリティポリシー | セキュリティ担当 |

## 触ってはいけない領域

`docs/ai-context/do-not-touch.md` を参照。
