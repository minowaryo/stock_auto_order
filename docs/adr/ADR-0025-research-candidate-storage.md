# ADR-0025: 調査候補（UC-016）の保存設計と、ウォッチリストへの受け渡し履歴

## Status

Accepted（2026-10-04、本人が「Ｇａｔｅ３承認でよい」と明示）。CHG-0031のUC-016・UC-012受け渡しはGate 2承認済み。同日のGate 3承認対象は本ADRとdata-model.mdの`research_*`節の保存設計。情報源固有の自動取得とGate 4テストケースは未承認。

## Date

2026-10-04

## Context

UC-016（市場からの調査候補発見・確認）は、本人が公開記事・動画などから見つけた企業を、出典・元発表・主張ごとの確認状態・上場主体の同定とともに記録し、本人確認後にUC-012のウォッチリストへ渡す。Gate 2で次が要件として確定した。

- 未同定（銘柄コード未確定）や一次資料未確認のままでも記録できる。
- 紹介記事（発見経路）と元発表を区別し、同じ元発表の転載を独立した裏付けとして数えない。同じ元発表と法人の組は重ねて作らない。
- 「元発表の件数」と「確認済み上場企業の数」を分けて集計し、同じ企業の複数上場は1社と数える。
- 編集の変更前後を残し、監視登録の確認に使った版を上書きしない。編集競合と、確認欄表示後の更新を検知する。
- 監視登録時は、未登録なら★ONで新規登録、既存行なら★・フォルダ・メモを上書きせずに関連付け、保有済みなら行を作らない。二重送信でも重複させない。
- 調査中の候補を、市場データの一括更新対象（ウォッチリスト）に含めない。

既存実装の制約（2026-10-04確認）:

- `ImportFavoriteCsvAction` はCSV再取込のたびに `watchlist_items.source` を `rakuten_favorites_csv` へ `updateOrCreate` で上書きする。`source` に「調査候補から登録」を持たせても、後からCSVに現れた時点で消える。
- `ShowWatchlistAction` は `in_rakuten_favorites` を `source === 'rakuten_favorites_csv'` から求めている。調査候補のみから登録した行（CSVに一度も出ていない）が「楽天側で解除済み」と誤表示される。
- `holdings` は「参照したことのある全銘柄」の銘柄マスタで、作成すると他機能（セクター分類のbackfill等）の母集団に入りうる。

## Decision

### D1 調査記録は既存テーブルと分離した専用テーブル群に置く

`research_` 接頭辞の8テーブルを新設する（定義は `docs/architecture/data-model.md`）。既存テーブルのスキーマは変更しない。

| テーブル | 役割 |
|---|---|
| `research_events` | 元発表（出来事）。元資料URL・発行主体・元発表日・確認状態。同一出来事の統合先を持つ |
| `research_discovery_links` | 発見経路（紹介記事・動画・SNS等）。1つの元発表に複数。投稿日・確認日・本人の要約・スポンサー表示 |
| `research_entities` | 言及された法人と、上場主体の同定状態 |
| `research_entity_listings` | 法人の上場（市場・銘柄コード）。1法人に複数上場を持てる |
| `research_candidates` | 調査候補＝元発表×法人の組。テーマ・企業の役割・証拠段階・反証・状態・楽観ロック版数 |
| `research_claims` | 候補ごとの主張と根拠URL・確認状態 |
| `research_candidate_revisions` | 候補の版（変更前後の全体スナップショット） |
| `research_watchlist_handoffs` | 監視への受け渡し履歴（確認した版・照合時点・結果） |

### D2 未同定の法人は `holdings` を作らず、監視登録の確定時にだけ find-or-create する

同定前・同定済みでも監視未選択の段階では、市場・銘柄コードを `research_entity_listings` に持つだけにする。`holdings` / `watchlist_items` の作成は「監視に追加」確定時のみ。これにより調査中の候補が一括更新・セクター分類・保有判定の母集団に混ざらない（UC-016 フロー5）。

### D3 一意性で重複を防ぐ

- `research_candidates (research_event_id, research_entity_id)` unique — 同じ元発表と法人の組を重ねて作らない。
- `research_discovery_links (research_event_id, url_hash)` unique — 同じ紹介URLを同じ元発表に二重登録しない（URLは2048文字のため `sha256` のハッシュ列で一意化）。
- `research_entity_listings (market, symbol_code)` は unique にしない（誤同定の訂正中に一時的に重複しうるため）。代わりに保存時に既存法人を提示し、本人が統合を選ぶ（UC-016 フロー2）。
- `research_watchlist_handoffs (research_candidate_id, idempotency_key)` unique — 二重クリック・再送で受け渡しを重複させない。

### D4 版と楽観ロック

- `research_candidates.lock_version` を更新のたびに+1する。保存・監視登録の確定時に画面が保持する版と一致しなければ拒否し、最新を表示する（編集競合・確認欄表示後の更新）。
- 更新のたびに、主張・同定・発見経路を含む候補の全体を `research_candidate_revisions.payload`（JSON）へ追記する。変更前後は連続する版の比較で表示する。版は追記のみで更新・削除しない。
- 監視登録は、確認した版（`research_candidate_revisions.id`）を受け渡し履歴に保持する。

### D5 ウォッチリストの登録経路は `watchlist_items.source` ではなく、事実から導く

- 調査候補から新規作成する行は `source='manual'`（アプリ側での登録）、`is_starred=true`、`folder_name=null`、`last_seen_in_csv_at=null` とする。楽天CSVの取込実績・フォルダ名は作らない。
- 「CSV経路」は `last_seen_in_csv_at IS NOT NULL`、「調査候補経路」は `research_watchlist_handoffs` の存在で判定する。`source` はCSV再取込で上書きされても、経路の判定には使わない。
- `in_rakuten_favorites` の判定を `last_seen_in_csv_at` 基準へ改める（data-model.md の `watchlist_items` 注記に既に記載されている判定と一致させる）。一度もCSVに出ていない行には「楽天側で解除済み」を出さない。これは表示ロジックの変更で、UC-012 Gate 2承認済みの出力定義（`in_rakuten_favorites`・`registration_routes`）に基づく。

### D6 受け渡しは1トランザクションで、結果を3種類に分けて記録する

確定時に保有スナップショット・ウォッチリストを再照合し、`research_watchlist_handoffs.outcome` を次のいずれかで記録する。

| outcome | 処理 |
|---|---|
| `created` | `holdings` を find-or-create し、`watchlist_items` を★ONで作成 |
| `linked_existing` | 既存 `watchlist_items` 行に関連付けるだけ（★・フォルダ・メモは変更しない） |
| `already_held` | 行を作らない。保有銘柄の確認へ案内 |

候補の状態更新（`watchlisted`、ただし `linked_existing` で★OFFなら `investigating` のまま）・版の追記・受け渡し履歴を同じトランザクションで書き、一部だけの成功を残さない。照合に使った保有スナップショットID・お気に入りCSVの最終取込時刻を履歴に保存する。

### D7 削除はアーカイブ（論理削除）、統合は参照で表す

- `research_candidates` は `archived_at` で通常一覧・集計から除外し、復元できる。物理削除しない。共有する元発表・法人・ウォッチリスト行は消さない。
- 元発表の統合は `research_events.merged_into_event_id` で表し、統合元の行を消さない。統合解除は参照を戻し、版に記録する。
- アーカイブ・復元・統合・統合解除・監視登録は、特権・破壊的操作として `audit` チャンネルに固定スキーマ（`action`・`actor_id`・`subject_type`・`subject_id`）で記録する（`.claude/rules/40-security.md`）。現時点で `config/logging.php` に `audit` チャンネルが未定義のため、実装時に追加する。

### D8 情報源固有の自動取得のための列は今は作らない

自動取得（4週試行後に別のGate 2差分）で必要になる情報源ID・取得実行記録・外部ID等は、その差分のGate 3で追加する。今回は `research_discovery_links.route_type`（`manual` のみ）を置いて、将来の経路を区別できる余地だけ残す。

## Rationale

- **専用テーブルにした理由**: 調査記録は未同定・未確認を許す「仮説の記録」で、`holdings` / `watchlist_items` のような確定済みの監視対象とは寿命と正確さの水準が違う。混ぜると、一括更新やセクター分類の対象に未確認の企業が入り、UC-016の「本人確認まで既存へ自動登録しない」（F-016）を守れない。
- **元発表と発見経路を分けた理由**: 転載・紹介記事を独立した裏付けとして数えない要件（F-016）を、テーブル構造で保証するため。
- **法人と上場を分けた理由**: 「確認済み上場企業の数」を法人単位で1社と数え、米国ADRと日本上場のような複数上場を正しく扱うため。
- **版をJSONの全体スナップショットにした理由**: 個人利用で更新頻度が低く、件数も小さい（数百件規模）。列単位の差分テーブルより単純で、確認した版を丸ごと再現できる。
- **`source` 列を拡張しなかった理由**: enum値の追加はMySQLのカラム型変更（20-mysql.md の危険操作）にあたり、しかもCSV再取込で上書きされるため経路の保持に役立たない。経路は事実（CSVでの最終確認時刻と受け渡し履歴）から導く方が壊れにくい。
- **採らなかった案**: 調査カード（Markdown）をそのまま保存する案（検索・重複排除・集計ができない）、`watchlist_items` に調査用の列を足す案（未同定の法人を表せない）、候補の段階で `holdings` を作る案（D2の理由）、版を作らず `updated_at` だけ持つ案（確認した版を再現できない）。

## Consequences

- 新規8テーブル。既存テーブルのスキーマ変更なし（危険なマイグレーションなし）。
- `ShowWatchlistAction` の `in_rakuten_favorites` 判定が `source` 基準から `last_seen_in_csv_at` 基準に変わる。CSV取込経路の既存行は全て `last_seen_in_csv_at` を持つため、既存の表示は変わらない想定（Gate 4で回帰テストを置く）。
- `audit` チャンネルの新設が実装に含まれる。
- 情報源固有の自動取得は本設計の範囲外で、4週試行後の再精査（2026-11-02以降）の結果で追加設計する。結果次第で `research_discovery_links` の列を見直す可能性がある。

## Related

- ADR-0022（無料一次情報を軸にした発見経路）、ADR-0013（ウォッチリスト）
- `docs/product/use-cases.md` UC-016・UC-012、`docs/architecture/data-model.md`
