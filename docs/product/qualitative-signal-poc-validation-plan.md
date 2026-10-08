# CHG-0049 段階0: 定性判定のPoC検証計画

> 状態: **計画（2026-10-07、10-08改訂）。段階0への着手は本人承認済み、合否基準は未承認。事前確認1・2（公開データ）は実施済み（[結果記録](qualitative-signal-poc-results.md)）、本人のデータでの本検証は未実施。** 親文書: [CHG-0049 変更要求案](qualitative-signal-proposal.md)
> 目的: 日本語の開示に対して、AIの定性判定が「使える精度と較正」を持つかを測り、用途ごとにGO／NO-GOを決める。比べるのは、Claude Haiku 4.5（既定の候補）、判定専用モデルJev、AIを使わない代替（タイトルの正規表現＋XBRLの数値）の3つ。
> 前提: アプリの外で行う。アプリのコード・DB・Gateには触れない。別PCで実施する。ClaudeはClaude Codeのヘッドレス実行で行えばAPIキーは不要。Jevだけは、OpenRouterの登録と$1〜2程度のクレジットが必要（§4）。
> 合否の承認は、画面の変更の承認ではない（変更要求案 §3.2）。

## 1. 検証すること

| # | 問い | 判定に使う指標 |
|---|---|---|
| 1 | 日本語の開示で、投資仮説が崩れたかを見分けられるか（Q1） | ROC-AUC（下側信頼限界）、見逃し件数、実際の出現率での適合率 |
| 2 | 返ってくる確率は較正されているか（0.8と言えば8割当たるか） | Brier、ECE、信頼度曲線（両モデルに同じ補正をかけた後） |
| 3 | Choice・Scoreのconfidenceは、当たり外れの予測に役立つか（Noulにはconfidenceがないので対象外） | AUROC（confidence → 正解） |
| 4 | 問いと文書の言語の組み合わせで、精度はどう変わるか | 変種V1〜V3の比較 |
| 5 | AIを使わない代替より良いか。HaikuとJevのどちらが良いか | 同じ項目での対応のある比較（McNemar検定、クラスタ単位のブートストラップ） |
| 6 | 結果は安定しているか | 候補の並べ替え・再実行での一致率 |

## 2. 問いのセット（初版 v1）

共通の規則:

- **1つの問いでは1つの性質だけを聞く。** 肯定形で書く（「〜しないか」とは聞かない）。
- **P(否) = 1 − P(是)を前提にしない。** 「関係ない」は、仮説を支持も否定もしない。
- **Choiceの候補**: 互いに排他的で、短く、形をそろえる。必ず「その他」を入れる。**実行ごとに並び順を入れ替える**（Jevは先頭の候補に寄りやすいため）。
- **問いの管理**: 問いにはIDと版を付ける。文面を変えたら版を上げる。`{}`の部分だけをコードで埋め、それ以外は毎回同じ文面にする。
- **問いと文書の分離**: Jevでは文書を`state`、問いを`questions`に入れる。Haikuでは、問いをシステムプロンプト、文書を別のメッセージブロックに入れる。

### 合否を判定する問い

| ID | 型 | 英語の問い | 日本語の問い | 入力 | 出す条件 |
|---|---|---|---|---|---|
| Q1 THESIS_BREAK | Noul | Does the following information undermine this investment thesis (i.e., make it less likely to be true)? Thesis: {thesis} | 以下の情報は、次の投資仮説を損なう（仮説が正しい可能性を下げる）内容を含んでいますか？ 投資仮説：{thesis} | 仮説（約30〜60トークン）＋開示本文の冒頭1〜3ページ（約800〜2,500トークン） | 関連度Q5aが0.3以上のときだけ。**見逃しは、このふるいを含めた全体で数える** |
| Q10 COMPANY_SPECIFIC_DETERIORATION | Noul | Does this disclosure report a deterioration specific to this company's business or earnings (not a market-wide factor)? | この開示は、市場全体の要因ではなく、この企業固有の事業または業績の悪化を報告していますか？ | 開示本文（約800〜2,500トークン） | 買いシグナルが出た銘柄の、直近の開示。陽性が30件以上集まった場合だけ合否を判定し、足りなければ参考値とする |

### 参考値として測る問い（合否は判定しない）

件数が少なく、合否を判定できる統計的な強さがないため、記述統計だけを出す。

| ID | 型 | 英語の問い | 日本語の問い |
|---|---|---|---|
| Q6 ONE_OFF_PROFIT | Noul | Is the main driver of this profit increase a one-off factor (e.g., gains on sale of assets or securities, FX gains, extraordinary income, subsidies)? | 今回の増益の主な要因は、一時的な要因（固定資産・投資有価証券の売却益、為替差益、特別利益、補助金など）ですか？ |
| Q2a DISCLOSURE_TYPE | Choice（9候補） | Which best describes the type of this disclosure? — Upward revision of earnings forecast / Downward revision of earnings forecast / Earnings results with no forecast change / Dividend forecast change / Share buyback / Management change / M&A or capital/business alliance / Equity financing / Other | この開示の種類として最も適切なものはどれですか？ — 業績予想の上方修正／業績予想の下方修正／決算発表（業績予想の修正なし）／配当予想の修正／自己株式の取得／経営陣の異動／M&A・資本業務提携／資金調達（増資・新株予約権等）／その他 |
| Q2b MATERIALITY | Score 1〜5 | How material is this disclosure for the company's share price, on a scale from 1 (negligible) to 5 (very large)? | この開示が株価に与える影響の大きさを、1（ほぼ影響なし）〜5（非常に大きい）で評価してください。 |
| Q3 GROWTH_SLOWING | Noul | Does management's commentary in this earnings release suggest that demand or growth momentum is weakening? | この決算資料における経営陣の説明は、需要または成長の勢いが弱まっていることを示唆していますか？ |
| Q5a RELEVANCE | Noul | Is this information directly relevant to the business or earnings of {company}? | この情報は{company}の事業または業績に直接関係しますか？ |
| Q7 GUIDANCE_TONE | Score −2〜+2 | Rate the tone of management's outlook statements from -2 (very bearish) to +2 (very bullish). | 経営陣の今後の見通しに関する記述のトーンを、-2（非常に弱気）〜+2（非常に強気）で評価してください。 |
| Q8 MGMT_CHANGE | Noul | Does this disclosure announce an unplanned CEO/president change, or one caused by misconduct or poor performance? | この開示は、予定外の、または不祥事・業績不振に起因する社長・CEOの交代を発表していますか？ |
| Q9 GOVERNANCE_RED_FLAG | Noul | Does this disclosure report an accounting irregularity, a postponed earnings release, an auditor change or disclaimer, or a going-concern note? | この開示は、不適切な会計処理、決算発表の延期、監査人の異動・意見不表明、継続企業の前提に関する注記のいずれかを報告していますか？ |

**Q2aの注意**: 修正が上方か下方かは**数値の比較**なので、本運用ではXBRLの数値から決める。Q2aは「その他」の判別と、上限の確認としてだけ測る。

### 言語の変種

- **V1**: 日本語の問い＋日本語の文書
- **V2**: 英語の問い＋日本語の文書
- **V3**: 英語の問い＋Haiku 4.5で英訳した文書
- **V4**（任意）: 公式の英文開示がある項目は、英語の問い＋公式英文

## 3. 評価データ

### 3.1 集め方

- **取得元は次のどちらか**（本人が選ぶ）:
  - **(a) J-Quants Light＋TDnetアドオンを1か月契約する**（約¥12,650、任意）。過去5年分の開示一覧とファイルを公式APIで取得する。手作業で探すより時間がかからず、規約上も明確。
  - **(b) 各社のIRサイトの過去資料（IRライブラリ・公式RSS）から手作業で集める（推奨・費用ゼロ。本人は当面Lightを契約しない）。** TDnetの公開画面は直近31日分しかなく、自動収集も控えるよう求められているので、取得元にしない。
- **抽出方法**: **無作為に**抽出する。対象は保有中・過去に保有した日本株で、各社の直近2〜3年の開示から選ぶ。区分ごとの件数（下表）に達するまで、各社の開示の並びから無作為に引く。本人が「それらしい例」を手で選ぶと、易しい例に偏るため。
- **言語の対照**: 米国株の英文ニュース・8-Kを15件加える。
- **保存場所**: リポジトリの外に置く。どうしてもリポジトリ内に置くなら、gitignore済みの場所にする（例: `storage/app/qualitative-eval/`）。投資仮説は個人の投資判断なので、コミットしない。

| 区分 | 件数 | 内容 |
|---|---|---|
| fc_up | 10 | 業績予想の上方修正 |
| fc_down | 10 | 業績予想の下方修正 |
| no_change | 10 | 決算短信（予想の修正なし）。強い増益5件と弱い決算5件 |
| one_off | 8 | 特別利益・売却益による増益。対照として、本業による増益4件をfc_up・no_changeから指定する |
| mgmt | 6 | 定例の交代3件、予定外・不祥事による交代3件 |
| governance | 4 | 決算の延期・第三者委員会・継続企業の前提 |
| other | 12 | 自己株式取得・配当・提携・製品発表・IRイベント |
| us_news | 15 | 米国保有銘柄の英文ニュース・8-Kの抜粋 |

### 3.2 仮説ペア（Q1用）

**仮説の作り方**: 本人は書かない（手入力の仮説登録は2026-10-07に却下）。本運用と同じ規則（[変更要求案](qualitative-signal-proposal.md) §3.3）で、既存データから型どおりの仮説（成長・割安・株主還元・テーマ）を作る。1銘柄に複数の型があってよい。評価するのは、本運用で実際に使う文面そのものになる。

**規模**: 20銘柄×型の仮説で、**陽性60件以上・陰性約120件、計約180ペア**を作る。件数は**事前に固定**し、結果を見てから増やさない。

| 種類 | 件数 | 中身 |
|---|---|---|
| pos_real | 集まるだけ | 実際に仮説を損なった開示。各社の開示の並びから無作為に抽出したものの中から探す |
| pos_counterfactual | 陽性が60件になるまで | 実在の開示に対して、その開示が損なう型の仮説を、§3.2の規則（本運用と同じテンプレート）から選んで作ったもの。モデルが仮説を実際に読んでいるかの確認も兼ねる。**pos_realとは結果を分けて報告する** |
| neg_related | 2件/仮説 | 関係はあるが、仮説を損なわない開示 |
| irrelevant | 2件/仮説 | 無関係な開示 |
| hard_neg | 2件/仮説 | 会社にとっては悪材料だが、仮説の軸からは外れている開示 |

**Q10**: 買いシグナルの発生時点に対して、「会社固有の悪化あり」と「市場全体の下げのみ」を**それぞれ30件以上**集める。足りなければQ10は参考値にする。

### 3.3 ラベル付けの規則（偏りの対策）

- **ブラインド**: モデルの出力を見る前にラベルを付ける。**開示後の株価の動き・日付・銘柄コードを伏せた状態**で読む（評価用スクリプトで伏せ字にした版を作る）。結果を知っていると、悪い結果の出た開示を「仮説を損なう」と判定しやすくなるため。
- **第2の評価者**: 可能なら、30%以上の項目を本人以外の人がラベル付けし、評価者間の一致度（カッパ係数）を出す。いない場合は、出せるのが本人内の一致度だけであることを、結果レポートに限界として明記する。
- **「不明（U）」**: 判断できない項目は「U」とし、二値の指標からは除いて、別に件数を報告する。
- **再ラベル**: 2週間以上空けてから、無作為に20%をもう一度ラベル付けする。カッパ係数が0.6未満の項目は、問いの書き方が悪いとみなして問いを直す（モデルの評価には数えない）。
- **重要度（1〜5）の目安**:

  | 段階 | 目安 |
  |---|---|
  | 1 | 定型の通知 |
  | 2 | 小規模な自己株式取得・IRイベント |
  | 3 | 予想の5〜15%の修正、配当の変更 |
  | 4 | 予想の15〜30%の修正、予定外の社長交代、大型M&A |
  | 5 | 30%超の修正、継続企業の前提・不正会計、上場廃止のおそれ、TOB |

### 3.4 ファイル形式（JSONL）

```
qual_eval_items.jsonl
{ "item_id", "market": "JP|US", "ticker", "company", "source": "tdnet|edinet|ir|news",
  "published_at", "title", "text_ja", "text_ja_masked", "text_en_mt", "text_en_official",
  "xbrl_facts": { "ordinary_income_yoy", "net_income_yoy", "extraordinary_income_ratio",
  "forecast_revision_pct" }, "stratum",
  "gold": { "type", "materiality", "growth_slowing": "Y|N|U", "one_off": "Y|N|U|NA",
  "company_specific_deterioration": "Y|N|U", "tone", "relevance": "Y|N", "weekend_read" },
  "labeler", "labeler_pass", "labeled_at" }

qual_eval_thesis_pairs.jsonl
{ "pair_id", "thesis_id", "thesis_type": "growth|value|shareholder_return|theme",
  "thesis_ja", "thesis_en", "item_id",
  "kind": "pos_real|pos_counterfactual|neg_related|irrelevant|hard_neg",
  "gold_break": "Y|N", "note" }

qual_eval_runs.jsonl   (append-only)
{ "run_id", "model": "haiku-4-5|jev|ollama|baseline", "model_version", "variant": "V1|V2|V3|V4",
  "qid", "qid_version", "item_id|pair_id", "p", "p_samples", "choice_probs", "score",
  "confidence", "evidence_span", "candidate_order_seed", "latency_ms",
  "input_tokens", "cost_usd", "error" }
```

### 3.5 公開データでの事前確認（任意・2〜4時間。chABSAは事前確認1・2で実施済み。[結果記録](qualitative-signal-poc-results.md)）

自分のデータを作る前に、日本語での傾向を安く確かめたい場合だけ行う。**いずれも開示の影響を判定するデータではない**ので、合否には使わない。

| データ | ライセンス | 使い道 |
|---|---|---|
| chABSA（https://github.com/chakki-works/chABSA-dataset） | CC BY 4.0 | 有報の文単位の極性。日本語と英語の差を見る |
| Economy Watchers Survey（https://huggingface.co/datasets/retarfi/economy-watchers-survey） | CC BY 4.0 | 5段階のScoreの較正。日本語と英語の差を見る（500件を抽出） |
| JF-ICR（https://huggingface.co/datasets/TheFinAI/JF-ICR） | Apache 2.0 | IRの質疑の確約度。見通しのトーン（Q7）に近い |
| japanese-lm-fin-harness（https://github.com/pfnet-research/japanese-lm-fin-harness） | コードはMIT。データはサブセットごとに異なる | Choice型の日本語の金融知識の確認。再配布はしない |

日本語の適時開示・決算短信に、影響のラベルが付いた公開データは見つからなかった。

## 4. 実行の組み合わせ

- **比較するもの**:
  - **AIを使わない代替**（`baseline`）: 開示タイトルの正規表現（上方修正・下方修正・特別利益・代表取締役の異動・延期・第三者委員会・継続企業の前提・自己株式・新株予約権 など）と、XBRLの数値（経常利益と純利益の伸びの差、特別利益の比率、修正の方向と幅）の組み合わせ。本文のキーワードだけの単純な規則にはしない。
  - **Claude Haiku 4.5**: 構造化出力（列挙値または数値＋根拠箇所の引用＋自己申告の確率）。
    - **確率は、自己申告の確率を主に使う。** 事前確認1（[結果](qualitative-signal-poc-results.md)）では、同じ問いを5回投げてもほぼ同じ答えが返り、回答の割合は過信側に偏った（ECE 0.13）。自己申告の確率は較正が良かった（ECE 0.04）。
    - **回答の割合は補助として記録する**（3〜5回）。
    - **実行経路**: APIキーなしで行う場合は、Claude Codeのヘッドレス実行（`claude -p --model haiku --output-format json --json-schema <schema> --system-prompt <問い> --tools ""`）を使う。temperatureは指定できない（既定値のまま）。CLAUDE.mdやフックを読み込ませないよう、評価専用の空のディレクトリで実行する。
    - **利用上限への配慮**: サブスクリプションは「通常の個人利用」が前提なので、1日あたりの件数を絞り、数日に分けて流す。
  - **Jev**: OpenRouter経由の`typesafe/jev-1.13`（API利用額は$1未満、クレジット購入を含めて$1〜2）。本家APIを使えるなら`jev-1.13.0`に固定する。
  - **ローカルLLM（任意）**: Ollamaで日本語に強いモデル（Qwen3.6系・Gemma 4・GPT-OSS Swallow 20Bなど）を動かす。`logprobs`で、回答の最初のトークンが「Yes」「No」になる確率を直接取る。外部にデータを送らないので、J-Quantsの規約の懸念がない。
- **組み合わせ**: 全モデル×V1〜V3×全項目。
- **較正の比較**: 両モデルに、同じ方式の1パラメータの補正（温度補正）をかける。データの半分で補正を当て、残り半分で評価する。
- **安定性**: Choiceは並び順を3通りに入れ替えて実行し、Noul・Scoreは2回ずつ実行する。
- **実行環境**: `scripts/poc/`の、Python標準ライブラリだけで書いた使い捨てのスクリプトを使う（出力はgit管理外の`storage/app/qualitative-eval/`。使い方は`scripts/poc/README.md`）。アプリに組み込むと決めた後は、PHPで作り直す（段階3）。
- **API費用の見積もり**: Haikuは、サブスクリプションでのヘッドレス実行なら追加費用なし（API料金に換算すると数十ドル）。Jevは、API利用額が$1未満、クレジット購入を含めて$1〜2。

## 5. 合否基準（用途ごと、件数は事前に固定）

**Q1・Q10のGO**: 次のすべてを満たしたときだけGOとする。

1. **見分ける力**: ROC-AUCの**95%信頼区間の下限が0.80以上**。信頼区間は、仮説（銘柄）単位のクラスタ・ブートストラップ（1,000回）で出す。
2. **見逃し**: 自動で「問題なし」とする領域（p≦0.2）に入った陽性が、**60件以上の陽性の中で0件**であること（上限は約5%）。Q5aのふるいで落ちた陽性も見逃しに含める。
3. **実用性**:
   - 自動で判定できる項目（p≧0.8またはp≦0.2）が50%以上ある。
   - そのうえで、**実際の出現率（陽性3%と仮定）に換算した適合率**を報告する。陽性が少ないので、注記のほとんどが誤報になっていないかを確認する。
4. **較正**: 補正後のECEが0.10以下。信頼区間の上限が0.15を超える場合は、GOではなく判定保留とする。
5. **代替より良い**: AIを使わない代替（§4）より、AUC（またはmacro-F1）が有意に高い（対応のある比較で、95%信頼区間が0を含まない）。
6. **安定性**: Choiceは、並び順を入れ替えても最上位の候補が95%以上一致する。
7. **言語の方式を確定**: V3（英訳を挟む方式）が10ポイント以上勝った場合は、翻訳の費用を運用費に含める。
8. **運用面**: エラー率が2%未満。

**モデルの選び方**: 既定はHaiku 4.5とする。**Jevは、Q1でHaikuより有意に良い（差の95%信頼区間が0を含まない）場合だけ採用する**。費用差は月数ドルしかなく、Jevには早期アクセス・SLAなしのリスクがあるため。

**AI判定全体のNO-GO**: ある用途で、どちらのモデルもAIを使わない代替を有意に上回らなければ、その用途ではAIを使わない。

**参考値の問い**（Q2a・Q2b・Q3・Q5a・Q6・Q7・Q8・Q9）: 記述統計（正解率・混同行列・相関）だけを出し、合否は判定しない。段階1で採用を検討する場合は、そのときに改めて件数を増やして測る。

## 6. 後から行う「当たり」の検証（PoCの合否には使わない）

- **方式**: 既存のシグナル事後検証（ADR-0017）に**合わせる**。
  - 週足、+4／+13／+26週
  - 日本株は日経平均、米国株はS&P 500に対する超過リターン
  - 結果が出た週が評価期間の3倍（+26週なら78週）に達するまでは判断保留
- **方法**: 本運用で保存した判定（`qualitative_judgments`）を、`signal_occurrences`・`ExcessReturnCalculator`と結合し、注記ありと注記なしを比べる。
- **注意**: 日次やTOPIXは使わない（ADR-0017で採らなかった案）。週足は週の月曜で管理しているので、15時以降に出た開示への反応はならされる。

## 7. 手順（別PCでの作業）

1. **実行経路を用意する**（[変更要求案](qualitative-signal-proposal.md) §7.1）。
   - **Claude**: 別PCのClaude Codeにサブスクリプションでログインしていれば、追加の準備は不要。スクリプトから呼ぶ場合は、`claude setup-token`でトークンを発行する。
   - **Jev**: OpenRouterに登録し、少額のクレジットを買う。
   - **ローカルLLM（任意）**: Ollamaとモデルを入れる。
   - **保管**: キーやトークンは`.env`か、リポジトリ外の環境変数に置き、コミットしない（`.claude/rules/40-security.md`）。
2. **疎通確認**: 公開済みの開示1件で、各モデルを1回呼ぶ。応答の形（Jevは`answers.<id>.noul`ほか）が本書の想定どおりかを確認する。
3. **評価データを集める**（§3.1の(a)か(b)。推奨は費用ゼロの(b)）。伏せ字版を作ってから、ラベルを付ける。件数は日本株60件（IRサイト）＋米国株15件（EDGAR等）の計75件。Q10用の買いシグナル時点の開示（各30件以上、§3.2）は別に集める。投資仮説は§3.2の規則でスクリプトが作る。ラベル付けは約20〜28時間（Q10用は含まず、追加で約3〜5時間）。
4. **スクリプトで実行する**（§4）。結果は`qual_eval_runs.jsonl`に追記する。
5. **指標を計算する**。用途ごとのGO／NO-GO、採用するモデルと言語の方式を、結果レポートにまとめる。
6. **結果に応じて進める**。結果を[変更要求案](qualitative-signal-proposal.md) §7 の段階1に渡すか、NO-GOとして記録して終了する。結論がNO-GOでも、見送りの理由はADRに残す（`.claude/commands/adr.md`の見送りの書き方）。
