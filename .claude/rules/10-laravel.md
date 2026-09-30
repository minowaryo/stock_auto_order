# 10-laravel.md — Laravel固有ルール

## アーキテクチャ方針

### Domain Boundary（ドメイン境界）

> 関連ADR: `meta/adr/ADR-0011-domain-boundary-contract.md`

「Fat Controller禁止」だけでは曖昧で実行可能な基準にならないため、境界を明示的な契約として明文化する。**Domain Boundary（ドメイン境界）** とは Service/Action レイヤーと Policy レイヤーを指す。判断・認可・永続化を行うものはすべてこの境界の内側に置く。

```
Controller → FormRequest → Service / Action  (ビジネスルール・トランザクション)
                         → Policy            (認可)
                         → Eloquent Model    (スキーマ・リレーションのみ)
```

設計目標は「Controllerを正しく書くこと」ではなく、**「Controllerが誤っていてもシステムが正しさを保つこと」**である——想定外の値を送る、任意ステップを省略する、別のクライアントに置き換えられるといったHTTP層であっても、データを破壊したり認可を回避したりできてはならない。

### Controller

Controllerが**行ってよいのは**以下のみ:

- `FormRequest` によるリクエストのバリデーション
- `authorize()` の呼び出し
- `Service` / `Action` の呼び出し（**ちょうど1つ**）
- レスポンスの整形（view / redirect / JSON）

Controllerは**以下を行ってはならない**:

- `DB::` の呼び出し、またはデータベースコンテナの解決（`app('db')`）——トランザクションはServiceレイヤーの責務
- Eloquentの書き込みメソッドを直接呼び出す——`save` / `fill` / `update` / `updateOrCreate` / `firstOrCreate` / `create` / `insert` / `upsert` / `delete` / `forceDelete` / `restore` / `increment` / `decrement` / `attach` / `detach` / `sync` / `associate`
- ロールのインラインチェック（`$user->role === 'admin'`、`$user->isAdmin()` など）——認可は `meta/adr/ADR-0003-auth-strategy.md` に従いPolicyを経由する
- **複数のエンティティ**にまたがる判断を行う（例: 注文の数量と商品の在庫を比較する）——これはクロスエンティティな不変条件であり、Service / Actionの責務

**これは下記の「Modelにビジネスロジックを書かない」と矛盾しない。** 実施（enforcement）はService/Actionレイヤーが担い、Modelはスキーマとリレーションのみを保持する。ここで意図的に「Model層」という語を避けているのは、フレームワークによって意味が異なるためである。

`.claude/hooks/domain-boundary-check.sh` は、`/review` 実行時にこの契約のうち機械的に検出可能な部分を検知する。4つ目のルール——プレーンなPHPで書かれたクロスエンティティな判断には識別可能なトークンが存在しない——はこのスクリプトには見えないため、レビューに委ねる。

### Service / Action
- 1クラス1責務を守る
- `Action` クラスは `execute()` メソッドに処理を集約
- トランザクションはServiceレイヤーで管理

### Model
- `$fillable` を明示する（`$guarded = []` 禁止）
- スコープはModelに定義する
- リレーションは積極的に定義する
- ビジネスロジックをModelに書かない

#### Actor Stamp（`created_by` / `updated_by` / `deleted_by`）

「誰が行った操作か」をレコードに記録する仕組みは**モデル単位のオプトイン**とする。`docs/product/use-cases.md` または `docs/architecture/data-model.md` で監査証跡が求められているモデルにのみ適用し、基底モデルやワイルドカードObserver経由で全モデルに一律適用しない。

- 共通トレイト（`app/Concerns/HasActorStamps.php`）を再利用し、Modelイベントからカラムを埋める。Controller/Serviceで手動セットしない
- マイグレーション規約: カラムごとにnullable FKを1つ、例 `foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()`（コンソール実行・システム起因の書き込みには認証済みactorが存在しないためnullable）
- Model規約: `use HasActorStamps;` のみを記述する。3カラムを `$fillable` に追加しない（トレイト側がセットするため、mass-assignable にしてはいけない）
- **論理削除時の注意**: Eloquentの `runSoftDelete()` は独自クエリで `deleted_at` を書き込み、`save()` を経由しないためsave系のフックは発火しない。`deleting` イベント内で明示的なupdateクエリを使い `deleted_by` をセットし、`restoring` でクリアする
- トレイト自体は、fixture用モデル・テーブルに対する4パス（作成・更新・削除・復元）すべてをUnit Testでカバーする。Feature Testからの間接的なカバレッジに頼らない

### Authorization
- **Policy / Gate を必ず使う**（手動チェック禁止）
- `authorize()` をControllerで明示的に呼ぶ
- ロールチェックはMiddlewareまたはPolicyに集約

### FormRequest
- バリデーションルールはFormRequestに書く
- `authorize()` も適切に実装する

## 命名規則

| 対象 | 規則 | 例 |
|---|---|---|
| Controller | PascalCase + Controller | `UserController` |
| Service | PascalCase + Service | `UserRegistrationService` |
| Action | PascalCase + Action | `RegisterUserAction` |
| FormRequest | PascalCase + Request | `StoreUserRequest` |
| Policy | PascalCase + Policy | `UserPolicy` |
| Event | PascalCase（過去形） | `UserRegistered` |
| Job | PascalCase | `SendWelcomeEmail` |

## 禁止事項

- `DB::statement()` での生SQL（必要な場合はADRを書く）
- `$guarded = []`
- Controllerでのビジネスロジック
- N+1クエリ（`with()` で積極的にEager Loading）
