# 06-branch-coordination.md — 並行ブランチ・採番の整合性ルール

複数セッション（本人・別AIセッション）が同時期に別々のfeatureブランチをmainから切って作業するため、
ADR番号・CR番号・PLAN.mdの追記が**互いを見ずに独立採番**され、マージ時に衝突しやすい。
実例: `feat/chg0017-value-cyclical-judgment` と `feat/chg0018-undervalued-quality-buy-signal` が
それぞれ独立に `ADR-0015` を採番し、両方未マージのままだったため衝突が発覚した（2026-09-21）。

## 採番ルール

- 新しいADR/CR番号を割り当てる前に、**mainだけでなく未マージの全ローカルブランチ**を確認する:
  ```bash
  git branch -a
  for b in $(git for-each-ref --format='%(refname:short)' refs/heads/); do
    git ls-tree -r "$b" --name-only -- docs/adr/ | tail -3
  done
  ```
- それでも並行作業由来の衝突は起こり得る（同時に分岐したブランチは互いの採番を知りようがない）。
  衝突が判明した時点で採番し直す。**優先して採番し直す側 = Gate承認が浅い方**
  （Proposed止まり・本文参照箇所が少ない方）。Gate1〜4まで承認済みで大量に本文参照されている側は
  そのままにする（変更コストが非対称なため）。

## ブランチ運用

- featureブランチは**短命に保つ**。長期間mainから分岐したままだと、番号衝突・PLAN.md追記の
  ズレが蓄積し、マージ時の手作業が増える。
- 定期的に未マージブランチを棚卸しする（「マージ忘れ」防止）:
  ```bash
  for b in $(git for-each-ref --format='%(refname:short)' refs/heads/ | grep -v '^main$'); do
    echo "$b: $(git log main.."$b" --oneline | wc -l) commits ahead"
  done
  ```
- あるブランチが別のfeatureブランチの上に乗って分岐した場合（例: `feat/chg0017-...` が
  `feat/f013-...` の上に乗った）、祖先ブランチを個別にmainへマージする必要はない
  （子ブランチをマージすれば内容は自動的に含まれる）。`git merge-base --is-ancestor <親> <子>` で確認できる。

## 追記型ドキュメントのマージ

`PLAN.md` / `docs/product/use-cases.md`（承認記録テーブル）/ `docs/rcid/traceability-matrix.md` は
「新しいエントリを先頭・末尾に追記する」運用のため、複数ブランチが同じ場所に追記すると
git の自動マージが誤って片方を消す・順序を壊すことがある。

- マージでconflictが出たら中身を読み、**両ブランチの追記を両方残す**（union）。「後勝ち」で片方の
  エントリを消さない。
- conflictが出ずに自動マージされた場合でも、マージ後に該当ファイルを目視で確認する
  （追記型ファイルは自動マージが「正しく統合できた」ように見えて実は片方の意図を壊すことがある）。

## マージ順序の目安

複数の未マージブランチをまとめて統合する場合、衝突解決が楽な順に処理する:

1. 変更範囲が小さく他ブランチと重複ファイルが少ないもの
2. ドキュメントのみ・初期段階（Proposed）のCR
3. 大きい変更・現在進行中の作業中ブランチ（他の内容を祖先に持つことが多いため最後）

## マージ後のブランチの扱い

- **ローカルブランチ**: mainへのマージが確認できたら削除してよい（`git branch -d <branch>`。
  `--no-ff`マージ済みなら安全に`-d`で消せる）。ただし削除は**pushとは独立に判断してよいローカル操作**
  であり、破壊的操作（Git Safety Protocol）には当たらないため、都度確認を取らずに削除して構わない。
- **リモートブランチ（`origin/`）**: 削除は本人に確認してから行う（`git push origin --delete <branch>`は
  共有リソースへの変更であり、CLAUDE.md「プッシュに関するルール」に準じて明示的な指示があるまで実行しない）。
- 削除前に必ず`git merge-base --is-ancestor <branch> main`で「mainに完全に取り込まれている」ことを確認する。
  他セッションが同じブランチ名で作業を継続している可能性がある場合（本ファイル冒頭の実例のように
  作業ディレクトリを共有している場合）は、削除前に本人に確認する。
- **`git branch -d`はカレントブランチ（HEAD）基準でしか未マージ判定をしない**ため、`main`以外の
  ブランチにcheckoutした状態で実行すると、実際はmainにマージ済みでも「not fully merged」と誤警告される
  （実例: `feat/chg0017-d7-valuation-badge`等）。**削除前の正式な判定は必ず`git merge-base --is-ancestor
  <branch> origin/main`（ローカルmainではなくpush先のリモートを基準にする）で行い**、`-d`の警告はその
  判定より優先しない。`-d`が誤警告するだけで実際は確認済みの場合、`-D`を使ってよい（強制上書きではなく、
  事前検証済みの安全な削除）。

## 複数ワークツリーが同一リポジトリを共有する場合の注意

`git worktree`で複数のディレクトリから同一リポジトリを触る運用（本プロジェクトでは`main`専用ワークツリーを
常設し、featureブランチはそれぞれ別ディレクトリに`git worktree add`する方式）では、以下に注意する。

- **作業前に`git worktree list`で全ワークツリーを把握する**。ブランチ削除・`git worktree remove`・
  `main`ブランチへの参照更新（`git branch -f main <commit>`等）を行う前に、対象ブランチが**他の
  ワークツリーでcheckoutされていないか**を確認する。
- **未コミットの変更が残っているワークツリーには一切触れない**（`git -C <worktree-path> status --short`
  で確認）。空でなければ、他セッションの作業中とみなし、`git worktree remove`はもちろん、
  そのブランチ参照を動かす操作（force-updateなど）もしない。作業ツリーが空（clean）であることを
  確認できた場合のみ、マージ済みブランチの`git worktree remove`を都度確認なしに実行してよい
  （ローカルブランチ削除と同じ扱い）。
- **`main`専用ワークツリーがpushを忘れて放置されると、ローカルmainがorigin/mainより
  何十コミットも先行した状態に気づかず溜まることがある**（実例: 2026-09-21、ローカルmainが
  origin/mainより25コミット先行——CHG-0017/CHG-0018/F-013の各マージコミットが一度も
  pushされていなかった）。定期的に`git -C <mainのワークツリーパス> log origin/main..main --oneline
  | wc -l`で確認する。
- **`main`のワークツリーに未コミットの変更が残っていて直接pushできない場合**は、そのワークツリーを
  一切変更せずに済ませる：別ブランチ（例: `git checkout -b tmp-sync <mainのコミットハッシュ>`）を
  他のワークツリー上に作り、そこにドキュメント修正等を積んでから
  `git push origin <ローカルブランチ名>:main`でリモートの`main`参照だけを直接更新する
  （ローカルの`refs/heads/main`自体は動かさないため、他ワークツリーのcheckout状態に影響しない）。
  ローカル`main`参照の追従（`git branch -f main ...`等）は、該当ワークツリーがclean化されてから
  改めて行う。

## PLAN.md等のステータス記述はコミット履歴で裏取りする

複数ワークツリー・複数セッションが並行してmainへマージし続けると、**あるセッションがマージした直後に
PLAN.mdのナラティブ（Status節）を更新しないまま次の作業に移り、別セッションのPLAN.md記述が古いまま
放置される**ことがある（実例: 2026-09-21、F-013のStatusが「実装着手待ち」のままだったが、実際は
Cycle 1〜2〔当初計画のCycle 3の並び順追加も含む〕がGreen・レビュー済みでmainに合流済みだった）。

- PLAN.mdの「未着手」「着手待ち」等の記述を鵜呑みにせず、次のいずれかで実態を確認してから
  次のアクションを決める: 該当する対象コード・テストファイルの存在確認（`git grep`/`git ls-tree`）、
  対象コミットの有無確認（`git log --oneline -- <path>`）、または`git merge-base --is-ancestor`
  でブランチの合流状況を確認
- 記述と実態がズレていた場合は、実装を進める前にPLAN.mdの該当Status/Files touchedを実態に合わせて
  修正する（`.claude/rules/60-docs.md`のドキュメント先行原則と矛盾しない範囲で、まず記録を正しくしてから
  次に進む）
