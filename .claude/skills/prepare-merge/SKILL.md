---
name: prepare-merge
description: 完了したフィーチャーブランチの main へのマージを準備し、明示的な指示があった場合のみ実行する——マージ前チェックを実行し、その区分に応じた /review の要否を守らせ、マージコミットのメッセージを起草し、マージ結果に対してテストを実行したうえで --no-ff でマージする。ブランチの作業が完了したとき、またはユーザーがブランチのマージ・完了・取り込みを依頼したときに使う（例: 「マージして」「ブランチを取り込む」「作業完了したのでmainに入れて」「merge this」「finish this branch」）。このプロジェクトでは Superpowers の finishing-a-development-branch を置き換える。Status: Trial（`meta/adr/ADR-0016-git-workflow.md` 参照）。
---

# マージ準備

最初に `docs/development/git-workflow.md` を読む——§0 プロファイル、§5 マージ、§6 マージ前チェック に
ルールがある。このファイルは手順のみを定める。有効なプロファイルは `.claude/rules/70-git.md` の
`Profile:` 行である。

## 手順

1. **前提条件** — フィーチャーブランチ上（`main` ではない）にいて、作業ツリーがクリーンであること。
   未コミットの変更がある場合: `lite` でユーザーが commit → merge（→ push）の一括実行を依頼したときは、
   `/commit` のステップ1〜5を実行し、その表をステップ5の計画に含める。それ以外は止まり、
   `/commit` を提案する。続いて作成者の事前確認（`docs/development/review-guidelines.md` の
   「レビュー前確認（作成者）」）を実行し、満たしていない項目があればステップ5の計画に列挙する。
2. **マージ前チェック** — 次を実行する:

   ```bash
   bash "${CLAUDE_PLUGIN_ROOT:-.claude}/hooks/review-score.sh"
   ```

   `MERGE_CHECK=` 行とスコアを読む。スクリプトが0以外で終了した場合、または `MERGE_CHECK=` 行を
   出力しなかった場合は、区分を `required` として扱い、エラーを提示する。ベースブランチが
   見つからないと報告された場合は、`REVIEW_SCORE_BASE_BRANCH` を設定するようユーザーに伝える。
3. **区分によるゲート**（§6）— `/review` を「実行済み」とみなすのは、このセッションでブランチの
   最後のコミットより後に実行された場合か、ユーザーが実行済みと確認した場合だけ——推測で判断しない。
   - `standard`:
     - `light` → 続行する
     - `recommended` → `/review` を提案する。ユーザーがスキップした場合は続行する（`Review: skipped`）
     - `required` → `/review` が未実行なら止まり、なぜ必要か（変更が大きい、または注意が必要な
       領域に触れている）を平易な言葉で伝えて、人間に実行を依頼する
       （`/review` は人間が呼び出すもの——ADR-0009）
   - `lite`: 区分やスコアには触れずに続行する——ただし機密パスに該当した場合、**または
     スクリプトが失敗した場合**（ステップ2）は、平易な言葉で1回だけ尋ね（例：「DBマイグレーションに
     触れているので、先に `/review` を実行しますか？」）、
     その回答に従う（断られた場合は `Review: skipped`）
4. **マージメッセージを起草する** — §5 の形式に従う。gitのデフォルト件名
   （`Merge branch '<branch>'`）、1〜2文の「なぜ」（UC-IDがあれば含める。`light` の場合は任意）、
   トレーラー `Merge-Check:`（区分・スコア・機密パス）、`Review:`（`normal` / `enhanced` / `skipped`）、
   `Tests:`（ステップ6で埋める）。区分とスコアは `Merge-Check:` トレーラーにだけ入れ、ユーザーには
   見せない（`docs/development/git-workflow.md` §6「ユーザーへの伝え方」）。
5. **計画を提示して待つ** — メッセージと、以下の正確なコマンドを提示する（`<base>` はプロジェクトの
   ベースブランチで、`REVIEW_SCORE_BASE_BRANCH` で指定しない限り `main`）。
   マージの指示として扱うのは、この計画を提示した**後に**得た承認のみである
   （「はい」「OK」「進めて」だけで十分）。このスキルを起動したきっかけの依頼は指示に含めない。
   `lite` の一括実行（§4 権限）では、1つの計画に作成するコミットと——ユーザーが依頼した場合のみ——
   最後の `git push origin <base>` も含め、変更ファイルとテストコマンドを示す。
   その1回の承認で一連の操作全体をカバーする。

   ```bash
   git checkout <base>
   git fetch && git merge --ff-only origin/<base>   # skip if there is no remote
   git merge --no-ff --no-commit <branch>
   <test command>                                   # e.g. php artisan test
   git commit -F - <<'EOF'                          # only if the tests passed
   <merge message>
   EOF
   git branch -d <branch>
   ```

6. **指示を受けてマージする** — 上記の手順を実行する。以下の場合は自分で解消しようとせず、止まって
   報告する: `--ff-only` での更新が失敗した場合（ローカルの `<base>` がリモートと分岐している——
   rebase付きの `git pull` は過去のマージコミットを平坦化してしまうため行わない）、テストが失敗した
   場合（`git merge --abort` し、ブランチ側で修正してからやり直す）、コンフリクトが発生した場合
   （内容を提示する。ユーザー抜きで解消しない）。
   `lite` の一括実行では、どの時点で失敗しても残りの操作は行わない——承認は失敗したステップの先には
   及ばない。
7. **マージ後** — ローカルブランチは計画どおり削除される。`<base>` のpushとリモートブランチの削除は、
   明示的な指示があった場合のみ行う（§4 権限）。`lite` の一括実行の承認は、計画に含めたpushについては
   その指示とみなす。
