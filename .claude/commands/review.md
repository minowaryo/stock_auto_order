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
- `review-score.sh` が失敗した場合は、通常レベルとして扱う（ベースブランチの設定は `.claude/rules/50-review.md` 参照）
- ブランチ上のコミット済みの変更と未コミットの変更の両方がスコアリング対象に含まれる——先にコミットしてもよい

続いて、Domain Boundaryチェックを**別コマンドとして**実行する（指摘があるとexit 1を返すため、`&&` で繋ぐと失敗したように見えてしまう）。

```bash
bash .claude/hooks/domain-boundary-check.sh
```

> 関連ADR: `meta/adr/ADR-0011-domain-boundary-contract.md`

- exit 1は「確認すべき指摘がある」という意味であり、「チェック自体が失敗した」わけではない——指摘事項は以下のチェックリストに持ち込んで確認する
- **まず `READ THESE FIRST` セクションから読む。** そこに挙げられたファイルはデータを変更しつつ手書きのロールチェックだけで保護しており、Policyを一切呼び出していない——オブジェクトレベルの認可チェック漏れが隠れやすい形である。各ファイルについて、すべての書き込みが**書き込み対象の当該レコードに対して**認可されているか（ネストした子レコードが実際に親に属しているかを含む）を確認する
- 各行は**パターンマッチであって断定ではない**。報告する前に `.claude/rules/10-laravel.md` のDomain Boundary契約と照らし合わせて確認する
- `db-access` / `eloquent-write` / `role-check` はこの契約への違反であり、分岐密度の指摘はControllerでビジネス判断をしている可能性があるメソッドを示すヒューリスティックである
- このチェックはプレーンなPHPで書かれたクロスエンティティな判断（識別可能なトークンがない）を検知できないため、クリーンな実行結果は証拠にはならない——読んで確認すること

## レビュー前に読むファイル

- `docs/product/use-cases.md` — 実装が要件と一致しているか確認するため
- `docs/architecture/data-model.md` — DBスキーマ・マイグレーションの整合性確認のため
- `docs/product/mockups/` — UI実装がモックと一致しているか確認するため（存在する場合）

## レビュー対象

直近の変更ファイル（または指定されたファイル）

## チェックリスト

### 機能・設計
- [ ] `docs/product/use-cases.md` の要件と実装が一致しているか
- [ ] 各Controllerが `.claude/rules/10-laravel.md` のDomain Boundary契約を満たしているか（`DB::` を呼んでいない、Eloquentの書き込みをしていない、ロールのインラインチェックをしていない、複数エンティティにまたがる判断をしていない）
- [ ] `domain-boundary-check.sh` の指摘事項はそれぞれ確認済みか、理由付きで却下されたか
- [ ] Policy / Gate を通しているか
- [ ] N+1クエリがないか

### セキュリティ（`.claude/rules/40-security.md` 参照）
- [ ] バリデーションが適切か
- [ ] secrets・PII がコードに含まれていないか
- [ ] ログに個人情報が出ていないか
- [ ] 特権操作・破壊的操作が固定最小スキーマで `audit` チャンネルに記録されているか（`.claude/rules/40-security.md`）

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
