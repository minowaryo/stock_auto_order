# ADR-0005: フロントエンド技術選定方針（PHP/Laravel エコシステム内での選定フレームワーク）

## Status
Accepted

## Date
2026-04-12

## Context

このリポジトリは複数の Laravel プロジェクトで再利用されるテンプレートである。
プロジェクトごとにチームのスキルセット・UI要求のリッチネス・API再利用の必要性（モバイルアプリ連携等）が異なるため、
フロントエンド技術を単一のスタックに固定すると、プロジェクト特性に合わない選択を強いることになる。

そのため、全プロジェクト共通の固定スタックを決めるのではなく、**プロジェクト開始時（Gate 0）に PHP/Laravel の技術範囲内で
そのプロジェクトに最適なフロントエンド技術を選定できる柔軟性**を持たせる方針とする。

検討対象となる選択肢は以下の通り:

1. **Blade**（Laravel 標準テンプレート）
2. **Livewire**（サーバー駆動のリアクティブ UI）
3. **Vue 3 + Inertia.js + Pinia**（サーバー駆動・API レス）
4. **React + Inertia.js**
5. **Vue 3 / React SPA + Laravel API**（API 分離）

## Decision

**全プロジェクトで単一のフロントエンドスタックに固定しない。**
プロジェクト開始時に、下記の選定基準・比較表をもとに PHP/Laravel の技術範囲内から選択し、
選定結果と理由をそのプロジェクト自身の ADR（例: `docs/adr/ADR-XXXX-frontend-stack-selection.md`）に記録する
（`.claude/rules/60-docs.md` の「フロントエンド技術選定（ライブラリ変更等）」に対応）。

特別な理由がない場合のデフォルト推奨は **Vue 3 + Inertia.js + Pinia** とする（理由は Rationale を参照）。

### 選択肢の比較

| 選択肢 | 適する場面 | 注意点 |
|---|---|---|
| Blade | シンプルな CRUD 中心、SPA的な操作性が不要、最速で立ち上げたい、チームが JS に不慣れ | リッチな UI/UX には不向き |
| Livewire | リアクティブな UI が欲しいが JS フロントエンド専任者を置きたくない/最小限にしたい | 大規模・複雑なクライアント側状態管理には不向き |
| **Vue 3 + Inertia.js + Pinia**（デフォルト推奨） | SPA的な UX が必要、かつ REST API を別途設計・運用するコストを避けたい | Vue Router 不使用などInertia特有の制約がある（`.claude/rules/15-frontend.md`） |
| React + Inertia.js | チームに React 経験者が多い、既存の React 資産・デザインシステムを流用したい | `.claude/rules/15-frontend.md` の内容を React 用に書き換える必要がある（Vue用の記述はそのまま使えない） |
| Vue/React SPA + Laravel API | モバイルアプリ・外部パートナー連携等で API を独立させる必要がある、フロントエンドとバックエンドを別チーム/別リポジトリで運用する | 認証・CORS・API バージョニング等、Inertia 構成より運用負荷が高い |

### 選定基準

プロジェクト開始時に以下を確認し、比較表と照らし合わせて選定する。

- チームのスキルセット（JS 経験の有無、Vue/React どちらの経験があるか）
- UI 要求のリッチネス（SPA のようなインタラクティブ性が必須か）
- API 再利用の必要性（モバイルアプリ・外部パートナー連携で同一 API を使い回すか）
- 開発速度・保守コスト（フロントエンド専任者の有無、チーム規模）
- 認証・認可を Laravel 側（Sanctum / Policy）に集約したいか（Inertia 構成であれば容易）

### 選定プロセス

選定はGate 0の必須ステップ（`CLAUDE.md` の Gate 0 Step 1a/1b/1c）として実施する。概要:

1. **選定**: 上記選定基準に基づきフロントエンド技術を決定し、`/adr` コマンドで
   `docs/adr/ADR-XXXX-frontend-stack-selection.md` として記録する
   （デフォルト推奨〔Vue 3 + Inertia.js + Pinia〕以外を選ぶ場合、または複数候補で迷った場合は理由・却下案を明記する）
2. **ルールファイル反映**: `.claude/rules/15-frontend.md` の内容を選定結果に合わせて書き換える
   （Vue 3 + Inertia.js + Pinia を選定した場合はデフォルト内容のまま利用可）
3. **ai-context反映**: 選定結果を `docs/ai-context/project-summary.md` の技術スタック表、
   `docs/ai-context/module-map.md` の Frontend セクションに反映する

詳細な手順は `CLAUDE.md` の Gate 0 Step 1 を参照。

## Rationale（デフォルト推奨: Vue 3 + Inertia.js + Pinia）

### Inertia.js を選んだ理由
- Laravel の Controller・Route・Middleware をそのまま活用でき、REST API を別途設計・維持するコストが不要
- 認証・認可（Sanctum / Policy）を Laravel 側に集約できる
- チーム規模・保守コストの観点から SPA（API 分離）より運用負荷が低い

### Vue 3 を選んだ理由
- Composition API + `<script setup>` により、ロジックの再利用性と TypeScript 親和性が高い
- Laravel コミュニティでの採用実績が豊富（Laravel Breeze の Vue オプション等）
- React と比較してテンプレート構文が Laravel Blade に近く、学習コストが低い

### Pinia を選んだ理由
- Vue 3 公式推奨の状態管理ライブラリ
- Vuex と比較して型安全でボイラープレートが少ない

## Consequences

- 各プロジェクトは、選定したフロントエンド技術を `docs/ai-context/project-summary.md` に明記する
- `.claude/rules/15-frontend.md` はどのスタックを選定してもファイル名・参照パスが変わらない「正本」として扱う。
  Vue 3 + Inertia.js + Pinia を選定したプロジェクトはデフォルト内容（`resources/js/` 配下の構成、Vue Router 不使用等）にそのまま従う
- Vue 以外（Blade / Livewire / React / SPA）を選定したプロジェクトは、`.claude/rules/15-frontend.md` の内容を
  選定したスタック用に書き換える（`CLAUDE.md` 等の参照表はファイル名が変わらないため更新不要）
- デフォルト推奨（Vue 3 + Inertia.js + Pinia）を選んだ場合でも、選定を明示的に確認した記録として
  プロジェクト側の `docs/ai-context/project-summary.md` に選定理由を一言残すことを推奨する
