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

### 本プロジェクト固有のドメイン層

| パス | 役割 | 注意 |
|---|---|---|
| `app/Services/Import/` | 楽天証券CSV（JP株/US株/投資信託）のパース | Shift-JISエンコード・カンマ区切りクォート付き数値に対応 |
| `app/Services/Analysis/` | テクニカル指標（RSI/MACD/BB/移動平均）・ファンダメンタルズ指標（PER/PBR/ROE等）の計算、利確シグナル判定（`SignalDeterminationService`）・買い増しシグナル判定（`BuySignalDeterminationService`）・ファンダメンタルズ健全性評価（`FundamentalHealthEvaluator`） | 閾値・パラメータの持たせ方は `docs/architecture/data-model.md`（Gate 3）で確定。売り側と買い側は別クラスに分離（ADR-0007） |
| `app/Services/MarketData/` | J-Quants API・Yahoo Finance相当の外部データ取得クライアント（個別銘柄の株価・指標に加え、日経平均・S&P500・米国10年債利回り・VIX指数・USD/JPY為替レート等の市場全体指標も取得する） | APIキー等は `docs/ai-context/do-not-touch.md` の外部連携セクション参照 |

## Frontend（選定結果: **Livewire**。`docs/adr/ADR-0001-frontend-stack-selection.md` 参照）

| パス | 役割 | 注意 |
|---|---|---|
| `app/Livewire/` | Livewireコンポーネントクラス（PHP） | ビジネスロジックを書かず `app/Services/` に委譲する |
| `resources/views/livewire/` | Livewireコンポーネントに対応するBladeビュー | kebab-case命名 |
| `resources/views/components/` | 汎用・共通のBladeコンポーネント | Livewireに依存しない表示部品 |

詳細な実装ルールは `.claude/rules/15-frontend.md` を参照。

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
