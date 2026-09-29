---
name: grill-me
description: Gate 1前の docs/product/requirements.md の作成・更新のための一問一答インタビュー手法——曖昧なアクター・成功基準・エッジケース・非機能要件を、推測せず1つずつ表面化させる。requirements.md を新規作成する、または大幅に改訂するときに使う。mattpocock/skills の grill-me/grilling パターンを翻案したもの（出典は本文に記載）。Status: Trial（`meta/adr/ADR-0014-third-party-skill-adoption-trial.md` 参照）——やり取りの往復が増える摩擦が最も懸念される項目であり、要件が実際に曖昧な箇所にのみ使う。すべての行に対して使うものではない。
---

# Grill Me（要件を問い詰める）

[mattpocock/skills](https://github.com/mattpocock/skills) の `grill-me` / `grilling` パターンを
翻案し、本リポジトリのGate/UC用語に合わせて書き直したもの。

曖昧・部分的な要件を、`docs/product/requirements.md` が正確に記述できる形へと変えるための
インタビュー手法。実際に曖昧な部分にのみ使い、既に明確な要件にまで儀式的に適用しない。

## 適用範囲

- `docs/product/requirements.md` にのみ適用する。`docs/product/use-cases.md` やコードの
  作成には使わない——それは `.claude/rules/00-global.md` が定めるGate 1 → Gate 2の境界を
  越えることになる。

## 手順

1. この要件について、`docs/product/requirements.md` と `docs/original-docs/` に既にある
   内容を読む。
2. 未解決の問いを特定する: アクターは誰か、何をもって成功とするか、エッジケース、
   非機能要件（性能・データ量・コンプライアンス等）。
3. **1度に1つだけ**質問する——次の質問をする前に回答を待つ。長い質問リストを先に
   まとめて出さない。1つの不明点への回答の方が、質問の山への回答より答えやすい。
4. 回答が来るたびに、最後にまとめて編集するのではなく、その都度 `requirements.md` を
   差分更新する。
5. 未解決の問いがなくなるまで繰り返し、なくなったら明示的にそう伝える:
   「未解決の問いはありません——Gate 1レビューの準備ができています。」

## ガードレール

**仮定した回答を勝手に埋めて先に進めてはいけない。** 未回答の質問はGate 1レビューを
ブロックするものであり、静かに推測で埋めて回答済みであるかのように書いてはならない。
ここで推測することは、Gate 1がまさに検出しようとしている失敗モードそのものを、
より早い段階に、より見えにくい形で移動させただけである。

## Related
- `docs/product/requirements.md`
- `.claude/rules/00-global.md` — Gate 1の条件
- `docs/original-docs/` — 参照元となる一次資料（参照のみ可）
