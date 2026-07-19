# glossary.md — 用語集

> プロジェクト固有の用語・略語を定義します。
> AIと人間の共通言語にするために正確に記載してください。

## ドメイン用語

| 用語 | 説明 | 別名 |
|---|---|---|
| Grab | 東南アジアで一般的な配車アプリ。本サービスは自社車両を持たず、Grabを標準の移動手段として案内する | - |
| アンヘレス | クラーク空港近郊のエリア。主力プラン「アンヘレス4泊5日」の拠点 | Angeles |
| クラーク | クラーク国際空港およびその周辺エリア | Clark |
| Zoom相談 | 旅行前に行うオンライン相談（有料）。事前アンケート回答をもとに実施 | オンラインコンサル |
| 事前アンケート | 相談申込時にユーザーが入力する旅行条件・好み・不安点等の項目群。データモデル上は `TravelProfile` | プレ・アンケート |
| 旅行プラン | 管理者が事前アンケートをもとに作成する旅程案。固定項目・柔軟項目・代替候補で構成される。データモデル上は `Trip` / `TripDay` | モデルプラン |
| 固定項目 | 旅行プランのうち事前に確定する項目（フライト・ホテル・空港送迎・必須アクティビティ等） | - |
| 柔軟項目 | 旅行プランのうち現地状況に応じて変更可能な項目（昼食・ショッピング・一部アクティビティ等） | - |
| ドライバー候補台帳 | Phase 1では一般公開・予約機能を持たない、管理者専用のドライバー候補管理台帳。将来のPhase 3機能化に向けた内部記録のみ | 簡易台帳 |
| Puning Hot Spring | クラーク／アンヘレス近郊の温泉アクティビティ。主力アクティビティ候補の一つ | Puning |
| Phase 1 / 2 / 3 | 一次資料が定義する開発段階。Phase 1=公開サイト・相談申込・管理画面中心のMVP、Phase 2=マイページ・決済・チャット・レビュー、Phase 3=ドライバーポータル・自動マッチング等 | - |

## 技術用語（プロジェクト固有の使い方）

| 用語 | このプロジェクトでの意味 |
|---|---|
| Action | 単一責務のビジネス操作クラス（`app/Actions/`） |
| Service | 複数Actionを束ねる上位サービスクラス（`app/Services/`） |
| ADR | Architecture Decision Record（意思決定記録） |
| Gate | Laravel認可の仕組み（Policy経由で呼ぶ） |
| RCID | Requirement Change ID（要件変更ID・トレーサビリティ用） |
| Inertia.js | Laravel とフロントフレームワークをサーバー駆動でつなぐアダプター。SPA ライクなUXを API 設計なしで実現する |
| Pinia | Vue 3 公式推奨の状態管理ライブラリ。Vuex の後継 |
| Composable | `use~` 命名の関数。Composition API のロジックを再利用可能な形で切り出したもの（`resources/js/Composables/`） |
| Page コンポーネント | Inertia のルートに対応する `Pages/` 配下の `.vue` ファイル。Controller の return で指定される |

## 略語

| 略語 | 正式名称 |
|---|---|
| BA | Business Analyst |
| ADR | Architecture Decision Record |
| PII | Personally Identifiable Information（個人識別情報） |
| ERD | Entity Relationship Diagram |
| MVP | Minimum Viable Product |

## ステータス定義

| ステータス | 意味 |
|---|---|
| [status1] | [定義] |
| [status2] | [定義] |
