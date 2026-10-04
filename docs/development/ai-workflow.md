# ai-workflow.md — AI開発ワークフロー

> Claude Code / Codex の使い方ルールです。
> 関連ADR: `meta/adr/ADR-0004-ai-development-policy.md`

## 役割分担

以下は新規プロジェクト（`SETUP.md` Step 1〜4）を前提とした分担である。既にコードが存在するプロジェクトへの
導入については、下記の「既存コードベース導入」を参照——「AIがドラフト・生成し、人間が判断・承認する」という
全体構造は同じで、異なるのは**人間が何について判断するか**である。

### 新規プロジェクト（グリーンフィールド）

| 担当 | 作業 |
|---|---|
| **人間のみ** | 業務理解、要件定義、use-cases承認、ADR作成、最終レビュー・マージ、コミットとpushの承認 |
| **AIメイン** | コード生成、テスト生成、コードレビュー補助、リファクタリング提案、コミット分割とマージメッセージの提案（`docs/development/git-workflow.md`） |
| **AI補助** | 設計相談、ドキュメント草案、バグ原因調査 |

### 既存コードベース導入

テンプレートリポジトリの `SETUP.md`（既存コードベース導入パス）および `ADR-0011-existing-codebase-adoption` を参照（いずれも本プロジェクトには未取り込み）。

| 担当 | 作業 |
|---|---|
| **AI** | 実際のスタック（フロントエンド/バックエンド/DB/認証）の検出、`.claude/hooks/domain-boundary-check.sh --audit-all` の実行、`docs/ai-context/*`・`use-cases.md`（as-is）・`data-model.md` のドラフト、「要確認」リストとBacklogリストの作成 |
| **人間** | 「要確認」リストの解消（用語の意味、コードと旧ドキュメントの食い違いの裁定、新規コードにどのルールを適用するか）、「適用したデフォルト」リストの確認（例: do-not-touch境界）、Gate 0〜3の統合承認、最終レビュー・マージ（グリーンフィールドの場合と変わらない） |

**共通点**: どちらの場合も、人間の承認前（Gate 2、または既存コードベース導入の統合チェックポイント）にAIが
実装コードを生成することはなく、最終レビュー・マージは常に人間が行う。
**相違点**: グリーンフィールドでは人間の役割は白紙からの**著述**、既存コードベース導入では人間の役割は
AIがドラフトした内容を実システムと突き合わせる**検証・判断**であり、著述作業を軽くしたものではない。

## Claude Code の使い方

### 推奨ワークフロー

```
1. Explore（探索）
   - 関連ファイルを読む
   - 既存実装・パターンを理解する

2. Plan（計画）
   - 変更の影響範囲を整理
   - 実装方針を提示・合意を得る

3. Implement（実装、`/tdd` コマンド）
   - Red → Gate 4（テストケース承認） → Green → Refactor のサイクルで進める
   - 計画外のスコープに脱線しない

4. Test（テスト・検証）
   - テスト実行に加え `run` スキルで実挙動を確認する（テストGreen＝機能OKとは限らないため）
   - マージ前チェックで求められた場合はマージ前に `/review` を実行する（`docs/development/git-workflow.md` §6 マージ前チェック）
```

サブエージェント構成・スキル実行タイミング・`@nizos/probity` 導入判断など詳細は `.claude/rules/30-testing.md` を参照。

### Claude Code に読ませる文脈

> 正式な一覧は `CLAUDE.md` の「Read first」「Read when relevant」を参照（本節はその要約であり、内容が食い違う場合は `CLAUDE.md` を正とする）。

**毎回（必須）:**
- `docs/ai-context/project-summary.md`
- `docs/ai-context/glossary.md`
- `docs/ai-context/module-map.md`

**タスクに応じて:**
- コマンド操作（テスト実行・マイグレーション等） → `docs/ai-context/common-commands.md`
- DB変更 → `docs/architecture/data-model.md`
- 認証変更 → `docs/architecture/authz-authn.md`
- アーキテクチャ変更 → `docs/adr/`

## Codex の使い方

### 推奨ユースケース
- 差分（diff）の自動生成
- コードレビューの自動化
- 繰り返しパターンの実装

### 必須読み込み（AGENTS.md 経由）
- `docs/ai-context/project-summary.md`
- 関連ADR
- タスク別ドキュメント

## 禁止事項

- use-cases.md 承認前のコード生成依頼
- AIが生成したコードのレビューなしマージ
- secrets・本番資格情報をプロンプトに含める
- AIの提案をそのまま採用（必ず人間がレビュー）

## 品質ゲート

AIが生成したコードは以下を満たすこと:
1. `php artisan test` が通る
2. `./vendor/bin/pint --test` がパス
3. `./vendor/bin/phpstan analyse` がパス
4. クリティカルフローに変更がある場合、`npx playwright test` が通る
5. `docs/development/review-checklist.md` のレビューが完了
