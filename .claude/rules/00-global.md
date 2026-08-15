# 00-global.md — 全体方針

## 前提：このファイルを読んだら確認すること

以下が埋まっているか確認する。埋まっていなければ作業を止めてユーザーに通知する。

- [ ] `docs/ai-context/project-summary.md` が記入済みか
- [ ] `docs/ai-context/glossary.md` が記入済みか
- [ ] `docs/adr/` にフロントエンド技術選定のADRが存在するか（`meta/adr/ADR-0005-frontend-stack.md` の選定プロセスを経ているか）
- [ ] `docs/product/use-cases.md` の承認状況はどうか

---

## 開発ワークフロー

必ずこの順序で進める:

```
Explore → Plan → Implement → Test
```

1. **Explore**: `docs/ai-context/` と関連ドキュメントを読み、現状を理解する
2. **Plan**: 変更の影響範囲・リスクを整理し、実装方針をユーザーに提示して合意を得る
3. **Implement**: 合意した計画に従って実装する（スコープ外に触れない）
4. **Test**: Feature Testを追加・実行して動作を確認する

---

## AI開発パイプライン

> パイプライン全体図（ドキュメント作成 → Gate承認 → TDD実装）は `CLAUDE.md` の Step 1〜4 を参照（このファイルでの図の重複記載はしない。図を変更する場合は `CLAUDE.md` 側のみ更新する）。
> Gate 0〜4 それぞれの条件・解禁内容は次の「品質ゲート詳細」表を参照。
> フェーズごとの手順・スキル実行タイミングの詳細は `.claude/rules/30-testing.md` を参照。
> UAT（任意・非ブロッキング）の位置づけは次節を参照。

---

## UAT（受け入れテスト・任意）

- `docs/product/uat-scenarios.md` はマージ後、AIによる叩き台生成可
- 実機動作確認・最終確認は人間（レビュアー・ビジネス側）が行い、結果を `docs/product/uat-results/` に記録する（この最終確認自体は必須の作業だが、位置づけとしてはGate化しない）
- **UATはGate 0〜4のいずれにも属さない独立ステップ**であり、他機能・他UCのGate 0〜4パイプラインやTDDサイクルの進行をブロックしない
- UAT未実施・未承認を理由に、後続の開発作業（別機能の実装・次のTDDサイクル等）を止める必要はない

---

## 品質ゲート詳細

| Gate | 条件 | 解禁されること |
|---|---|---|
| Gate 0 | `docs/ai-context/` の必須ファイルが記入済み、かつフロントエンド技術選定ADRが作成済み | AI支援の開始 |
| Gate 1 | `docs/product/requirements.md` レビュアー承認済み | use-cases.md 叩き台生成・モック生成 |
| Gate 2 ★ | `docs/product/use-cases.md` レビュアー最終承認済み | コード生成・acceptance-criteria / data-model 叩き台生成 |
| Gate 3 | `docs/architecture/data-model.md` レビュアー承認済み | DB実装・マイグレーション作成 |
| Gate 4 | TDD Redフェーズのテストケース（Feature/Unit）がレビュアー承認済み | 実装（Greenフェーズ）着手 |

> **Gateの性質の違い**: Gate 0〜3 はプロジェクト単位で1度だけ通過するドキュメント承認ゲート。Gate 4 は機能・UC単位で、TDDサイクル（`/tdd`）を回すたびに繰り返す実装ゲート。
> **モックレビューはGate番号を持たない**: `docs/product/mockups/` のビジネス側レビューは独立したGateではなく、Gate 2（use-cases.md最終承認）の前提条件として扱う（フィードバックをuse-cases.mdに反映してからGate 2承認を行う設計）。

---

## 絶対禁止

- Gate 2 通過前のコード生成
- Gate 4 通過前の実装（Greenフェーズ）着手（テストケース承認前の実装コード生成）
- コード先行（ドキュメント未確認での実装）
- 大規模変更の一括コミット
- テストなし実装（バグ修正には再発防止テストを必ず追加）
- スコープ外ファイルの編集
- secrets・本番資格情報をプロンプトに含める
- `docs/original-docs/` の編集・削除・ファイル新規作成（参照のみ可）

---

## 変更時の確認リスト

- [ ] 関連ADRを確認したか
- [ ] use-cases.md に変更の影響はないか
- [ ] Gate 4（テストケース承認）を得てから実装（Green）に着手したか
- [ ] CR（Change Request）の場合、traceability-matrix.md を更新したか
- [ ] マイグレーション計画はあるか（DB変更の場合）
- [ ] テストを追加したか
- [ ] ドキュメントを更新したか
