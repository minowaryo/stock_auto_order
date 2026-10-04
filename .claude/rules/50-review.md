# 50-review.md — レビュー観点（コア）

- レビューは `/review` でも単なる「レビューして」でも、まず全文 `docs/development/review-guidelines.md`（review-score によるレビュー強度・作成者の事前確認・レビュアー観点）を読む
- `/review` を省略する場合も含め、マージ前には必ず同ファイルの作成者の事前確認を適用する（`prepare-merge` が実行する）
- マージに `/review` が必要かはマージ前チェック（`docs/development/git-workflow.md` §6）で判定する。`/review` 自体は常に人間が起動する（`meta/adr/ADR-0009-review-escalation-mechanism.md`）
- スコア算出スクリプトは `REVIEW_SCORE_BASE_BRANCH` / `DOMAIN_BOUNDARY_BASE_BRANCH`（デフォルト `main`）を使う。プロジェクトのベースが `master` / `develop` の場合は設定する
