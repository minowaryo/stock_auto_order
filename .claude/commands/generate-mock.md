---
disable-model-invocation: true
---

# /generate-mock — モック生成コマンド

引数で指定したUC番号のHTMLモックを生成して `docs/product/mockups/` に保存してください。

## 実行前に読み込むファイル

- `docs/product/requirements.md`
- `docs/product/use-cases.md`
- `docs/product/ui-guidelines.md`
- `docs/product/mockups/README.md`

## 手順

1. `requirements.md` と `use-cases.md` を読み、指定されたUC番号の要件・業務背景を把握する
2. `ui-guidelines.md` のカラー・レイアウト・コンポーネント方針を確認する
3. 以下を含む静的HTMLモックを生成する
   - ページタイトル・ヘッダー（ナビゲーション含む）
   - 対象UCのメインコンテンツ（一覧/フォーム/詳細など画面種別に応じた構造）
   - 操作ボタン・アクション要素
   - ダミーデータ（実データ使用不可）
4. 画面名は `use-cases.md` のUCタイトルを英語の小文字・ハイフン区切りで使用する
   （例: UC-006「受注一覧」→ `order-list`）
5. `docs/product/mockups/screen-UC[番号]-[画面名].html` として保存する。UC番号はハイフンを
   除いた形にする（例: `screen-UC006-order-list.html`。この名前は `spec-lint.sh` がチェックする）
6. `docs/product/mockups/README.md` の画面一覧に追記する

## 制約

- 実データを使わない（ダミーデータのみ）
- インタラクションは不要（静的HTMLで可）
- **Gate 1通過後〜Gate 2承認前の間にのみ使用する**
- ビジネス側レビューのフィードバックは `use-cases.md` に反映してから Gate 2 承認へ進む

## 使用例

```
/generate-mock UC-006
```

→ `docs/product/mockups/screen-UC006-[画面名].html` を生成し、`mockups/README.md` の画面一覧に追記する
