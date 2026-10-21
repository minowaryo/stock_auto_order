# CHG-0031 FDA企業下書き・コミット/マージ計画

2026-10-20。対象はUC-016／UC-012、ADR-0033。**本人が「計画を承認してコミット・マージする」と回答し、本計画を承認。承認された手順を実行する。** 品質ゲートの追加10件は本人承認済みで、Green修正と[検証](chg0031-automation-merge-review.md)は完了。

## 再開時の状態（2026-10-21）

2コミット`9f8c299`／`f25f186`は作成済み。その後UC-019がmainへ統合され、main/origin/mainは`c6d6d63`。追加3ファイルの衝突を確認したため、元の停止条件に従いmainはcleanで維持。[追加の具体的解消案と39件の事前検証](chg0031-automation-uc019-integration-plan.md)を作成した。追加解消案は承認待ちで、実際のmainマージ・統合結果の全体検証は未実施。

## 完了した確認

- 強化レビューの4指摘は修正済み。関連Feature313件＋実2接続競合1件（計2,222assertions）成功、画面E2E1件成功、Pint36ファイル成功。Green中の承認済みテスト151ファイルの変更0。
- spec-lintは既存21指摘/警告0を維持。全体成功とは扱わず、新しい指摘はない。Domain Boundaryは対象Controller0件/指摘0で、Livewire/Serviceを実読済み。
- `git fetch origin`済み。確認時点のmain/origin/mainはともに`b39ab6e`、main専用worktreeはclean。
- `3fbc3fb`全体は取り込まず、本件の仕様/モックだけを選択。既存`9e4bc14`のNEDO一覧取得を含むブランチを継続する。

## コミット分割

| # | 対象 | 件名 |
|---|---|---|
| 1 | migration 1ファイル。追加10テーブル/既存nullable・既定値付き5列 | `feat: add research automation persistence schema` |
| 2 | 下記ファイル一覧。FDA抽出・公式照合・本人確認画面・承認済みテスト・説明文書 | `feat: generate reviewable research drafts from FDA records` |

2は400行を超えるまとまりだが、取得/照合/本人確認を結ぶ承認済みテストが共通しているため、liteのUC単位で一緒に保存する。テストだけのRedコミットや、テストが要求する入口を欠いた途中の実装コミットには分けない。migrationは独立させる。各メッセージに理由と`Co-Authored-By: Codex <noreply@openai.com>`を付ける。

## main統合と既知の文書衝突

1. 専用feature worktreeで上記2コミットを作る。下記の正確なファイルだけをstageし、`git add -A`を使わない。
2. main専用worktreeがcleanであることを再確認し、`git fetch origin`→`git merge --ff-only origin/main`。
3. mainへ`git merge --no-ff --no-commit feat/chg0031-nedo-source`。
4. 事前の3-way検査で確認した以下2件は、本人にこの計画で承認された統合内容で解消する。**未知の衝突・リモートとの分岐・検証失敗があれば止める。**
   - `PLAN.md`: 作業ブランチの現行統合案を採用。本件エントリとmain側CHG-0050の実データ確認/CHG-0033の最新状態を両方保持。完了済みCHG-0045は原文をアーカイブし、main祖先`3414d70`で完了を裏付ける。300行上限を守る。
   - `docs/rcid/traceability-matrix.md`: 本件CHG-0031のNEDO/FDA進捗は作業ブランチ、別件CHG-0033の7f/公開後修正/UC-019保留はmainを保持した現行統合案を採用。重複行を作らない。
   - 自動統合されるUCの承認記録やcommon-commandsも、両側の更新が残っていることを確認する。
5. 反映状態の文書（PLAN・project-summary/module-map・本件計画/仕様/引継ぎ）を、このmainマージで統合する状態へ更新し、検証後のマージコミットへ含める。通常アプリへの配布や本人DBへのmigrationを実施済みとは記載しない。
6. main専用worktreeの依存をlockから揃え、Vite build後、**統合結果そのもの**で全Pestスイートと専用DBの競合テスト、UC-016画面E2Eを実行する。環境は下記のテストDBだけ。既存アプリ/本人DBにはmigrationしない。
7. 全件成功後にmainのマージコミットを作り、両worktreeがGit上cleanであることを確認する。専用feature worktree/ブランチは後続作業のため残す。本計画はローカルmainへの統合まで。

変更画面: 既存の候補調査画面と新しい企業下書き部分。新規ルートはない。PC/狭い幅の表示は前回RUNで確認済み、今回のE2Eでも本人確認OFF→取り込み→監視0を確認した。

## 実行する主なコマンド

```bash
# feature worktree
# git add は下記の列挙ファイルを各コミット別に指定する。
git commit -F <migration-message-file>
git commit -F <feature-message-file>

# main専用worktree .claude/worktrees/chg0031-main
git fetch origin
git merge --ff-only origin/main
git merge --no-ff --no-commit feat/chg0031-nedo-source
# 既知2文書を上記の承認済み統合案で解消・反映状態の文書更新後にstage。
composer install --no-interaction --prefer-dist
npm run build
# 以下の隔離DBラッパー経由でPestを実行。
php vendor/bin/pest --compact
php vendor/bin/pest tests/Concurrency/UC016ResearchSourceHandoffConcurrencyTest.php --compact
# 架空データの隔離アプリに対して実行。
node node_modules/@playwright/test/cli.js test tests/e2e/uc016-research-automation.spec.ts

git diff --check
git commit -F <merge-message-file>
```

PHP/Composerはホストにないため、実際には既存Laravelコンテナ内のmain worktreeへ`chdir`して実行する。Pestラッパーは`APP_ENV=testing`、`DB_URL=''`、`QUEUE_CONNECTION=database`とDB名を`putenv`/`$_ENV`/`$_SERVER`すべてへ明示設定してからvendor/bin/pestを呼ぶ。キューワーカーは起動しない。

- 全Pest: `testing_chg0031_source_adapters`。
- 競合: `testing_chg0031_review_concurrency`。テスト内のDB名完全一致ガード後だけ`migrate:fresh`。
- 画面E2E: `testing_chg0031_review_e2e_20261020`。架空ユーザー/FDA下書きのみで、今回の一時サーバーを使う。既存プレビューDBをリセットしない。

全体スイートはこの統合結果で1回実行する。現ブランチの対象314件成功を、全体スイート成功と表示しない。新しいmainが承認後に進んだ場合は差分を再確認し、想定外の衝突があれば止める。

## マージメッセージ案

```text
Merge branch 'feat/chg0031-nedo-source'

Let the owner review source-backed company drafts before creating
research records or choosing a watchlist entry (UC-016 / UC-012).

Merge-Check: required (score from final check; automation migration)
Review: enhanced
Tests: merged-tree full Pest suite, concurrency and UC-016 E2E
```

トレーラーには承認後に実測のスコア・件数/証跡を記入する。失敗時にはマージコミットを作らず、mainを未マージ状態のまま放置しない。

## 残す作業

企業集約/追加絞り込み、NEDO本文/H02、YouTube、既存依存監査、情報源の4週試行と**11/02以降**の品質再精査はPLANで継続する。本件FDA初期版のコミットをCHG-0031全体完了とは扱わない。

## コミット1のファイル

- `database/migrations/2026_10_19_000000_create_research_automation_tables.php`

## コミット2のファイル（77ファイル）

- `.env.example`
- `PLAN.md`
- `app/Console/Commands/AppOnStartCommand.php`
- `app/Console/Commands/ResearchDiscoverCandidatesCommand.php`
- `app/Livewire/Candidate/CandidateResearch.php`
- `app/Models/ResearchAutomationRun.php`
- `app/Models/ResearchCandidateDraft.php`
- `app/Models/ResearchCompanyAlias.php`
- `app/Models/ResearchDiscoveryLink.php`
- `app/Models/ResearchDocumentAttempt.php`
- `app/Models/ResearchDocumentVersion.php`
- `app/Models/ResearchDraftListingMatch.php`
- `app/Models/ResearchDraftRevision.php`
- `app/Models/ResearchListingCatalogEntry.php`
- `app/Models/ResearchListingCatalogImport.php`
- `app/Models/ResearchSourceCheck.php`
- `app/Models/ResearchSourceDocument.php`
- `app/Services/Research/ResearchCandidateAutomationService.php`
- `app/Services/Research/ResearchCandidateService.php`
- `app/Services/Research/ResearchDraftReviewService.php`
- `app/Services/Research/ResearchListingCatalogParser.php`
- `app/Services/Research/ResearchListingCatalogService.php`
- `app/Services/Research/ResearchSourceCheckService.php`
- `composer.json`
- `composer.lock`
- `config/research_automation.php`
- `config/research_sources.php`
- `docs/adr/ADR-0031-research-source-startup-check.md`
- `docs/adr/ADR-0033-research-candidate-automation.md`
- `docs/ai-context/common-commands.md`
- `docs/ai-context/module-map.md`
- `docs/ai-context/project-summary.md`
- `docs/architecture/data-model.md`
- `docs/architecture/research-automation-data-model.md`
- `docs/development/chg0031-automation-commit-merge-plan.md`
- `docs/development/chg0031-automation-fda-green-review.md`
- `docs/development/chg0031-automation-fda-red-review.md`
- `docs/development/chg0031-automation-gate3-review.md`
- `docs/development/chg0031-automation-jpx-code-red-review.md`
- `docs/development/chg0031-automation-merge-review.md`
- `docs/development/chg0031-automation-review-preparation.md`
- `docs/development/chg0031-automation-session-handoff.md`
- `docs/development/chg0031-run-verification.md`
- `docs/development/uc016-source-nedo-red-review.md`
- `docs/history/plan-archive.md`
- `docs/product/megatrend-candidate-automation-plan.md`
- `docs/product/megatrend-candidate-automation-spec.md`
- `docs/product/mockups/README.md`
- `docs/product/mockups/assets/research-candidate.css`
- `docs/product/mockups/screen-UC016-research-candidate-discovery.html`
- `docs/product/mockups/screen-UC016-research-candidate-review.html`
- `docs/product/mockups/screen-UC016-research-candidate-states.html`
- `docs/product/requirements.md`
- `docs/product/ui-guidelines.md`
- `docs/product/use-cases.md`
- `docs/product/user-guide.md`
- `docs/rcid/traceability-matrix.md`
- `resources/views/livewire/candidate/candidate-research.blade.php`
- `resources/views/livewire/candidate/research-drafts.blade.php`
- `tests/Concurrency/UC016ResearchSourceHandoffConcurrencyTest.php`
- `tests/Feature/UC016ResearchAutomationBudgetTest.php`
- `tests/Feature/UC016ResearchCandidateAutomationTest.php`
- `tests/Feature/UC016ResearchCandidateDraftScreenTest.php`
- `tests/Feature/UC016ResearchDraftReviewSafetyTest.php`
- `tests/Feature/UC016ResearchListingCatalogJpxCodeTest.php`
- `tests/Feature/UC016ResearchListingCatalogTest.php`
- `tests/Fixtures/ResearchAutomation/jpx-formula.xlsx`
- `tests/Fixtures/ResearchAutomation/jpx-invalid-row.xlsx`
- `tests/Fixtures/ResearchAutomation/jpx-mixed-formal-codes.xlsx`
- `tests/Fixtures/ResearchAutomation/jpx-older.xlsx`
- `tests/Fixtures/ResearchAutomation/jpx-overexpanded.xlsx`
- `tests/Fixtures/ResearchAutomation/jpx-valid.xlsx`
- `tests/Fixtures/ResearchAutomation/jpx-zero.xlsx`
- `tests/Support/ResearchAutomationFixtures.php`
- `tests/Support/ResearchAutomationReviewFixtures.php`
- `tests/Support/ResearchListingCatalogFixtures.php`
- `tests/e2e/uc016-research-automation.spec.ts`

## 実行結果（2026-10-21）

本人が「追加解消案を承認してマージまで進む」と回答し、追加3ファイルの解消も承認。mainの追加変更`2c3b403`はPLANのUC-019完了記録だけで、既知の解消方針で保持した。5衝突を解消し、本マージでローカルmain統合。テスト環境の復旧経過、全Pest・競合・E2E・Pint・buildは[検証記録](chg0031-automation-main-merge-verification.md)を正本とする。push/通常アプリ導入/本人DBのmigrationは未実施。
