# /review — コードレビューコマンド

以下の観点でコードレビューを実施してください。

## Step 0: レビュー強度の判定（review-score）

> 関連ADR: `meta/adr/ADR-0009-review-escalation-mechanism.md`

レビュー内容に着手する前に、必ず以下を実行してスコアを確認する。

```bash
bash .claude/hooks/review-score.sh
```

- 出力末尾の `RECOMMENDATION=normal` の場合 → 通常レベルでレビューする（以下のチェックリストを1パスで確認）
- 出力末尾の `RECOMMENDATION=enhanced` の場合 → 強化レベルでレビューする。以下のチェックリストに加えて、検出した各指摘事項（HIGH/MEDIUM）を「本当にリスクか？見落としている前提はないか？」という視点でもう一度懐疑的に見直すadversarialな再確認パスを追加する
- `review-score.sh` が失敗する、または `main` ブランチが存在しない環境では、通常レベルとして扱ってよい

## レビュー前に読むファイル

- `docs/product/use-cases.md` — 実装が要件と一致しているか確認するため
- `docs/architecture/data-model.md` — DBスキーマ・マイグレーションの整合性確認のため
- `docs/product/mockups/` — UI実装がモックと一致しているか確認するため（存在する場合）

## レビュー対象

直近の変更ファイル（または指定されたファイル）

## チェックリスト

### 機能・設計
- [ ] `docs/product/use-cases.md` の要件と実装が一致しているか
- [ ] Fat Controller になっていないか
- [ ] Policy / Gate を通しているか
- [ ] N+1クエリがないか

### セキュリティ（`.claude/rules/40-security.md` 参照）
- [ ] バリデーションが適切か
- [ ] secrets・PII がコードに含まれていないか
- [ ] ログに個人情報が出ていないか

### テスト（`.claude/rules/30-testing.md` 参照）
- [ ] Feature Testが追加されているか
- [ ] 正常系・異常系・認可のテストがあるか
- [ ] 新規データモデル（migration）が追加されている場合、use-cases.md上で提供が定義されている作成・編集・削除の操作がFeature Testで一貫して網羅されているか（`docs/architecture/data-model.md` のモデル一覧と突き合わせる。未定義の操作を理由に追加実装・追加テストを求めない）

### ドキュメント（`.claude/rules/60-docs.md` 参照）
- [ ] 設計変更が docs に反映されているか
- [ ] ADRが必要な判断をしていないか

## アウトプット形式

1. **総評**（1〜2文）
2. **問題点**（重大度: HIGH / MEDIUM / LOW）
3. **推奨する修正**
4. **追加すべきテスト**
