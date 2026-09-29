# 70-git.md — Gitワークフロー

> 関連ADR: `meta/adr/ADR-0016-git-workflow.md`（根拠・不採用案）
> Gitのルールを記述するのはこのファイルだけである。他のファイルはここを参照する。
> 手順は `/commit`（`.claude/commands/commit.md`）と `prepare-merge` スキルに置く。

## §1 ブランチ

- `main` は常に動く状態を保つ。作業は `main` から切った短命ブランチで行う。`develop` / release ブランチは作らない
- コード・テスト・マイグレーション・設定の変更には必ずブランチを切る。`main` への直接コミットはdocs/誤字修正のみの変更に限る
- 命名: `<type>/<issue-no>-<slug>`（issue番号は任意。`type` は§3と同じ）。例: `feat/123-order-export`, `fix/login-timeout`
- 寿命: 2営業日以内を目安とし、最長5営業日。それを超えそうならUCまたはレイヤー単位で分割する
- ベースブランチはプロジェクトのデフォルトブランチ（`REVIEW_SCORE_BASE_BRANCH` で指定しない限り `main`）
- AIは `main` 上で実装を始めない。ブランチ名を提案し、同意を待つ

## §2 コミット単位

| 基準 | ルール |
|---|---|
| 一文で言える | 変更内容を「〜と〜」を使わずに一文で説明できる |
| 常にGreen | すべてのコミットでテストが通る（`git bisect` / `git revert` を使える状態に保つ） |
| 3種類を分ける | 振る舞いの変更・リファクタ・整形（Pintの出力）は別コミットにする |
| テストはコードと一緒 | テストは、それが検証するロジックと同じコミットに含める |

- `/tdd`: Redだけを単独でコミットしない。Red + Greenで1コミット、Refactorは別コミット
- マイグレーションは単独のコミットにする
- コード変更を説明するdocsは同じコミットに含める。docsのみの変更は単独の `docs:` コミットにする
- コミットの依頼は、`/commit` でも自然文でも、すべて `/commit` の手順に従う

## §3 コミットメッセージ

```
[type]: [summary of change]

[why, if needed]
```

- `type`: `feat` / `fix` / `refactor` / `style`（整形のみ） / `test` / `docs` / `chore`。破壊的変更（例: デプロイの調整が必要なマイグレーション）には `!` を付ける。scopeは使わない
- 件名: 日本語または英語で意図を書く（英語の場合は命令形）。50文字程度（最大72文字。日本語の場合は全角25〜35文字程度）、末尾にピリオド・句点を付けない
- 本文: 72文字で折り返し、「どうやったか」ではなく「なぜか」を書く
- AIが作成したコミットには `Co-Authored-By:` トレーラーを残す

## §4 権限

| 操作 | AI |
|---|---|
| `git add` | 可 |
| `git commit` | 分割案とメッセージを提案し、人間の承認1回でコミットする（`/commit`） |
| `git push` | 明示的な指示があった場合のみ（`.claude/settings.json` で `ask`） |
| force push（`--force`, `-f`, `--force-with-lease`） | 行わない（`deny`）。人間が手動で行うのは可 |
| `main` へのマージ | 準備する（`prepare-merge`）。実行はマージ計画を提示した後に得た承認がある場合のみ |
| push済み履歴の書き換え | 自発的には行わない |

パーミッションルールはガードレールであってセキュリティ境界ではない（例: `git -C . push` はマッチしない）。上記ルールはパーミッション設定の有無にかかわらず適用する。

## §5 マージ

- `git merge --no-ff` でマージする: ブランチのコミットはそのまま残り、1つのマージコミットでまとめられる（`git log --first-parent main` が機能一覧として読め、`git revert -m 1 <merge>` で機能単位に取り消せる）
- テストはマージコミットを作る前に、マージ結果に対して1回実行する
- マージコミットのメッセージ — gitのデフォルト件名、短い「なぜ」、トレーラー:

```
Merge branch 'feat/123-order-export'

Let accounting export the order list as CSV for monthly reconciliation (UC-012).

Merge-Check: required (score 34; database/migrations/2026_09_29_add_export_flag.php)
Review: enhanced
Tests: php artisan test (128 passed)
```

- `Review:` は `normal` / `enhanced` / `skipped`。`skipped` は `light` / `recommended` の場合のみ。`light` の場合、「なぜ」の行は任意

## §6 マージ前チェック

ブランチごとにマージ時に1回だけ実行する（コミットごとには実行しない）。`.claude/hooks/review-score.sh` の `MERGE_CHECK=` 行に基づく。

| 区分 | 条件 | マージ前に行うこと |
|---|---|---|
| `light` | スコア < 10（`REVIEW_SCORE_LIGHT_THRESHOLD`）かつ機密パスなし | テストのみ |
| `recommended` | 10 ≤ スコア < 30 | `/review` を推奨。スキップ可 |
| `required` | スコア ≥ 30（`REVIEW_SCORE_THRESHOLD`）または機密パスあり | `/review` 必須（≥ 30 では強化レベル） |

スコア10 ≈ 変更100〜150行、30 ≈ 350〜450行。閾値はTrial（ADR-0016）。

## §7 並行セッションとworktree

> ADR/CR番号衝突・追記型ドキュメントのマージ・DB/コンテナ共有時の注意（`COMPOSE_PROJECT_NAME` 等）は `.claude/rules/06-branch-coordination.md` を参照。このセクションはworktree運用の基本のみを定める。

2つ以上のAIセッションを同時に動かす場合のみ:

- 1セッションにつき1 worktree + 1ブランチ。`.claude/worktrees/` 配下に置く（`claude --worktree <name>`）
- セッション同士で同じファイルを編集しない（UCまたはレイヤー単位で分担する）。各worktreeにはそれぞれ `composer install` / `npm install` / `.env` が必要
- `main` への統合は1ブランチずつ、それぞれ§5に従って行う
- タスクごとのサブエージェント自動レビューループは行わない。レビューはマージ前の1回の `/review`（§6）のまま

## §8 プラグインスキルに対する優先

本ルールはプラグインスキル（例: Superpowers）より優先する:

- プランのタスクごとの「Commit」ステップは `/commit` の承認を経る。自律的なコミットはしない
- `finishing-a-development-branch` は `prepare-merge` で置き換える
- `using-git-worktrees` は並行セッション（§7）の場合のみ使う
- タスクごとのサブエージェントレビューループは実行しない
