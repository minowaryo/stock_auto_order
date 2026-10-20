# CHG-0031 FDA自動下書き・Green検証記録

2026-10-19。UC-016/UC-012、ADR-0033。[追加Red79件](chg0031-automation-fda-red-review.md)を本人が「承認してFDAのGreen実装へ進む」でGate 4承認。**承認済み79件はGreen。JPX実配布の差異は追加3件を別承認しGreen修正、実4,447行の解析も成功。関連304件も成功。**

専用worktree `.claude/worktrees/chg0031-sources` / `feat/chg0031-nedo-source`。main/本人DB/ローカル稼働アプリは変更せず、commit/merge/pushも行っていない。

## 実装した範囲

| 範囲 | 主なファイル/内容 |
|---|---|
| 保存構造 | [新規migration](../../database/migrations/2026_10_19_000000_create_research_automation_tables.php)、新規10モデル、既存ResearchSourceCheck/ResearchDiscoveryLink。既存にnullable/default付き5列追加、FK32本はRESTRICT |
| 取得→下書き | [ResearchCandidateAutomationService](../../app/Services/Research/ResearchCandidateAutomationService.php)、[ResearchSourceCheckService](../../app/Services/Research/ResearchSourceCheckService.php)、新`research:discover-candidates`、`app:on-start`。取得時一時入力→200件/回→版/未確認下書き。共有ロック、初回/新着/後処理、停止・原子保存 |
| 公式照合 | [ResearchListingCatalogService](../../app/Services/Research/ResearchListingCatalogService.php)、[Parser](../../app/Services/Research/ResearchListingCatalogParser.php)。公式固定URL、SEC識別用UA、間隔/サイズ/ZIP/数式/全件公開、厳密名/確認済み別名/失効、鮮度と失敗の警告 |
| 本人確認 | [ResearchDraftReviewService](../../app/Services/Research/ResearchDraftReviewService.php)、CandidateResearch、[新部分画面](../../resources/views/livewire/candidate/research-drafts.blade.php)。版付きLocked確認、既存発表/法人は名前で選択、OFFから本人操作、原資料要点/本人追記の分離、二重送信、訂正/保留/見送り/復元、資料更新後の再確認と監視境界 |
| 設定/依存 | [research_automation](../../config/research_automation.php)、`.env.example`のキー名`RESEARCH_SEC_USER_AGENT`、composer.json/lock。PhpSpreadsheet 5.10.0＋推移依存3件のみ追加、既存依存版変更0 |

自動生成は未確認下書きまで。元発表・主張・上場主体の確認、本人の監視選択を代行しない。FDA決定日を公開日/本人確認日へ代入しない。本人の本文/判断は資料再取得で上書きしない。

## 実行結果

| 検証 | 結果/証跡 |
|---|---|
| UC-016/UC-012関連Feature | **301件成功、1,999assertions、失敗/エラー/skip 0、71.59秒**。承認済み79件を含む。`storage/logs/automation-fda-green-related-20261019.xml` |
| JPX補足後の最終Feature | **304件成功、2,038assertions、失敗/エラー/skip 0、33.90秒**。`storage/logs/automation-fda-jpx-green-final-20261019.xml`。専用DBキューへ保存し外部株価ジョブを実行しない設定 |
| 初回Green | 79件中78成功/1失敗。残る1件は未reloadモデルraw配列とDB読取配列のfixture比較差。DB保存済み同士に補正し、保持assertion/期待値は維持。[補正理由](chg0031-automation-fda-red-review.md#green到達時のfixture補正) |
| 画面E2E | [uc016-research-automation.spec.ts](../../tests/e2e/uc016-research-automation.spec.ts) **1件成功・4.1秒**。ログイン→FDA下書き→確認OFF→本人確認→再入力なし取り込み→ウォッチリスト0。ブラウザ外部要求0 |
| 表示データの最終確認 | 既存発表/法人の選択を名前表示へ整えた後、本人確認29件/412assertionsを再実行し全成功。`storage/logs/automation-fda-green-ui-final-20261019.xml` |
| フロントビルド | `npm run build`成功。既存の任意fontaine未導入の通知あり、ビルドエラーではない |
| 表示 | 幅1440/390で新しい確認画面を表示。document幅は各viewportと一致。画像`/tmp/chg0031-automation-ui-{1440,390}.png`。狭幅の既存ヘッダ折返しは本件で未修正 |
| Migration | 空の専用DBで本migration1本だけrollback→再適用、10テーブルの削除/復元と既存取得項目数保持を確認。本人DB/稼働DBには実行していない |
| PHP/style | 変更PHP33ファイルの構文はロード可能、Pint33ファイル成功。テスト既存157ファイルの比較で差分は上記fixture2行のみ。新E2E/JPX追加Redは別ファイル |
| 参照/差分 | `git diff --check`と文書ローカル参照を確認。spec-lintは既存21 findings/0 warningsを維持、新規指摘0 |

Feature用DBは`testing_chg0031_source_adapters`、画面は別の`testing_chg0031_automation_e2e`に架空ユーザー/下書きを作成。E2Eは専用PHPサーバーとlocalhost限定の一時転送で実行した。Docker DesktopのコンテナIPへ直接接続できない初回は接続経路のエラーで、Docker経由へ切替後に成功。当時の一時転送は停止。後続の[本人指示によるRUN](chg0031-run-verification.md)で別の隔離プレビューを起動し、起動継続中。

## 実資料検証で残ったJPXの問題

既取得の公式Xlsx（266,304bytes、SHA256取得時と一致、基準日2026-09-30）を新パーサへ渡すと、4,447行中の正式5桁コード7行により一覧全体を拒否した。合成26件の成功だけで実資料対応と判断できなかった点を明記する。

[JPX公式の株式銘柄コード説明](https://www.jpx.co.jp/glossary/ma/429.html)は4桁と予備1桁の正式表示を区別している。5桁を4桁へ丸めず保存し、現アプリの自動照合は4桁に限定する補足案と[追加Red3件](chg0031-automation-jpx-code-red-review.md)を作成。Redでは3件全失敗・エラー0を確認し、その後本人が補足と3件を別途承認。正式5桁を原形保存・自動照合は4桁だけにする修正後、同じ実Xlsx4,447行の解析に成功した。[経緯と検証](chg0031-automation-jpx-code-red-review.md)。

## 残る作業と制限

- JPX補足/追加3件は本人承認済みで修正・実ファイル解析と関連304件成功を確認。フルスイート全体のGreenとは扱わない。
- SECは`RESEARCH_SEC_USER_AGENT`未設定なら外部アクセスせずblocked。実連絡先はコード/ログへ保存せずローカル設定で扱う。本セッションでは実SECへの新規アクセスを行っていない。
- 新画面は資料×法人の仮の候補数と状態別一覧。公式発行体による企業集約、テーマ/市場/登録状況の追加絞り込みは未実装で、次のUI Redサイクルへ残す。静的3画面の全機能実装完了とはしない。
- NEDO結果本文/複数法人抽出、H02題名抽出は後続の別サイクル。既存NEDO一覧Greenは保持。YouTubeは条件設計が残る。
- `composer audit`は既存5パッケージに6件の指摘（うちhigh1件）。追加4パッケージの指摘は0、対象の既存版はHEADから変更0。Laravel/framework、league/commonmark、league/flysystem、symfony/http-foundation、symfony/yamlは別の依存更新として対応する。本件で無断更新しない。
- 情報源の品質は4週試行（10/05〜11/01）を記録し、**11/02以降**に再精査。取得/解析/固定fixtureの成功を有用性・網羅性の合格へ読み替えない。
- フルスイートは未実行。変更の対象UC全関連304件とE2Eを実行し、別件の試験は広げていない。mainへの適用/commit/merge/pushも未実施。

最終回帰では既存CSV取込テストの同期ジョブが外部の株価取得を実行して待機したため、その実行を止め、`QUEUE_CONNECTION=database`で専用DBへ保存する設定で再実行した。上記304件が最終結果。同期ジョブの本体/既存テスト/アプリ設定の変更は行っていない。
