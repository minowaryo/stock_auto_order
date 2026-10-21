# CHG-0031 FDA企業下書き・マージ前レビュー

2026-10-20。UC-016／UC-012、ADR-0033。本人の「レビューして問題がなければマージ」の依頼による強化レビュー。**4指摘を追加Gate 4承認後に修正し、関連314件と画面E2E1件は成功。現在は具体的なcommit/merge計画への承認待ち。** 専用`feat/chg0031-nedo-source`で継続し、mainの作業ツリーは変更していない。

## 対象・方法

- HEAD `9e4bc14`のNEDO一覧取得と、未コミットのFDA企業抽出・JPX/SEC照合・本人取り込み・migration・画面・関連文書。別件を含む`3fbc3fb`全体は取り込んでいない。
- `review`スキルのレビュー強度判定は強化。Domain Boundary検査は対象Controller 0件で指摘0であり、Livewire／Serviceは実読して確認した。
- 独立した3担当が取得/抽出、公式照合/保存、本人操作/画面を分担。指摘を要件・既存ガードと再照合し、以下の4点を残した。既知の企業集約/追加フィルタ未実装は今回の新規不具合に数えない。
- 初回基準線、原情報・本人記録の保持、資料版の追記、取り込みの原子性、二重送信、Policy、固定4フィールドの監査、JPX正式5桁保持/4桁限定照合、失敗時の旧公式版保持は確認した。

## 修正前の指摘（すべてMEDIUM、下記Greenで解消）

### 1. 保存障害のSQLとバインド値を画面へ表示する

[CandidateResearch](../../app/Livewire/Candidate/CandidateResearch.php)の新しいimport/reconfirm/correct/disposition/restoreは`RuntimeException`を捕まえ、そのメッセージをそのまま画面のエラーにする。`QueryException`もこれに含まれるため、SQL・本人の追記等のバインド値・接続の内部情報が表示され得る。APP_DEBUGの設定では防げない。

実DBの履歴INSERT直後に合成QueryExceptionを注入したimport/reconfirmで、全体rollbackは成功するが、固定の機密マーカーがエラーバッグへ入ることを再現した。

修正案: DB障害を版競合・入力エラーと分離し、本人向けの固定メッセージを出す。SQL/バインド値/外部本文を画面や監査ログへ転記しない。既存の手動経路にある別の例外処理課題を解消済みとは扱わない。

### 2. 監視確定と資料訂正の並行実行で旧確認を使える

[ResearchCandidateService::confirmHandoff](../../app/Services/Research/ResearchCandidateService.php)はcandidateをロックするが、追加の[ResearchDraftReviewService::handoffError](../../app/Services/Research/ResearchDraftReviewService.php)は関連draftを通常SELECTで読む。一方、自動訂正はdocument/draftを更新し、candidateの版は進めない。

専用DBの実2接続で、監視確定がdraftを読んだ直後に別接続の公開`research:check-sources`→`research:discover-candidates`で資料訂正をcommitさせた。その後、元の監視登録も旧確認のまま成功し、禁止する結果を再現した。

修正案: 自動更新と整合するdocument→draft→candidateのロック順で、監視確定時まで確認資料を保護する。新しい資料が先に確定したなら旧確認を拒否する。監視を先に直列化できた場合は、待っていた資料更新が後続で再開できることも検証する。単にcandidateだけを追加確認してもこの競合は防げない。

### 3. 未選択市場の欠測まで選択銘柄の監視登録を妨げる

`ResearchDraftReviewService::handoffError`は選択先ではなく法人の全listingsを確認する。JPX正常・SECのUA未設定で取得blockedという状態の複数上場先では、本人が日本株を明示選択しても、未選択の米国側を理由に拒否する。既存のprepare/confirmは選択listing IDを受け取るが、新検証には渡していない。

修正案: その候補に属する選択上場先を検証へ渡し、当該市場のマスタ/発行体/本人確認の条件を評価する。同じfixtureで米国株を選んだ場合は拒否を維持する。別名撤回や資料変更等の共通ガードは維持する。

### 4. 全体120秒の期限が保存・照合内で効かない

[ResearchCandidateAutomationService](../../app/Services/Research/ResearchCandidateAutomationService.php)の期限確認は外側の処理区間だけ。200件transaction内・backfill内では確認せず、期限後も`matchDrafts()`を開始する。[ResearchListingCatalogService](../../app/Services/Research/ResearchListingCatalogService.php)も全保存下書きの照合に期限を適用しない。

実DBの1資料保存/1下書き照合の後に壁時計だけ121秒進めると、続く2件も処理された。件数200の上限を守ってもADR-0033 D5の時間枠を守れるとは限らない。

修正案: 取得・保存・照合で残予算を共有し、安全な資料/下書き単位でpartialにして残りを次回へ渡す。途中カーソルと版保存の原子性を維持し、再開で重複や追加HTTPを生まないことを検証する。

## 追加RedとGate 4承認

`tdd`スキルにより実装修正前の再発防止テストを追加。既承認79件/JPX3件を変更して承認を流用していない。

2026-10-20、本人が「追加10件を承認してGreen修正へ進む」と回答。以下10件のGate 4を承認し、4点のGreen修正を開始した。Redの実行結果は修正前の証跡として保持する。

| ファイル | ケース | 期待する結果 |
|---|---:|---|
| [UC016ResearchAutomationBudgetTest.php](../../tests/Feature/UC016ResearchAutomationBudgetTest.php) | 3 | 一時入力/backfill/照合の期限到達後をpartialで持ち越し、次回重複なしで完了 |
| [UC016ResearchDraftReviewSafetyTest.php](../../tests/Feature/UC016ResearchDraftReviewSafetyTest.php) | 2 | import/reconfirmのDB保存失敗で全体rollback、安全なエラー、SQL/固定マーカー非表示 |
| 同ファイル（選択市場） | 4 | prepare/confirmの日本株選択は許可、SEC確認不能の米国株選択は拒否 |
| [UC016ResearchSourceHandoffConcurrencyTest.php](../../tests/Concurrency/UC016ResearchSourceHandoffConcurrencyTest.php) | 1 | 実2接続で資料更新が先にcommitした場合に旧確認のhandoffを許さない。直列化された逆順の正常動作も許容 |

Feature9件は`testing_chg0031_source_adapters`。競合1件は、未commit fixtureが別接続に見えないRefreshDatabaseを使わず、専用`testing_chg0031_review_concurrency`で実行する。**DB名の完全一致をassertした後だけ`migrate:fresh`を実行**する。通常アプリ/プレビュー/Feature DBでは実行しない。この競合テストは通常のFeature globには含まれないので、Green/マージ前にも明示実行が必要。

## 検証結果

- 追加前のUC-016/UC-012回帰: **304件成功、2,038assertions、33.13秒**。`storage/logs/automation-fda-merge-review-20261019.xml`。
- 追加Feature9件: **7件が指摘した不具合で失敗、回帰2件成功、104assertions、エラー/skip 0、15.64秒**。`storage/logs/automation-merge-review-red-20261020.xml`。日本株の確認拒否も例外を捕捉した期待値のassertion失敗として記録し、テスト環境エラーとは分けた。
- 競合1件: **旧確認のまま監視登録する禁止結果で失敗、15assertions、エラー/skip 0、11.52秒**。`storage/logs/automation-merge-review-concurrency-red-20261020.xml`。
- 合計**10件中8件が意図した失敗、回帰2件成功、119assertions、エラー0**。合成HTTPと架空データのみで、本テストから実外部アクセスはしない。後半の再開/重複防止assertionは先行するRed assertionで停止しており、現時点で成功を確認済みとは扱わない。
- 追加3ファイルのPint成功。実装コードは今回変更していない。
- LOWの承認状態表記: ADR-0033冒頭と保存定義末尾の古い「Gate 4未承認」を、FDA79件/JPX3件の既承認と追加修正テストの未承認を区別する記述へ訂正した。設計判断の変更ではない。

## 次の手順

1. 追加10件のGate 4は2026-10-20本人承認済み。承認された4点のGreen修正・対象検証は完了。
2. [具体的commit/merge計画](chg0031-automation-commit-merge-plan.md)への承認を得て実行する。main側の変更を保持し、統合結果を検証してから`--no-ff`でマージする。
3. migrationは単独のコミットにする。新しいGreen検証を通常アプリ/本人DBへの反映済みとは扱わない。
4. 情報源の品質は引き続き4週試行（10/05〜11/01）を記録し、**11/02以降**に再精査する。今回の解析成功/回帰成功を品質評価の完了には読み替えない。

## Green修正・最終検証（2026-10-20）

| 指摘 | 修正 |
|---|---|
| DB例外表示 | 新規5操作はPDOException/QueryExceptionを先に捕捉し「保存できませんでした。時間をおいて再試行してください。」に固定。既存の入力エラー/版競合/認可は保持し、SQLや値を新しいログへ流さない |
| 資料更新と監視確定 | document ID順→draft ID順→candidateのロック。最新のロック読み取り集合を検証に渡し、古いREPEATABLE READ snapshotの通常SELECTへ戻さない。有限回再試行を維持 |
| 選択市場 | prepare/confirmが選択listingを追加検証へ渡す。当該市場のマスタ/発行体を検証。資料版と別名撤回の共通ガードは保持 |
| 120秒 | カタログ取得/公開と資料保存/照合で壁時計・単調時計の残予算を共有。資料/下書き境界でpartialへ持ち越す。期限切れの正常終了と入力不正/DB障害を区別し、後者は同checkのbatch全体をrollbackする既承認ケースも維持 |

独立3担当が実装を分担し、主セッションが差分を再確認して中央でDB試験を実施。追加Red10件も別担当が独立に実読し、修正が必要なテストの指摘なし。承認時のテスト151ファイルのSHA256をGreen後と比較し、変更0を確認。

| 検証 | 結果 |
|---|---|
| UC-016/UC-012全関連（追加9件含む） | **313件成功、2,205assertions、39.65秒、失敗/エラー/skip 0**。`storage/logs/automation-merge-review-green-related-20261020.xml` |
| 別接続の競合（追加1件） | **1件成功、17assertions、12.95秒、失敗/エラー/skip 0**。`storage/logs/automation-merge-review-concurrency-green-20261020.xml` |
| 画面E2E | **1件成功、3.9秒**。FDA下書き→確認OFF→本人確認→再入力なしの取り込み→監視0。`testing_chg0031_review_e2e_20261020`の架空データで確認、ブラウザ外部要求0 |
| PHP/差分 | Pint36ファイル成功。`git diff --check`成功 |

画面E2Eの最初の試行は一時PHPサーバーの作業ディレクトリがpublicでないため失敗し、起動設定だけを訂正して成功した。アプリコードやテストはそのために変更していない。今回の一時8876/18876サーバーは終了し、既存プレビュー8875/18875と通常アプリには停止操作をしていない。

mainの別案件の文書更新はPLAN/追跡表の統合案へ保持。PLANの300行上限に収めるため、`3414d70`がmain祖先と確認できた完了済みCHG-0045を原文のままアーカイブした。現在のPLANは291行。実際のGitマージ/統合結果試験は計画への承認後。
