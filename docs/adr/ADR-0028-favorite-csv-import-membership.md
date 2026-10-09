# ADR-0028: お気に入りCSVの直近取込への在籍を取込単位で判定する

## Status

Accepted（2026-10-05、本人が追加Gate 3草案と追加Redテスト11件を承認。UC-012のGate 2本文は承認済み）

## Date

2026-10-05

## Context

UC-012の`in_rakuten_favorites`は「直近のお気に入りCSVに含まれたか」を表す。現行の`ShowWatchlistAction`は`last_seen_in_csv_at IS NOT NULL`で判定するため、一度でもCSVに載った銘柄は、その後の成功した取込から消えても在籍中と表示される。このため「楽天お気に入り解除済」バッジも表示されない。`last_seen_in_csv_at`は過去のCSV経路を表す値として必要であり、消去できない（ADR-0025 D5）。

MySQLの`timestamp`はこの列では秒精度であり、短時間の連続取込を時刻の等値比較で区別できない。対象銘柄行が0件のCSVはUC-012に従い取込エラーなので、失敗した取込で直近の在籍を更新してはならない。

## Decision

### D1 成功した取込を識別する

`favorite_csv_import_batches`を追加する。`id`（bigint PK）、`imported_at`（timestamp）、`registered_count`（unsigned int）、`created_at`・`updated_at`を持つ。各成功取込を1行で表す。`favorite_csv_import_states`には主キー`id=1`の単一行とnullableな`latest_batch_id`（FK）を置き、マイグレーションで空の状態行を作る。`watchlist_items`にはnullableな`last_seen_favorite_import_id`を追加し、このバッチへの外部キーと索引を付ける。既存の`last_seen_in_csv_at`、`source`、`folder_name`は残す。

CSVの検証とパースが成功した後、状態行を`lockForUpdate`して取込を直列化する。同じトランザクションでバッチ作成、対象行のupsert、`latest_batch_id`更新を確定する。失敗したパース・DB書込はバッチと各行・状態のいずれも更新しない。各対象行の`last_seen_favorite_import_id`だけを今回のIDに更新し、今回にない行は削除も更新もしない。連続取込が同じ秒に完了してもIDで判定する。

### D2 現在の在籍と登録経路を分ける

最新の成功バッチがある場合、`in_rakuten_favorites`は各行の`last_seen_favorite_import_id === favorite_csv_import_states.latest_batch_id`で判定する。一度でもCSVに載った経路の判定は引き続き`last_seen_in_csv_at IS NOT NULL`を使う。従って直近CSVから消えた行は、`registration_routes`に`CSV`を残したまま「楽天お気に入り解除済」と表示する。調査候補経由だけでCSV実績がない行には、解除済みバッジを出さない。

### D3 既存データの扱い

過去の取込はバッチIDを持たない。マイグレーション時に在籍を推測して埋め戻さない。移行後の最初の成功取込までは従来の`last_seen_in_csv_at IS NOT NULL`を暫定表示に用いる。最初の成功取込以降はバッチIDに切り替わり、古いCSV行の直近在籍を正しく判定する。対象銘柄行が0件のCSVは現行UC-012どおり失敗し、前回の在籍が続く。

### D4 照合時点

UC-016の受け渡しに表示・保存するCSV照合時点は、バッチ導入後は最新成功バッチの`imported_at`を使う。導入前は従来の`last_seen_in_csv_at`の最大値を暫定使用する。バッチの作成と対象行の反映は同一トランザクションなので、受け渡しが失敗取込の時刻を参照しない。

## Rationale

取込IDなら時刻精度やタイムゾーン、同じ秒の再取込に依存せず、失敗取込と成功取込も分離できる。過去のCSV経路は保持し、既存行・★・メモ・調査経路は削除しない。

### 採用しなかった代替案

- **`last_seen_in_csv_at`を最大時刻と比較**: 秒精度で同秒の取込が区別できず、取込件数が0のケースも表せない。
- **今回のCSVにない行の`last_seen_in_csv_at`をnullにする**: 過去のCSV経路を失い、調査候補との両経路表示が壊れる。
- **既存行を物理削除する**: UC-012の追加のみの取込・履歴保全に反する。

## Consequences

- 追加のみのマイグレーションが必要。既存カラムの変更・削除は行わない。ロールバックは新しい外部キーとバッチ表を落とせるが、移行後の直近在籍履歴は失われるため、本番での実行前にはバックアップと復旧手順を確認する。
- 過去データは最初の成功取込まで暫定表示になる。成功取込後、今回CSVにない既存行の表示が変わる。これはUC-012で承認済みの出力定義に合わせるユーザー向け変更であり、適用前に本ADRとRedテストの承認を得る。
- 同時に複数CSVを投入する場合は、状態行のロック取得順に直列化する。全取込で「状態行→バッチ→銘柄行」の順にアクセスし、長時間トランザクションとデッドロックを避ける。Gate 4では同秒の連続取込を検証し、実装レビューで同時実行の境界も確認する。

## Related

- [ADR-0013](ADR-0013-favorites-watchlist.md)、[ADR-0025](ADR-0025-research-candidate-storage.md) D5（直近在籍判定を本ADRで補完）
- [UC-012／UC-016](../product/use-cases.md)、[data-model.md](../architecture/data-model.md)
