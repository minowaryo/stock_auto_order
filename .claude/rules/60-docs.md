# 60-docs.md — ドキュメント更新ルール

## 変更とドキュメントの対応表

| 変更内容 | 更新すべきドキュメント |
|---|---|
| DB スキーマ変更 | `docs/architecture/data-model.md` |
| API 追加・変更 | `docs/architecture/overview.md` |
| 認証認可変更 | `docs/architecture/authz-authn.md` |
| アーキテクチャ判断 | `docs/adr/ADR-XXXX-xxx.md`（新規作成） |
| コーディング規約変更 | `docs/development/coding-standards.md` |
| テスト方針変更 | `docs/development/testing-strategy.md` |
| 新しい共通コマンド | `docs/ai-context/common-commands.md` |
| 新ドメイン・モジュール追加 | `docs/ai-context/module-map.md` |
| 用語追加 | `docs/ai-context/glossary.md` |
| 触ってはいけない領域の変更 | `docs/ai-context/do-not-touch.md` |
| UI/デザイン仕様変更 | `docs/product/ui-guidelines.md` |
| モック追加・更新 | `docs/product/mockups/README.md`（画面一覧を更新） |
| UCへのモックフィードバック反映 | `docs/product/use-cases.md` + `docs/product/mockups/README.md` |
| フロントエンド画面・コンポーネント追加 | `docs/ai-context/module-map.md` |
| 状態管理（Pinia/Vuex/Redux等、選定したスタックのStore）の追加・変更 | `docs/architecture/overview.md` |
| フロントエンド技術選定（ライブラリ変更等） | `docs/adr/ADR-XXXX-xxx.md`（新規作成） |
| 権限・ロールのビジネス方針変更 | `docs/product/org-permission-philosophy.md` + `docs/architecture/authz-authn.md` |
| ユーザー向け機能・操作方法の変更 | `docs/product/user-guide.md` |
| UATシナリオ・結果の追加（任意） | `docs/product/uat-scenarios.md` / `docs/product/uat-results/`（`.claude/rules/00-global.md` のUAT節を参照。非ブロッキング） |
| ライブラリ/フレームワーク固有のハマりどころを解決した | `docs/ai-context/known-pitfalls.md`（常時読込ではないため、コード変更と同一PRである必要はない。解決した都度追記） |
| Gate条件・品質ゲート運用の変更 | `.claude/rules/00-global.md`（詳細表・絶対禁止）+ `CLAUDE.md`（Step手順）+ `AGENTS.md`（Codex用。Gate定義を複製しているため3ファイル同期が必要） |

## ドキュメント更新の原則

1. **コード変更と同じPRでドキュメントも更新する**
2. 仕様変更はドキュメント先行（コード前に文書化）
3. ADRは「なぜそう決めたか」を必ず書く（Whatだけでなく Why）
4. `docs/ai-context/` は短く・正確に保つ（AIが読む要約層）

## ADRを書くべきタイミング

以下の判断をしたときは必ずADRを作成する:

- 新しいライブラリ・フレームワークの採用
- 既存ライブラリの変更・廃止
- アーキテクチャパターンの変更
- セキュリティ方針の変更
- DBスキーマの大規模変更
- AI開発ポリシーの変更

## PLAN.md 肥大化防止ルール（アーカイブ運用）

`PLAN.md`はセッションをまたいで参照する現在進行中のタスク台帳であり、無制限に追記し続けると1ファイルが肥大化し逆に参照性が落ちる。以下のルールで一定サイズ以内に保つ。

- **上限**: `PLAN.md`は**300行を超えないようにする**（250行を超えた時点でアーカイブ実施を検討する目安とする）
- **アーカイブ先**: `docs/history/plan-archive.md`（プロジェクト内に存在しない場合は新規作成する）
- **退避対象の選び方**: `PLAN.md`は新しいエントリを先頭に追記する運用のため、**ファイル末尾（最も古い）のエントリから**、Statusが「完了」相当（例: 完了・Green確認完了・マージ済み・実装済み等、後続作業がぶら下がっていない状態）のものを退避する。ユーザーの承認待ち・作業中・次のアクションが明記されているエントリは残す
- **手順**:
  1. 対象エントリ（`##`見出し単位、Decision/Files touched/Statusの3節セット）を丸ごと`docs/history/plan-archive.md`に移す。アーカイブ側も新しい順（＝`PLAN.md`から外れた直後のものが先頭）に並べる
  2. アーカイブファイル冒頭の説明文（何〜何までの記録か）を更新する
  3. `PLAN.md`冒頭の「アーカイブ済み」注記（範囲・日付）を更新する
  4. エントリ本文・ファイルパス等は要約・省略せず原文のまま移す（後で経緯を追えなくなるため）
- **このルール自体の位置づけ**: `PLAN.md`本文には手順を書かず、本ファイル（`.claude/rules/60-docs.md`）を正本とする。`PLAN.md`側は「300行を超えないよう保つ」旨と本ファイルへの参照のみ記載する

## ADRテンプレート

`docs/adr/ADR-XXXX-[title].md` として作成:

```markdown
# ADR-XXXX: [タイトル]

## Status
[Proposed / Accepted / Deprecated / Superseded by ADR-XXXX]

## Date
YYYY-MM-DD

## Context
[なぜこの判断が必要になったか]

## Decision
[何を決めたか]

## Rationale
[なぜそう決めたか・採用しなかった代替案]

## Consequences
[この決定による影響・トレードオフ]
```
