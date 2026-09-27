# ADR-0018: 画面表示と日付判定のタイムゾーンを Asia/Manila に統一する（DB保存は UTC のまま）

## Status
Accepted（2026-09-27、本人指示・範囲選択済み）

## Date
2026-09-27

## Context

2026-09-27、本人から「日付が一律マニラ時間になっているか確認して」との指示があった。調査の結果、以下がすべて UTC だった。

| 層 | 設定 |
|---|---|
| Laravel `config/app.php` の `timezone` | `UTC` |
| PHP（Sail コンテナ） | `UTC` |
| MySQL `@@time_zone` | `SYSTEM`（コンテナのOS＝UTC） |

画面上の日時もタイムゾーン変換なしで表示されていたため、例えば取込履歴の「09:12」はマニラ時間では 17:12 であり、利用者の生活時刻とずれていた。表示箇所は次の6つ。

- CSV取込画面の取込履歴（`import_batches.imported_at`）
- 新規投資候補画面の「最終更新」（`watchlist_refresh_runs.finished_at`）
- 新規投資候補画面の行展開内のウォッチ記録日時（`watch_records.recorded_at`）
- 銘柄詳細画面のメモ日時（`holding_memos.recorded_at`）
- サマリーレポートの取込日時キャプション（`import_batches.imported_at` / `snapshots.snapshotted_at`）
- 銘柄詳細の株価推移チャートの日付（`snapshots.snapshotted_at`）

加えて、CHG-0020 の移送コマンド `signal-outcomes:backfill`（ADR-0017 D6）は発生週の判定に Asia/Manila を直書きしている。

## Decision

- **D1 保存は UTC のまま**: `config/app.php` の `timezone` と DB の時刻は変更しない。既存行の変換（データ移行）はしない
- **D2 表示と日付判定のタイムゾーンを設定値 `app.display_timezone`（既定 `Asia/Manila`、環境変数 `APP_DISPLAY_TIMEZONE` で上書き可）に集約する**。画面に日時・日付を出すときは、必ずこのタイムゾーンへ変換してから書式化する
- **D3 変換は1か所のヘルパーに寄せる**（`App\Support\DisplayTime`）。各画面・Action で `->timezone(...)` を個別に書かない
- **D4 日付判定**: 「どの日・どの週か」を決める処理（現時点では `signal-outcomes:backfill` の発生週判定）も `app.display_timezone` を使う。Asia/Manila の直書きはやめる
- **D5 対象外**: JSON API（`GET /market-indicators` 等）のタイムスタンプは ISO 8601 の UTC 表記のまま（オフセット付きで曖昧さがないため）。外部データ（Yahoo 週足の日付・J-Quants の開示日）はデータソース側の日付をそのまま扱う（`WeekDateNormalizer` の週判定は取引所の週に揃える規則であり、表示タイムゾーンとは別物）

## Rationale

- **保存まで Asia/Manila に変える案を採らなかった理由**: 既存行は UTC で保存済みで、アプリのタイムゾーンを変えると新しい行だけ +8 時間ずれて保存され、新旧が混在する。全テーブルの一括変換は漏れのリスクが高い。保存は UTC、表示で変換するのが Laravel の標準的な運用でもある（本人が選択肢から「表示と日付判定のみ」を選択）
- **設定値にした理由**: 利用者の居住地が変わった場合に `.env` だけで切り替えられるようにする
- **ヘルパーに寄せる理由**: 表示箇所ごとに変換を書くと、新しい画面で変換漏れが起きやすい（CHG-0005 型の乖離の防止）

## Consequences

- 6つの表示箇所の時刻が +8 時間（マニラ時間）で表示されるようになる。日付の境界（UTC 16:00〜23:59 の記録）は日付自体が翌日に変わる
- 新しい画面で日時を表示する際は `DisplayTime` を使う必要がある（`docs/product/ui-guidelines.md` に規約として追記）
- 日曜 23 時台（マニラ時間）の取込だけは、JP株の週足の切り替わり（月曜0時 JST）と1時間ずれる。影響は移送コマンドの発生週判定のみで、一度きりの処理のため許容する（ADR-0017 D6）
