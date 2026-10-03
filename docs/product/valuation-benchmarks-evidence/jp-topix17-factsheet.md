# TOPIX-17 業種別 PER/PBR（JPX公式ファクトシート）

出典: JPX 各指数ファクトシートPDF（TOPIX-17 各業種）。一覧ページ https://www.jpx.co.jp/markets/indices/factsheets/index.html
個別URL: https://www.jpx.co.jp/markets/indices/factsheets/files/003_2_NN_fac2_17sector_<英語名>.pdf （NN=01..17）
基準日: 全17業種とも 2026年8月31日時点（PDF 2/3頁「ファンダメンタルズ」欄から転記）
取得方法: curl(UA指定)でPDF取得→pypdfでテキスト抽出（WebFetchは403）

| # | 業種 | PER | PBR | 配当利回り | ROE | 銘柄数 | ファイル名(英) |
|---|---|---|---|---|---|---|---|
| 01 | 食品 | 21.72 | 1.81 | 2.59% | 8.31% | 78 | FOODS |
| 02 | エネルギー資源 | 12.62 | 1.04 | 2.54% | 8.26% | 11 | ENERGY RESOURCES |
| 03 | 建設・資材 | 14.38 | 1.41 | 2.78% | 9.80% | 125 | CONSTRUCTION and MATERIALS |
| 04 | 素材・化学 | 23.40 | 1.56 | 2.03% | 6.67% | 146 | RAW MATERIALS and CHEMICALS |
| 05 | 医薬品 | 27.93 | 1.83 | 2.80% | 6.56% | 32 | PHARMACEUTICAL |
| 06 | 自動車・輸送機 | 18.30 | 1.01 | 2.75% | 5.53% | 50 | AUTOMOBILES and TRANSPORTATION EQUIPMENT |
| 07 | 鉄鋼・非鉄 | 25.48 | 1.76 | 1.73% | 6.90% | 42 | STEEL and NONFERROUS METALS |
| 08 | 機械 | 26.01 | 2.33 | 1.53% | 8.96% | 117 | MACHINERY |
| 09 | 電機・精密 | 44.10 | 3.54 | 0.96% | 8.04% | 155 | ELECTRIC APPLIANCES and PRECISION INSTRUMENTS |
| 10 | 情報通信・サービスその他 | 17.70 | 2.35 | 1.56% | 13.28% | 365 | IT and SERVICES and OTHERS |
| 11 | 電気・ガス | 14.50 | 0.77 | 2.21% | 5.31% | 23 | ELECTRIC POWER and GAS |
| 12 | 運輸・物流 | 12.80 | 1.09 | 2.35% | 8.55% | 55 | TRANSPORTATION and LOGISTICS |
| 13 | 商社・卸売 | 17.36 | 1.83 | 2.16% | 10.53% | 126 | COMMERCIAL and WHOLESALE TRADE |
| 14 | 小売 | 27.77 | 2.52 | 1.35% | 9.07% | 141 | RETAIL TRADE |
| 15 | 銀行 | 17.98 | 1.66 | 2.05% | 9.24% | 69 | BANKS |
| 16 | 金融（除く銀行） | 13.00 | 1.59 | 2.81% | 12.20% | 51 | FINANCIALS (EX BANKS) |
| 17 | 不動産 | 14.31 | 1.43 | 2.31% | 9.99% | 50 | REAL ESTATE |

## 定義（信頼度に差あり）
- ファクトシート自体にはPER/PBRの計算式の注記は無し（PDF全文を確認）。指数は時価総額加重（浮動株ベース）。
- JPX用語集(https://www.jpx.co.jp/glossary/ka/74.html)/検索要約では PBR=「株価合計÷1株当たり純資産合計、各社の決算期末の実績値」。PERも同様の「直近実績ベース・集計値(構成銘柄の時価総額合計÷利益合計に相当)」と推定されるが、PERの定義明記は未確認（=推測。予想PERではない可能性が高い）。
- 調和平均/単純平均かの明記は取得不可。集計(加重)型で、単純平均の中央値とは異なる点に注意。

## NEXT FUNDS（野村AM）
- 月次レポートPDF（例 https://www.nomura-am.co.jp/fund/monthly1/M1141617.pdf 食品1617）を取得・抽出したが、PER/PBRの記載は確認できず（テキスト抽出で該当語なし。画像内の可能性は否定できず）。ETF側の値は「取得不可」。ETFは対象指数連動のためJPXファクトシートが一次情報源として適切。
- 他社17業種ETF資料は未調査。

## 注意点
- 電機・精密PER44.10は半導体関連等の大型株の影響（時価総額加重）。業種内ばらつき大、割安/割高判定の単純基準には歪みあり。
- 小売のみ最初検索時に2026/5/29版(27.35/2.48)がヒット、取得時は8/31版に更新済。月次更新なので定期更新が必要。
- 赤字企業の扱い等（PERの除外規則）は未確認。
- 利用規約: 無断複製・転載禁止の注記あり。アプリへの数値組込みは利用条件の確認推奨。
