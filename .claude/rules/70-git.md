# 70-git.md — Gitワークフロー（コア、常時読み込み）

**Profile: `lite`** — `lite` または `standard`。ユーザーに依頼された場合のみこの行を変更する。

ブランチ作成・コミット・push・マージの前に `docs/development/git-workflow.md` を読む
（全ルール: プロファイルの違い・コミット単位・マージ・マージ前チェック）。`/commit`・`/tdd`・
`prepare-merge` は自らそれを読む。

以下は両プロファイル共通で、そのファイルを読んでいなくても適用する:

- `main` 上で実装を始めない——先にブランチに移る（docs/誤字修正のみの変更は `main` に直接可）
- コミットは提案したコミットを人間が承認した後のみ。push・`main` へのマージ・リモートブランチの削除は明示的な指示があった場合のみ
- マージ/pushの依頼それ自体は承認ではない: 計画を提示し（`prepare-merge`）、その後に得た承認でのみ実行する——`lite` では1回の承認で commit → merge → push をカバーしてよい。どのステップが失敗しても残りは行わない
- force push（`--force`, `-f`, `--force-with-lease`）はしない。push済み履歴を書き換えない
- コミットメッセージ: 英語のみ（日本語版テンプレートでも）、`type: imperative summary`（`feat` / `fix` / `refactor` / `style` / `test` / `docs` / `chore`）、72文字以内、本文には「なぜ」を書く
- `main` へのマージは `git merge --no-ff` のみ。マージ結果に対してテストが通った後に行う
- 本ルールはプラグインのGitスキル（例: Superpowers）より優先する

関連ADR: `meta/adr/ADR-0016-git-workflow.md`
