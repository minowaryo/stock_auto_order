# メガトレンド候補発見（CHG-0031）引き継ぎメモ

> 作成: 2026-10-04。別セッションがこの案件を再開するための要約。詳細の正本は下の「正本の場所」にあり、食い違う場合は正本を優先する。状態はgit履歴とPLAN.mdで裏取りしてから動くこと（`.claude/rules/06-branch-coordination.md`）。

## 1. 目的（何をしたい機能か）

本人が指定するメガトレンド（初期はAI基盤・フィジカルAI/産業ロボット・医療AI）から、**楽天証券のお気に入り・アプリのウォッチリストに無い日米の上場企業**を見つけ、元資料・企業の役割・上場主体・反証を確かめたうえで、本人が選んだ銘柄だけをウォッチリスト（UC-012）へ渡す。

- 売買推奨・投資スコア・順位付けはしない。テーマ自体の将来予測もしない。
- 紹介記事や動画は「入口」。一次資料（企業開示・NEDO・FDA等）に戻って確認したものだけを確認済みにする。
- 本人が確認して「監視に追加」を選ぶまで、銘柄マスタ・ウォッチリスト・売買シグナルへは自動登録しない。

## 2. 現在の状態（2026-10-04時点）

| 段階 | 状態 | 根拠 |
|---|---|---|
| Gate 1（要件 F-016） | 本人承認 2026-10-03 | `requirements.md` F-016、`use-cases.md` 承認記録 |
| Gate 2（UC-016・UC-012受け渡し） | 本人承認 2026-10-04。モックは本人指示で省略 | `use-cases.md` UC-016・UC-012、コミット`f53d097` |
| Gate 3（保存設計） | **本人承認済み（2026-10-04）**。「Ｇａｔｅ３承認でよい」と明示 | ADR-0025、`data-model.md`の`research_*`節。草案作成コミット`93c8f54` |
| 情報源の品質 | 遡及サンプルのみ確認済み。4週新着試行は2026-10-05〜11-01、最終再精査は2026-11-02以降 | `source-pilot-log.md`、PLAN.md |
| Gate 4・実装 | 本人が2026-10-04に当初34件と追加3件のRed・失敗結果を承認。Greenの37件とUC-012回帰48件が通過。未コミット・未マージ | PLAN.md、`tests/Feature/UC016*Test.php` |

Gitの反映状況は変動するため、再開時に `git log` と `git status` で main・origin/main・作業ブランチを確認する。

## 3. 決まっている方針

- **初期入力は手動**: 本人が公開URL・企業名・自分の要約を記録する。URL保存時の本文取得・自動要約・企業名抽出はしない。サーバーから外部URLへアクセスしない。
- **情報源固有の自動取得は対象外**: 4週試行と再精査の結果、取得条件を確認できた源だけを、別のGate 2差分として後から追加する。今回の共通処理だけを作っても「自動発見機能の完成」とは扱わない。
- **転載は1件**: 同じ元発表の転載・紹介は独立した裏付けとして数えない。集計は「元発表の件数」と「確認済み上場企業の数（複数上場でも1社）」を分ける。
- **ウォッチリストへの渡し方**: 未登録なら★ONで新規登録（登録経路「調査候補」、楽天CSVの実績やフォルダ名は作らない）。既存行なら★・フォルダ・メモを上書きせず関連付けるだけ。保有済みなら行を作らない。二重送信でも重複させない。
- **画面**: 「新規投資候補」内に「ウォッチリスト／調査候補」の切替、調査候補は`/candidate-research`。グローバルタブは増やさない。

## 4. Gate 3承認内容の要点（ADR-0025）

- 調査記録は既存と分けた専用8テーブル: `research_events`（元発表）、`research_discovery_links`（発見経路）、`research_entities`（法人）、`research_entity_listings`（上場）、`research_candidates`（元発表×法人）、`research_claims`（主張と根拠）、`research_candidate_revisions`（版）、`research_watchlist_handoffs`（受け渡し履歴）。既存テーブルのスキーマ変更なし。
- 未同定・監視未選択の段階では`holdings`を作らない（一括更新・セクター分類の母集団に混ぜないため）。
- **既存実装の落とし穴**: `ImportFavoriteCsvAction`はCSV再取込のたびに`watchlist_items.source`を上書きし、`ShowWatchlistAction`は`in_rakuten_favorites`を`source`から求めている。そのため登録経路は`source`ではなく、`last_seen_in_csv_at`と受け渡し履歴から導く設計にした（表示ロジックの変更を伴う。Gate 4で回帰テストを置く）。
- **直近CSV判定の未解決点**: UC-012本文の`in_rakuten_favorites`は直近CSVへの在籍を意味するが、ADR-0025 D5の`last_seen_in_csv_at IS NOT NULL`は過去の掲載履歴しか示さない。空CSV取込も含む厳密な直近判定は現行スキーマでできない。Gate 4では調査経由のみの行を「楽天側で解除済み」と誤表示しないことと、CSV再取込後も両経路を残すことを確認し、この差は別途仕様・設計を見直す。
- 編集競合は`lock_version`による楽観ロック、削除はアーカイブ（復元可）、元発表の統合は参照で表す。
- アーカイブ・復元・統合・監視登録は`audit`チャンネルへ記録する。Green実装で`config/logging.php`にJSONLの`audit`チャンネルを追加した。

## 5. 未決事項

Gate 3とGate 4（当初34件と追加3件）は2026-10-04に本人承認済み。GreenとUC-012回帰の計85件が隔離DBで通過した。コンテナへChromiumを一時導入してPlaywrightで未同定候補の記録と照合未実施の表示を確認した。監視登録成功はFeatureテストのみで、ブラウザE2Eは未実施。コミット・統合も未完了。

前セッションでは、CHG-0033を優先する案も提案していたが、今回の本人決定はCHG-0031のRed着手。4週試行の結果で情報源固有の取得条件が変わる可能性は引き続き残る。

## 6. 今後の作業（実装準備と試行を並行）

1. `feat/chg0031-research-candidates`の37件のRed→Gate 4承認→Greenと、未同定候補の記録・照合未実施を示すPlaywright E2E 1件は完了。監視登録成功はFeatureテストで確認済み。静的検査・レビュー後にコミット・統合する。
2. 並行して4週試行の週次記録（10/05〜11、10/12〜18、10/19〜25、10/26〜11/01）を`source-pilot-log.md`へ記入する。源ごとに元URL・発表日・照合結果・所要時間・確認できなかった理由を残す。**本人の手作業で、自動実行は設定していない。**
3. 2026-11-02以降: 源ごとに一次資料との一致／不一致／確認不能、訂正、誤同定、転載重複、未登録企業数、偏り、確認時間を集計し、継続／補助参照／入替／保留を決める。F-016の各要件を維持／修正／保留で見直し、変更があれば該当Gateを再レビューする。記録が足りなければ不足を明記し、期間の経過だけで検証済みにしない。
4. 再精査で採用した源の自動取得は、別のGate 2差分 → Gate 3（ADR-0025 D8で後回しにした列）として追加する。

## 7. 正本の場所

| 内容 | ファイル |
|---|---|
| 要件 | `docs/product/requirements.md`（F-016） |
| UC | `docs/product/use-cases.md`（UC-016、UC-012の調査候補経由の記述、承認記録） |
| 発見経路の判断 | `docs/adr/ADR-0022-megatrend-source-radar.md` |
| 保存設計（Gate 3承認済み） | `docs/adr/ADR-0025-research-candidate-storage.md`、`docs/architecture/data-model.md`（`research_*`節） |
| 情報源の台帳・試行 | `docs/product/megatrend-source-selection.md`、`docs/investment-research/megatrend/source-pilot-log.md`と事例カード |
| Gate 1再精査メモ | `docs/product/megatrend-discovery-gate1-requirements-draft.md` |
| 調査カードの書式 | `docs/product/megatrend-research-card-template.md` |
| 進捗台帳 | `PLAN.md`（CHG-0031のエントリ）、`docs/rcid/traceability-matrix.md` |

> `docs/product/megatrend-investment-thesis-2026-2028.md`と個別調査カードは本人の実際の売買相談用で、アプリ開発では読まない（`CLAUDE.md`）。CHG-0031の要件検討では試行ログの集計だけを参照する。
