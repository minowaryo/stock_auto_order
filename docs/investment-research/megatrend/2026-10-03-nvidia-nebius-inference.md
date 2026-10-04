# NVIDIA Groq 3 LPXとNebiusの採用計画（遡及カード）

| 項目 | 記録 |
|---|---|
| 記録日 | 2026-10-03（Asia/Taipei） |
| 状態・テーマ | 保留／AI基盤・推論用半導体 |
| 元発行主体・公開日 | NVIDIA、2026-08-24。同日のNebius発表も当事者資料 |
| 元資料 | [NVIDIA発表](https://nvidianews.nvidia.com/news/nvidia-groq-3-lpx-now-in-full-production-with-world-class-speed-for-agentic-ai)、[Nebius発表](https://nebius.com/blog/posts/nvidia-groq-3-lpx-nebius-token-factory) |
| 発見経路 | H02 [中島聡さんの公開note](https://note.com/lifeisbeautiful/n/n3b528f5fd322)、2026-08-26 |
| 同じ出来事の既存カード | なし。NVIDIA・Nebiusの同じ発表を2件の独立証拠として数えない |

## 仮説と証拠の鎖

1. NVIDIAは推論用アクセラレータGroq 3 LPXの量産を発表した。低遅延のトークン生成を狙う製品で、ベンチマーク値は同社発表に基づく。
2. Nebiusは自社のToken Factoryへの導入を発表。NVIDIA発表は「導入を計画」と表現しており、実際の稼働台数・利用開始日を確定しない。
3. NVIDIAは製品供給側、Nebiusは導入するクラウド側。Groq社は初期導入予定の別事業者として言及される。顧客・供給者の役割を分ける。
4. [NVIDIA発表](https://nvidianews.nvidia.com/news/nvidia-groq-3-lpx-now-in-full-production-with-world-class-speed-for-agentic-ai)がNASDAQ `NVDA`、[Nebius公式IR資料](https://nebius.com/newsroom/nebius-group-announces-pricing-of-upsized-private-offering-of-5-0-billion-of-convertible-senior-notes)がNASDAQ `NBIS`を示す。
5. 製品の量産・採用計画までは確認したが、Nebiusの稼働実績、NVIDIAの当該製品売上、Nebiusの収益寄与は未確認。
6. [Nebiusの2026年第2四半期決算](https://nebius.com/newsroom/nebius-reports-second-quarter-2026-financial-results)の[発表PDF](https://assets.nebius.com/assets/72a8c258-bbb7-4df7-ab9d-8698f6cb88fc/PR.pdf)には連結売上高5億8,230万ドル、営業損失1億7,590万ドル、固定資産・無形資産購入56億5,740万ドルが載る。連結にはAIクラウド以外も含まれ、対象期間はGroq 3 LPXの8月発表より前。これらの数字から当該製品の売上・稼働・投資回収額は分からない。

| 証拠の段階 | 状態 | 根拠・限界 |
|---|---|---|
| テーマ需要の統計・予測 | 未確認 | 一社発表の性能値・需要見通しは市場全体を示さない |
| 案件・採択・補助・研究 | 該当なし | 製品と採用計画 |
| 製品・規制承認・臨床結果 | 確認済み | NVIDIAによる量産発表。第三者の継続的性能評価は未確認 |
| 顧客導入・稼働 | 未確認 | Nebiusの採用**計画**発表まで。実稼働台数・提供開始は未確認 |
| 当該企業の受注・契約 | 未確認 | 契約金額・出荷量は公表資料で未確認 |
| 決算での売上・利益寄与 | 未確認 | 個別寄与は未開示 |

## 反証・欠落

- 当事者2社の同時発表を独立需要2件と数えない。発表には将来見通しの注意書きがある。
- 推論速度の比較は指定モデル・長いコンテキストなど条件付き。価格、電力、供給量、稼働率との総合比較は未確認。
- [Nebiusの転換社債発行](https://nebius.com/newsroom/nebius-group-announces-pricing-of-upsized-private-offering-of-5-0-billion-of-convertible-senior-notes)は資金調達・希薄化等の検討材料であり、需要増だけで企業価値を判断しない。次回は2026-11-01に提供開始と両社決算を確認。
- 固定サンプル期間外の[2026-10-01のInferize買収発表](https://nebius.com/newsroom/nebius-acquires-inferize-to-strengthen-nebius-token-factorys-production-inference-stack)は、推論基盤強化の別の出来事。買収条件は非開示で、Groq 3 LPXの実稼働や売上を証明しない。試行の固定サンプル・10月5日以降の週次新着には算入しない。

## 判断とUC-012への受け渡し

| 項目 | 記録 |
|---|---|
| お気に入りCSV・アプリ照合 | NVDAは登録済み。NBISはアプリのウォッチリストになく、2026-09-30の最新保有スナップショットにもない（2026-10-03、読み取り専用で確認） |
| 結論 | 保留。Nebiusは新規候補だが稼働・収益への距離を要確認 |
| 本人による銘柄・証拠確認 | 未確認 |
| UC-012で監視するか | 未決定。自動登録しない |
| 次回見直し | 2026-11-01、稼働実績・顧客事例・決算 |
