# CHG-0031: UC-019統合後の追加衝突解消案

2026-10-21。UC-016／UC-012・ADR-0033。追加Gate 4の10件と元の2コミット/mainマージ計画は本人承認済み。**本書の追加3ファイルの衝突解消は本人承認済み（2026-10-21）。** 新機能や新テストケースの承認依頼ではない。

## 現在地

- `9f8c299`: `feat: add research automation persistence schema`（migrationのみ）。
- `f25f186`: `feat: generate reviewable research drafts from FDA records`（77ファイル）。
- 元の実行時はmainで別セッションのUC-019マージが進行中だったため、取り消さず停止した。本件のマージは開始していない。
- 今回の`git fetch origin`後、mainとorigin/mainはともに`c6d6d63`。UC-019は`61f2c86`でマージ済み。main専用worktreeはclean、MERGE_HEADなし。
- 実装は専用`feat/chg0031-nedo-source`で作成済み。共有アプリのcheckoutや本人DBは変更していない。

## 衝突と解消案

merge-base `3f12941`／main `c6d6d63`／feature `f25f186`の3-way検査。既知2件と新規3件の計5ファイルで衝突。下記はGit操作をしない作業用コピーで作成済み。

| ファイル | 解消案 | 承認 |
|---|---|---|
| `PLAN.md` | CHG-0031の進捗とmainの最新UC-019実機記録を両方保持。完了CHG-0045は承認済み原文アーカイブを維持。統合案298行。 | 元の計画で承認済み |
| `docs/rcid/traceability-matrix.md` | CHG-0031はfeature、CHG-0033は最新mainの行を採用。他行は保持し重複を作らない。 | 元の計画で承認済み |
| `app/Console/Commands/AppOnStartCommand.php` | mainの実行用メソッドとウォッチリスト投入を保持し、情報源確認の後に候補生成を追加。生成時だけtrigger=startup、finallyで復元。 | **本人承認済み（2026-10-21）** |
| `docs/adr/ADR-0031-research-source-startup-check.md` | UC-019のキュー投入の追記と、CHG-0031の候補生成の追記を両方残す。統合時点のGate承認・FDA実装状態を追記し、過去の審議時点と区別する。 | **本人承認済み（2026-10-21）** |
| `docs/ai-context/common-commands.md` | 起動順を価格追跡→ウォッチリスト更新投入→情報源確認→企業下書き生成に統合。個別コマンド説明は保持。 | **本人承認済み（2026-10-21）** |

UC-019の投入は前回成功から72時間以上など既存条件を守り、キューの完了を待たない。情報源確認と候補生成は直列。`--force`は情報源確認だけに渡し、ウォッチリストの72時間条件には効かせない。価格追跡／投入／情報源確認の失敗でも後続へ進み、どれかが失敗すれば起動コマンドは失敗終了とする。mainの売買振り返り実装・テスト・当時記録は変更しない。

## 事前検証（追加案、main未適用）

専用worktree内のignoredな`storage/app/chg0031-integration-preview2`へmainを展開し、featureの差分を3-way統合。テストを編集せず、専用DB`testing_chg0031_source_adapters`、キュー接続databaseで実行した。共有DBや通常アプリのmigrationは行っていない。

- `UC016ResearchCandidateAutomationTest`＋`UC019StartupWatchlistRecordTest`: **39成功、310assertions、20.96秒、失敗/エラー0**。
- `AppOnStartCommand.php`のPint `--test`: **1ファイル成功**。
- 証跡: 作業用コピーの`storage/logs/chg0031-uc019-integration-preview.xml`。
- 全体スイート・競合・画面E2Eは、この事前確認で成功したとは扱わない。元の承認計画どおり、実際の統合結果で実行する。

## 追加承認後の実行範囲

1. mainがcleanで先端`c6d6d63`のままであることを再確認し、`git merge --ff-only origin/main`。
2. main専用worktreeで`git merge --no-ff --no-commit feat/chg0031-nedo-source`。
3. 上記5ファイルを解消し、元の計画で認められた反映状態の文書更新と、本書・実行結果をマージコミットへ含める。UC-019の実機記録を古い記述に戻さない。
4. lockどおりの依存導入、Vite build、専用DBで全Pest・実2接続競合・UC-016画面E2Eを実行。失敗時は本件のマージを取り消し、原因を報告する。
5. 全件成功後、元の計画のメッセージ形式でローカルmainへマージコミットを作る。専用featureブランチは後続用に保持する。

既存2コミットの作り直しや、Gate 4の再承認は求めない。push・共有アプリ更新・本人DBへのmigrationは本計画に含めない。今回の解消案以外の衝突が増えた場合は停止して確認する。情報源品質の再精査は11/02以降として継続する。

関連: [元の承認済みGit計画](chg0031-automation-commit-merge-plan.md)、[マージ前レビュー](chg0031-automation-merge-review.md)、[引継ぎ](chg0031-automation-session-handoff.md)。

## 実行結果（2026-10-21）

本人が「追加解消案を承認してマージまで進む」と回答し、追加3ファイルの解消も承認。mainの追加変更`2c3b403`はPLANのUC-019完了記録だけで、既知の解消方針で保持した。5衝突を解消し、本マージでローカルmain統合。テスト環境の復旧経過、全Pest・競合・E2E・Pint・buildは[検証記録](chg0031-automation-main-merge-verification.md)を正本とする。push/通常アプリ導入/本人DBのmigrationは未実施。
