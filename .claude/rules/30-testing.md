# 30-testing.md — テスト方針

## テスト優先順位

1. **Feature Test（最優先）**: HTTPリクエスト〜レスポンスの統合テスト
2. **Unit Test**: 複雑なビジネスロジック・計算ロジック
3. **E2E Test（Playwright）**: クリティカルなユーザーフロー（詳細は `docs/development/e2e-testing.md` を参照。通常のTDDサイクルでは読まなくてよい）

## テスト作成前に読むファイル

- `docs/product/use-cases.md` — テストケース名・網羅範囲の導出元（UCが正常系/異常系/権限の基準）
- `docs/architecture/data-model.md` — DBアサーション・Factoryの型・制約の確認
- `docs/product/mockups/` — E2Eテストの画面構造・操作フローの確認（存在する場合）

## 基本ルール

- 変更には必ずFeature Testを追加する
- バグ修正時は再発防止テストを先に書く（TDD）
- テストはDBをモックしない（実DBを使う）
- Factoryを活用してテストデータを生成する
- テストケース名は `use-cases.md` のUCタイトル・フローを基に命名する

---

## TDDワークフロー（Claude Code / Codex 共通）

> 関連ADR: `meta/adr/ADR-0007-tdd-enforcement-probity.md`

Claude Code / Codex は「実装を先に書いてからテストを後付けする」挙動を取りやすい。
バグ修正に限らず、**通常の機能開発でもRed → Green → Refactorのサイクルを明示的に指示する**こと。

### サイクル

1. **Red**: 失敗するテストのみを書かせる。同じターンで実装コードを書かせない
2. **Green**: そのテストを通す最小限の実装のみを書かせる（テスト要件を超える実装をさせない）
3. **Refactor**: テストがGreenのままリファクタさせる

### プロンプト例

```
# Red
「〇〇の失敗するFeature Testを書いてください。実装コードはまだ書かないでください。」

# Green
「このテストを通す最小限の実装をしてください。テストが要求する以上のことはしないでください。」

# Refactor
「テストをGreenに保ったまま、実装を整理してください。」
```

### Gate 4 — テストケース承認

- RedフェーズとGreenフェーズを1リクエストにまとめない（AIがテストを実装に迎合させやすくなるため）
- Redフェーズでテストを書かせた後、**Greenフェーズ（実装）に進む前に必ず人間がテスト内容をレビュー・承認する**（`.claude/rules/00-global.md` の Gate 4）
  - 確認観点: 意図した仕様どおりにテストが失敗しているか、テストケースが `use-cases.md` の正常系/異常系/権限を網羅しているか
  - `/tdd` コマンドはこの承認を得るまでGreenフェーズに自動で進まない
- 承認後、テストと `docs/product/` は `tdd-implementer` に対してロックされる——フックがその書き込みをブロックし、`/tdd` がGreen後に承認スナップショットと比較する（`docs/development/tdd-guard.md`、ADR-0017）。正当に変更するには、Redからやり直して再度承認する

### Greenフェーズ完了後のスキル実行

「テストが通った」＝「機能が動く」とは限らない（テストのモック漏れ・カバー不足の可能性があるため）。Greenフェーズ完了時は以下を実行してから次のフェーズに進む。

1. **`run` スキル**の実行をユーザーに推奨する。実際にアプリを起動して機能が期待通りに動作するか確認するためのものである——Claude Code v2.1.215時点で、バンドルされたスキルは人間が明示的に呼び出した場合にのみ実行されるため、自動的に実行するのではなく推奨する旨を伝える
2. 対象がUCのクリティカルフロー（`docs/product/use-cases.md`）かつUI変更を含む場合、**`/generate-e2e-test`** でPlaywright E2Eテストを追加する
3. Refactor完了後、マージ前チェックで求められた場合はマージ前に **`/review`** を実行する（`docs/development/git-workflow.md` §6 マージ前チェック）
   - `/review` 実行時にStep 0として自動計算される review-score の結果（`meta/adr/ADR-0009-review-escalation-mechanism.md` 参照）に従って通常レベル/強化レベルが自動選択される

### テスト実行バジェット——正本

> テストをどれだけの頻度で実行するかの唯一の正本（`meta/adr/ADR-0021`）。`.claude/commands/tdd.md`、TDDの両サブエージェント、`docs/development/git-workflow.md` §5、`prepare-merge` はここを参照する。

| フェーズ | 実行者 | 実行するもの |
|---|---|---|
| Red | `test-writer` | 自分が作成・編集したテストファイルだけ |
| Gate 4の根拠 | メインセッション | 同じファイルを1回 |
| Green（反復中） | `tdd-implementer` | 対象テストファイルだけ——全件は実行しない |
| Green確認（`/tdd` ステップ4） | メインセッション | 全件を1回——サイクルの正式な実行 |
| Green後（サイクルレビューの修正、Refactor） | メインセッション | 編集中は対象ファイル。変更後に全件を1回——コードが変わった場合だけ |
| マージ | `prepare-merge` | マージ結果。すでにテスト済みのツリーと異なる場合だけ（`git-workflow.md` §5） |

- このセッションで結果が分かっているツリーを再実行しない。再実行は何かが変わった後だけにする。
- AIによる実行は簡潔な出力（`php artisan test --compact …`）を使い、サマリー行と失敗ブロックだけを読む。通ったテストの行を会話に貼らない。
- 実データ相当の準備に数秒かかるテストには `slow` グループを付ける（PHPUnitは `#[Group('slow')]`、Pestは `->group('slow')`）。まず速くする（重い準備はテストごとに1回。MySQLでは `setUp` でDDLを実行しない——`$table->temporary()` を使う）。反復中の広めの確認では `--exclude-group slow` を使ってよいが、正式な実行には必ず含める。
- 各フェーズの報告には、実行したテスト（回数とおおよその時間）を載せる。

### フェーズ報告——正本

`/tdd`・`/commit`・`prepare-merge` は、必ず会話の中で結果報告を出して終える——バグが見つかったかどうかに関係なく、途中で止まった場合（Gate 4の差し戻し、`SPEC_CONFLICT`、試行回数の上限、チェックやサイクルレビューによる停止、マージの失敗）も同じ。ファイルには何も保存しない（`meta/adr/ADR-0021`）。

- `/tdd`: `.claude/commands/tdd.md` のステップ8の完了報告（ADR-0020）。その「チェック」項目に、実行したテスト（回数とおおよその時間）を含める
- `/commit`: ステップ8の表
- `prepare-merge`: ステップ8の表

## テストの質に関するヒューリスティクス（Trial — `meta/adr/ADR-0014-third-party-skill-adoption-trial.md` 参照）

Red → Green → Refactor のサイクル自体に加えて、「通ってはいるが実質何も検証していない」
テストにも注意する:

- 検証したい振る舞いそのものをモックしない——上記の「DBをモックしない」より広い概念。
  例: Serviceの振る舞いを検証したいFeature Testで、そのServiceのメソッド自体をモックしない
- assertionの期待値は実装コードを読んで逆算せず、仕様・use-caseから独立して導出する。
  実装からコピーした値は、その実装が間違っていても検出できない
- Redフェーズを完了とみなす前に、意図した修正を戻してもそのテストが実際に失敗するか
  サニティチェックする（どちらの状態でも通るテストは空虚である）

## 命名規則

```php
// Feature Test: 何をテストするか明確に
test('管理者はユーザー一覧を取得できる', function () { ... });
test('一般ユーザーはユーザー一覧にアクセスできない', function () { ... });
test('未認証ユーザーはログインページにリダイレクトされる', function () { ... });
```

## テスト構造（AAA パターン）

```php
test('example', function () {
    // Arrange: テストデータ・前提条件を準備
    $user = User::factory()->create();

    // Act: テスト対象の処理を実行
    $response = $this->actingAs($user)->get('/dashboard');

    // Assert: 期待する結果を検証
    $response->assertOk();
});
```

## 必ずテストすること

- [ ] 正常系（ハッピーパス）
- [ ] 認証・認可（未認証/権限なし）
- [ ] バリデーションエラー
- [ ] 境界値・エッジケース
- [ ] 削除・更新の副作用

## データモデル追加時のCRUD網羅ルール

新しいデータモデル（migration・Eloquent Model）を追加した場合、そのモデルが **`docs/product/use-cases.md` 上でユーザー操作として定義している作成・編集・削除の各操作**をFeature Testで網羅する（一覧・詳細取得を伴う場合はそれも対象に含める）。

- 対象は「use-cases.md で実際に提供すると定義されている操作」のみ。中間テーブル（親モデル経由でしか操作しない pivot）、参照専用マスタ、ログ・監査系テーブルなど、編集・削除機能が定義されていないモデルに対して、**このルールを理由に不要な編集・削除機能やテストを追加しない**（スコープ外実装の禁止。`.claude/rules/00-global.md` 参照）
- 提供される操作について、モデル単位で最低1本ずつテストケースを用意する（1本の統合テストで一連の流れを検証してもよい）
- 認可（Policy）が絡む操作は、提供される操作それぞれで「権限あり/なし」の両方を確認する
- 網羅状況は `docs/architecture/data-model.md` のモデル定義と `docs/product/use-cases.md` の操作範囲を突き合わせて漏れがないか確認する
- `/review` 実行時にこの網羅ルールを満たしているか必ず確認する（`docs/development/review-guidelines.md` 参照）

## コマンド

```bash
# 全テスト実行
php artisan test

# 特定ファイルのみ
php artisan test tests/Feature/UserTest.php

# AIによる実行: 簡潔な出力（サマリー + 失敗）
php artisan test --compact tests/Feature/UserTest.php

# slowグループ以外すべて（反復中のみ）
php artisan test --compact --exclude-group slow

# カバレッジ確認
php artisan test --coverage
```

> E2E Test（Playwright）の方針・配置規約・実行コマンドは `docs/development/e2e-testing.md` に分離した（`/generate-e2e-test` 実行時のみ参照すればよく、通常のTDDサイクルでは読まない）。
