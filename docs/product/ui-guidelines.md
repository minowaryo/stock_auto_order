# UI Guidelines

> このファイルはプロジェクト固有のUI/デザイン仕様を定義する。
> AIがモック生成・フロント実装を行う際は必ずこのファイルを参照すること。

---

## カラーパレット

個人利用の株式ポートフォリオ管理ツール。含み益/含み損・シグナル有無を色でひと目で判別できることを優先する。

| 用途 | カラーコード | 備考 |
|---|---|---|
| Primary | `#2563EB` | メインアクション・ボタン・ナビゲーション選択状態 |
| Secondary | `#64748B` | サブアクション |
| Danger | `#DC2626` | 含み損・削除・エラー |
| Warning | `#D97706` | 利確シグナル・セクター偏り警告 |
| Success | `#16A34A` | 含み益・取込成功 |
| Background | `#F8FAFC` | ページ背景 |
| Surface | `#FFFFFF` | カード・パネル背景 |
| Text primary | `#0F172A` | 本文 |
| Text secondary | `#64748B` | 補足テキスト |

---

## タイポグラフィ

| 用途 | フォント | サイズ | ウェイト |
|---|---|---|---|
| 見出し H1 | system-ui, "Segoe UI", sans-serif | 24px | Bold |
| 見出し H2 | system-ui, "Segoe UI", sans-serif | 18px | SemiBold |
| 本文 | system-ui, "Segoe UI", sans-serif | 14px | Regular |
| ラベル | system-ui, "Segoe UI", sans-serif | 13px | Medium |
| キャプション | system-ui, "Segoe UI", sans-serif | 12px | Regular |
| 数値（指標・金額） | system-ui, "Segoe UI", sans-serif（tabular-nums指定） | 本文と同サイズ | Medium |

---

## レイアウト原則

- **グリッド**: 8px基準グリッド（個人利用の管理画面のため厳密な12カラムは採用しない）
- **利用環境**: 本人1人がPCブラウザから利用する想定。デスクトップ表示を優先し、モバイル対応は必須としない
- **ブレークポイント**:
  - Mobile: `< 768px`（レイアウト崩れ防止の最低限対応のみ）
  - Tablet: `768px〜1024px`
  - Desktop: `> 1024px`（主要ターゲット）
- **コンテナ最大幅**: `1200px`
- **標準余白**: `8px / 16px / 24px / 32px`

---

## コンポーネント方針

### ボタン
- Primary: メインアクション（1画面に1つまで）
- Secondary: サブアクション
- Danger: 削除・取り消し（確認ダイアログを必ず挟む）
- Ghost: ナビゲーション系

### モーダル
- 利用シーン: 確認ダイアログ・簡易フォーム入力
- 全画面遷移が必要な複雑操作にはモーダルを使わない
- モーダル内にモーダルを重ねない

### テーブル
- ページネーション: [例: 20件/ページ]
- ソート可能カラムには矢印アイコンを表示
- 空状態（0件）には必ずメッセージを表示

### フォーム
- バリデーションエラーはフィールド直下にインライン表示
- 必須項目には `*` マークを付ける
- Submit後の成功/失敗はトースト通知で伝える

---

## アイコン

- ライブラリ: Heroicons（Livewire/Tailwindエコシステムとの親和性が高いため採用）
- サイズ規則: 16px（インライン） / 20px（ボタン） / 24px（ナビ）

---

## モック作成ルール（AI向け）

AIがモックを生成する際の指示：

1. このファイル（`ui-guidelines.md`）を読んでからHTMLを生成する
2. 対応するUC番号をファイル名に含める（例: `screen-UC006-filter.html`）
3. `docs/product/mockups/` に配置する
4. `docs/product/mockups/README.md` の画面一覧に追記する
5. 実データは使わず、ダミーデータで描画する
6. インタラクションは不要（静的HTMLで可）

### モック生成プロンプトテンプレート

```
docs/product/use-cases.md の [UC-XXX] と docs/product/ui-guidelines.md を読み、
[画面名]のHTMLモックを docs/product/mockups/screen-[UC-XXX]-[screen-name].html として生成してください。
実データは不要です。ダミーデータを使用してください。
```
