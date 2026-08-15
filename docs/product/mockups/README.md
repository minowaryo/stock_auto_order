# Mockups

モックファイルの一覧と、対応するユースケース番号を管理する。

## ファイル命名規則

```
screen-[UC番号]-[画面名（英小文字ハイフン区切り）].[html|png|md]
```

例:
- `screen-UC006-order-list.html`
- `screen-UC007-order-detail.html`
- `screen-UC007-order-detail.png` + `screen-UC007-order-detail.md`（画像の場合は補足mdをセットで置く）

## 画面一覧

| ファイル名 | 対応UC | 画面名 | 作成日 | ビジネスレビュー | フィードバック反映 |
|---|---|---|---|---|---|
| `screen-UC001-csv-import.html` | UC-001 | CSV取込 | 2026-08-15 | 未実施 | - |
| `screen-UC002-holding-list.html` | UC-002（UC-007の市場全体指標ウィジェットを含む） | 保有銘柄一覧 | 2026-08-15 | 未実施 | - |
| `screen-UC003-holding-detail.html` | UC-003 | 銘柄詳細 | 2026-08-15 | 未実施 | - |
| `screen-UC004-signal-list.html` | UC-004 | 利確シグナル一覧 | 2026-08-15 | 未実施 | - |
| `screen-UC005-sector-dashboard.html` | UC-005 | セクター配分ダッシュボード | 2026-08-15 | 未実施 | - |
| `screen-UC006-candidate-check.html` | UC-006 | 新規投資候補の重複チェック | 2026-08-15 | 未実施 | - |

> UC-007（市場全体指標表示）は独立画面を持たず、`screen-UC002-holding-list.html` 上部のウィジェットとして実装する（`use-cases.md` UC-007参照）。
> 全画面共通のスタイルは `_shared.css`（`docs/product/ui-guidelines.md` 準拠）を参照している。

## 運用ルール

- モックは **Gate 1通過後〜Gate 2の間** に作成する（Gate 3を待たない）
- ビジネス側レビューのフィードバックは `docs/product/use-cases.md` に反映する
- フィードバック反映後に Gate 2（use-cases.md 最終承認）へ進む
- 画像モック（PNG等）は必ず補足mdをセットで置く（AIへの文脈伝達のため）

## 補足mdの記載内容（画像モックの場合）

```markdown
# [画面名] モック補足

## 対応UC
- UC-XXX: [UCタイトル]

## 注意点・設計意図
- [AIに伝えておくべき点を箇条書き]

## ビジネス側フィードバック（レビュー済み）
- [フィードバック内容と対応方針]
```
