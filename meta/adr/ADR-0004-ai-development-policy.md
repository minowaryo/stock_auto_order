# ADR-0004: AI駆動開発ポリシー

## Status
Accepted

## Date
[YYYY-MM-DD]

## Context

このプロジェクトでは Claude Code と Codex（またはその他AIコーディングツール）を併用する。
AIツールの利用方法・責任範囲・禁止事項を定めないと、品質・セキュリティ・設計の一貫性が損なわれる。

## Decision

以下のAI駆動開発ポリシーを採用する。

### AI担当領域

| 担当 | 作業 |
|---|---|
| AI | APIコード生成、Controller/Model/Migration生成、テスト生成、コードレビュー補助 |
| 人間 | 業務理解、use-casesレビュー・承認、設計判断、ADR作成、最終承認 |

### 開発ワークフロー

> 2026-07-15 更新: TDD運用の明文化（[[ADR-0007-tdd-enforcement-probity]] / [[ADR-0008-tdd-e2e-harness-tooling]]）に伴い、
> テスト生成をコード生成より先に行う順序に修正した（Gate 4: テストケース承認を追加）。

```
requirements.md（人間定義）
      ↓
use-cases.md（人間レビュー・承認 ← 品質ゲート）
      ↓
data-model.md / openapi.yaml
      ↓
AI テストケース生成（Red・/tdd コマンド）
      ↓
Gate 4: テストケース承認（人間レビュー）
      ↓
AI 実装コード生成（Green、Claude Code / Codex）
      ↓
Refactor
      ↓
人間レビュー・マージ
```

### AIへの指示ルール
- `CLAUDE.md` / `AGENTS.md` を入口にして文脈を与える
- 毎回全ドキュメントを読ませない（必要なものだけ）
- secrets・PII をAIプロンプトに含めない

### 禁止事項
- use-cases.md 承認前のAIへのコード生成依頼
- AIが生成したコードのレビューなしでのマージ
- AIにAPIキー・本番資格情報を渡すこと

## Rationale

**この方針の理由:**
- use-cases.md を品質ゲートにすることで、AIのコード生成品質が仕様に担保される
- AIルールを文書化することで、チーム全員がAIを同じ使い方できる
- secrets保護はAI時代に特に重要なリスク管理

### 採用しなかった代替案
- **AIなし開発**: 開発速度・コスト効率の観点から不採用
- **AI全自動（人間レビューなし）**: 品質・セキュリティリスクが高すぎる

## Consequences

### メリット
- 開発速度の向上
- コード品質の均一化
- テスト自動生成による品質担保

### デメリット・リスク
- use-cases.md の質がプロジェクト全体の品質を左右する
- AIの誤生成コードをレビューできるスキルが人間に必要

## Related
- `CLAUDE.md`
- `AGENTS.md`
- `.claude/rules/00-global.md`
- `docs/development/ai-workflow.md`
- ADR-0007-tdd-enforcement-probity
- ADR-0008-tdd-e2e-harness-tooling
