# ai-workflow.md — AI開発ワークフロー

> Claude Code / Codex の使い方ルールです。
> 関連ADR: `meta/adr/ADR-0004-ai-development-policy.md`

## 役割分担

| 担当 | 作業 |
|---|---|
| **人間のみ** | 業務理解、要件定義、use-cases承認、ADR作成、最終レビュー・マージ |
| **AIメイン** | コード生成、テスト生成、コードレビュー補助、リファクタリング提案 |
| **AI補助** | 設計相談、ドキュメント草案、バグ原因調査 |

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
   - マージ前に `/review` を実行する
```

サブエージェント構成・スキル実行タイミング・`@nizos/probity` 導入判断など詳細は `.claude/rules/30-testing.md` を参照。

### 効果的なプロンプト

`docs/ai-context/prompt-patterns.md` を参照。

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
