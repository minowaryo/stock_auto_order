# UC-016 FDA追加Red / Gate 4審議材料

作成: 2026-10-11。CHG-0031。FDA → NEDO → YouTubeの実装順に基づく最初の追加。既存RSS起動時確認のGate 4とは別のケース。**2026-10-11本人がADR-0032と31件を承認し、Greenへ着手**。有効化は検証後に判断する。

## 承認対象

- [ADR-0032](../adr/ADR-0032-research-source-adapters-and-access-intervals.md): FDA CSVの解析、決定日と公開日の区別、題名255文字/元metadata4KB、FDA内の時系列、forceでも守る30秒間隔。DBスキーマと画面構造の変更なし。
- `tests/Feature/UC016ResearchSourceFdaTest.php` の31ケース（データセット込み）と合成データ用 `tests/Support/ResearchSourceFdaFixtures.php`。
- [実装順と一次資料の確認](../product/megatrend-source-implementation-plan.md)。今回のGreen対象はFDAだけ。NEDOとYouTubeの新ケースは後続の審議対象。

## ケースの範囲

| 範囲 | 確認する契約 |
|---|---|
| 正常CSV | 510(k)/De Novo/PMA/PMA補足の4種類、公式リンク、6フィールド、BOM、引用カンマ・改行 |
| 基準線と差分 | 初回は新着0、失敗後の初成功、正規ヘッダーだけの0件、既存番号/重複/行順/本人の処理状態、新番号だけを新着 |
| 日付と表示 | 決定日はmetadataと題名で明示、公開日はNULL、新しい決定から一覧に出る、作成/更新時刻は取得時刻、長い題名でも番号/決定日が残る |
| 破損と境界 | 必須列欠落、HTML、番号/日付/企業名/列数の破損、一部保存の禁止、サイズ上限、MySQLに保存されたJSONが4KB以内、超過時の源全体失敗 |
| 取得の制御 | 公式CSV直URL/168時間/30秒の既定設定、1リクエスト/CSV Accept、無効源、168時間境界、forceと30秒境界、源間1.5秒との差分待機、全体120秒不足、専用ロック、次のRSSへの継続 |
| 自動登録の防止 | CSV取得で調査候補やウォッチリストを作らない。登録は既存の本人選択経路を使う |

## Redの実行結果

専用MySQL `testing_chg0031_source_adapters`、合成HTTP応答、`Sleep::fake(syncWithCarbon: true)` を使用。HTTP Factoryを各設定で差し替え、未設定の外部アクセスは禁止。実アプリDB・実情報源へのアクセスなし。

- 31件: **19失敗 / 12成功 / エラー0**、82アサーション、9.12秒（最終実行。120秒不足ケースは直接HTTP未送信の期待で失敗を確認）。
- ログ: `/tmp/chg0031-fda-red/fda-red.log`、JUnit: `/tmp/chg0031-fda-red/fda-red.xml`。一時ファイルのため将来の再開時には再実行する。
- 失敗14件は成功CSVが現行のfile未対応で拒否されるため。残り5件は既定URL未変更、CSV Accept未対応、秒待機2件、待機で全体予算が不足したときの省略未実装。
- 成功12件のうち8件（破損6、metadata超過、次源継続）は現行のfile未対応による拒否でも成立する。**CSVを解釈できた証拠ではない**。Greenでは正常系も通った状態で異常系を維持する必要がある。
- その他の成功4件は既存ガード（無効源、168時間未満、サイズ超過、専用ロック）。

## 独立レビューと回帰確認

独立エージェントがテスト/資料/実行ログをレビューし、**未解消の確定HIGH/MEDIUMなし**と確認した。長い題名の番号/決定日保持、保存後JSONの実体サイズ、決定日を作成時刻に代入しないことを、レビュー指摘に従い追加assertした。ASCII境界フィクスチャの整形JSONは4,061bytesで、4KB以内の保存を期待することも独立計算で確認した。

- 既存 `UC016ResearchSourceStartupTest.php` は専用DBで**26件成功、110アサーション**、エラー0、8.82秒（ログ `existing-startup.log` / JUnit `existing-startup.xml`）。
- review-scoreの初回判定は31で強化相当。Domain Boundaryは変更Controllerなし、指摘0。Red段階の範囲は文書/テストだけで、同段階では全体スイートやブラウザは再実行していない。
- PHP2ファイルのPintは成功。差分の書式エラーなし。

## Green後の確認と限界

1. 31件と既存起動時確認を通す。
2. 確認済み公開CSV1,614行を専用DBへ取り込み、全行/原フィールド/公式URL/表示順と、既存の1源15秒・全体120秒以内での処理を確認する。
3. レビュー後にFDAの有効化とローカルアプリへの反映を判断する。

公式CSVは非網羅で、決定日は発表日や導入日ではない。企業名から上場主体を本人が照合する。情報源の品質は未合格で、4週試行後の2026-11-02以降の再精査を継続する。`.claude/rules/30-testing.md` に従い、本人の追加Gate 4承認前にGreenへ進めない。

## 承認後のGreenと秒精度の追加差分

本人が元の31ケースとADR-0032を承認し、CSV解析/取得制御を実装した。元31件と既存49件の統合は**80件成功・421アサーション・13.81秒**、Pint6ファイルも成功。初回統合で、時系列ケースの未到達assertがEloquentの配列castを二重JSON解釈してTypeErrorになることを確認。承認した順序の期待を維持し、配列直接参照へ直して再実行した。

独立GreenレビューではCSV解析/安全な保存/認可にHIGHなし。ただし、既存DBの秒精度で実アクセスの小数秒が消え、forceの実間隔が29.1秒になる**MEDIUM1件**を確認した。当該レビュー時点ではFDA設定は無効。ADR-0032末尾の追加補足案（完了済み試行の開始/完了時刻の遅い方+31秒）と、追加Red4件/既存3件の待機期待補正を別のGate 4へ提示する。元31件の承認でこの変更を実装しない。

追加4件は実MySQLで小数秒が落ちる成功/失敗2ケース、31秒境界、試行記録のINSERT後に時計を1.2秒進めるDB書込み遅延ケース。既存3件は整数秒の待機を20→21秒、源間待機後18.5→19.5秒、保存時刻から30秒境界の待機を0→1秒に補正する。独立レビューで、完了時刻を用いる因果関係とfixtureの妥当性を確認した。

最終Red: **FDA35件は6失敗/29成功、既存Startup26件は全成功**。計61件・357アサーション・エラー0・10.12秒。ログは`/tmp/chg0031-fda-precision-red/precision-red-final.xml`。6失敗の内訳は小数秒2件（実間隔29.1秒）、DB遅延1件（27.9秒）、旧3件の待機不足。独立エージェントもログを確認し、すべて狙った失敗で、テスト側の未解消HIGH/MEDIUMなしと確認した。31秒境界の追加1件は現行でも成功する回帰確認。この補足と追加/補正ケースは**2026-10-11本人承認済み**（「承認して精度補足を修正する」）。

## 最終Greenと導入前検証（2026-10-13）

- 精度補足を実装後、FDA35件と既存49件は**84件成功、469アサーション**（2026-10-11、14.76秒、`/tmp/chg0031-fda-red/fda-green-precision.xml`）。
- 2026-10-13の再開後、作業ブランチ全体を既存PHPコンテナから専用DBで実行。**1,977件、失敗/エラー0、スキップ1、8,420アサーション、158.89秒**。Pest表示は1,953 passed・23 deprecated・1 skipped。CLIの終了コードは0。全体JUnitは生成されていないため、この結果はCLI出力に基づく。
- 2026-10-11取得の公開CSV1,614行を専用DBで検証。初回3.25秒、168時間後を模擬した再確認0.95秒。全6フィールド一致、公開日時全NULL、題名最大255文字、保存metadata最大607bytes、先頭20件の最新決定日は2026-06-29。両試行の新着0、候補/監視の自動登録なし。HTTPは2回とも模擬し、検証データは外側のトランザクションでロールバックした。検証用の静的HTTP応答は読取後にストリームが閉じるため、再取得時は新しい応答を返すよう修正した。
- 2026-10-13の独立強化レビューは確定HIGH/MEDIUMなし。秒精度/DB遅延の指摘は解消。承認済みADRへのコメント修正も反映。
- 作業ブランチのS10設定は有効。**未コミット・main未統合・実アプリ未反映**。実アプリでFDAを取得したとは扱わない。
- UIの構造変更がないため、新規E2E/ブラウザ確認・フロントエンドbuildは今回は追加していない。既存画面の回帰はFeatureテストに含まれる。情報源の品質再精査は11月2日以降に継続。

## 再開時の再確認（2026-10-16）

- PHP/MySQLの停止を確認し、起動後に専用DBでFDA35件+既存49件を再実行。**84件成功・469アサーション・12.43秒**。JUnitは作業ブランチの`storage/logs/fda-source-verification-20261016.xml`（Git対象外）。
- コードの挙動は10/13の全体1,977件成功時点から変更なし。今回の変更は承認/進捗/トレーサビリティの記録整理。mainは引き続き`4907b80`で、FDAは未コミット・未統合・実アプリ未反映。
- 同日の再開確認でも対象84件・469アサーションが成功（11.47秒、終了コード0）。外部HTTPは模擬、実アプリDBは使用していない。

## コミット・main統合の承認計画（2026-10-16本人承認済み）

本人の「承認」を受領。以下の1コミット作成とローカルmain統合を実行する。実行結果は後続節へ記録する。

- FDA取得を実装/テスト/設計記録と一緒に1コミットにする。400行を超えるが、FDAの一つのTDDサイクルとしてまとめる。マイグレーションはない。
- main専用worktreeでリモートを確認し、fast-forward更新後、`--no-ff --no-commit`で統合する。統合結果の全テストを専用DBで実行し、成功後にだけマージコミットを作る。
- 競合、fast-forward更新失敗、テスト失敗では後続を止める。テスト失敗時は未確定マージをabortする。作業ブランチは保持する。
- 完了条件はFDAの変更がローカルmainに入り、統合結果のテストが成功していること。実アプリへの反映とリモートpushはこの計画の対象外。次はNEDO追加Red。情報源の品質再精査は11月2日以降に残す。

| # | Files | Lines | Message |
|---|---|---|---|
| 1 | 下記16ファイル（実装/設定4、テスト2、資料10） | 提案前の差分 +918/-20、加えて本計画の記録 | `feat: check FDA AI medical device sources` |

対象ファイルを明示してstageする。`git add -A`は使用しない。以下のコマンドは、コミット/マージ本文を一時ファイルへ準備した後に、承認を得て順番に実行する。main側の依存ファイルと非公開設定はテスト前に存在/参照先を確認し、資格情報の値を出力しない。

```bash
cd /root/workspace/stock_auto_order/.claude/worktrees/chg0031-sources
git add PLAN.md \
  app/Services/Research/ResearchSourceCheckService.php \
  app/Services/Research/ResearchSourceFetcher.php \
  app/Services/Research/ResearchSourceFdaParser.php \
  config/research_sources.php \
  tests/Feature/UC016ResearchSourceFdaTest.php \
  tests/Support/ResearchSourceFdaFixtures.php \
  docs/architecture/data-model.md \
  docs/product/megatrend-discovery-handoff.md \
  docs/product/megatrend-source-selection.md \
  docs/product/use-cases.md docs/product/user-guide.md \
  docs/rcid/traceability-matrix.md \
  docs/adr/ADR-0032-research-source-adapters-and-access-intervals.md \
  docs/development/uc016-source-fda-red-review.md \
  docs/product/megatrend-source-implementation-plan.md
git commit -F /tmp/chg0031-fda-commit-message.txt
cd /root/workspace/stock_auto_order/.claude/worktrees/chg0031-main
git fetch origin
git merge --ff-only origin/main
git merge --no-ff --no-commit feat/chg0031-source-adapters
docker exec \
  -w /var/www/html/.claude/worktrees/chg0031-main \
  stock_auto_order_wsl-laravel.test-1 php -r '
foreach (["APP_ENV"=>"testing","DB_DATABASE"=>"testing_chg0031_source_adapters","DB_URL"=>"","QUEUE_CONNECTION"=>"sync"] as $key=>$value) {
    putenv($key."=".$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
}
$_SERVER["argv"]=$argv=["vendor/bin/pest","--compact","--log-junit=storage/logs/fda-merge-verification.xml"];
$_SERVER["argc"]=$argc=count($argv);
require "vendor/bin/pest";'
git commit -F /tmp/chg0031-fda-merge-message.txt
```

機能コミット本文案:

```text
feat: check FDA AI medical device sources

Let UC-016 collect official device evidence while preserving manual
company verification and source access limits (ADR-0032).

Co-Authored-By: Codex <noreply@openai.com>
```

マージ件名は`Merge branch 'feat/chg0031-source-adapters'`、本文は同じ目的を記載し、実行時のレビュー判定と統合後の実際のテスト結果をトレーラーへ記録する。main統合の承認後に、実行済みの状態へ更新する。
