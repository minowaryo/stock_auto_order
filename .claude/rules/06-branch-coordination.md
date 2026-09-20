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
