# 20-mysql.md — MySQL固有ルール

## Migration方針

### 基本ルール
- **後方互換を意識する**（本番DBに影響するため）
- カラム削除・リネームは段階的に行う（まず非推奨化）
- NOT NULL追加はデフォルト値を設定してから行う
- マイグレーションファイルは一度実行したら編集しない
- 文字コードは必ず `utf8mb4`、照合順序は `utf8mb4_unicode_ci` を使う

### 安全なマイグレーション順序（例：カラム削除）
```
Step 1: アプリコードからカラム参照を削除
Step 2: デプロイ・確認
Step 3: カラム削除マイグレーション実行
```

### 危険な操作（ADR必須）
- `DROP TABLE`
- `DROP COLUMN`
- カラム型変更（MySQLはカラム型変更で全行ロックが発生する）
- 大量データへのALTER TABLE（オンラインDDLの利用を検討）
- 既存データへの変換処理を伴うマイグレーション

### LaravelのMigrationでの注意
```php
// OK: utf8mb4 / utf8mb4_unicode_ci は config/database.php から適用される（「文字コード」参照）
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->timestamps();
    $table->softDeletes();
});

// NG: timestampsTz() は不要（MySQLではtimestamp型を使う）
```

## Index設計

- 外部キーには必ずインデックスを貼る
- 検索条件に使うカラムにはインデックスを検討する
- 複合インデックスはカーディナリティの高い順・クエリの絞り込み順に並べる
- 全文検索は `FULLTEXT INDEX` を使う（`pg_trgm` は不要）
- 不要なインデックスは削除する（更新パフォーマンスに影響）

## Locking

- 長時間トランザクションを避ける
- `lockForUpdate()` / `sharedLock()` の使用は慎重に
- MySQLはデッドロックが発生しやすいため、テーブルアクセス順序を統一する
- デッドロックリスクがある場合はADRに記載

## クエリ方針

- Eloquent / Query Builder を優先する。生SQLに関するルールはここに一本化する（`10-laravel.md` と `40-security.md` はここを参照する）:
  - 生の式（`DB::select`、`selectRaw`、`whereRaw`、`orderByRaw`、`DB::raw` など）は、パラメータバインディング（`?` または名前付き）を使う場合に限り許可する——入力をSQLに連結・埋め込みしてはならない——また、Builderでは足りなかった理由をコメントで記載する
  - `DB::statement()` / `DB::unprepared()` はADR必須
- `EXPLAIN` で実行計画を確認してからリリース
- N+1クエリは必ず解消する（`with()` でEager Loading）
- `SELECT *` を避ける（必要なカラムのみ取得）

## 型・制約

- `string` カラムには適切な `length` を設定（デフォルト255）
- 金額は `decimal` を使う（`float` / `double` 禁止）
- タイムスタンプは `timestamps()`（MySQLの`timestamp`型）
- 論理削除は `softDeletes()`
- JSON型データは `json()` カラム（MySQL 5.7.8+）

## strict mode

- MySQLのstrict modeを有効にする（`config/database.php` の `strict: true`）
- strict modeが有効な場合、空文字列のNULL挿入やゼロ日付がエラーになるため注意

## 文字コード

- テーブル・カラムは `utf8mb4` を使う（絵文字対応）
- `config/database.php` で `charset: 'utf8mb4'` / `collation: 'utf8mb4_unicode_ci'` を設定
