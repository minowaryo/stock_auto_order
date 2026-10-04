# Damodaran 米国業種別 PER/PBR 基準値（調査結果）

## 出典・基準日（全値共通）
- PER: https://pages.stern.nyu.edu/~adamodar/pc/datasets/pedata.xls （Industry Averages シート。"Date updated" セル = 46027 = 2026-01-05。ファイル最終保存 2026-01-07。US companies）
- PBR: https://pages.stern.nyu.edu/~adamodar/pc/datasets/pbvdata.xls （同日付。US companies）
- データはファイルを取得し Python(xlrd) で読み取って転記（推測補完なし）。ダウンロード元のデータ基準は「2026年1月」版（= 2025年末時点の株価と直近財務）。
- 更新頻度: Damodaran のデータページによれば「毎年1月第1〜2週に主要更新、最終更新2026-01-09、次回は2027年1月上旬」。年1回なので、現時点(2026-10)でも最新はこの版。
- 全95業種+Total Market分の完全表: 本ファイル末尾。

## 指標の定義（pedata.xls の Variables & FAQ シートより）
| 列 | 定義 | 注意 |
|---|---|---|
| Current PE | 直近会計年度EPSベースのPER。**黒字企業のみ**の**単純平均** | 外れ値に弱い（Specialty Chemical 161.7、Pharma 335.3 等は異常値） |
| Trailing PE | TTM EPSベース。黒字企業のみの**単純平均** | 同上（Healthcare IT 506.5, Retail Special 314.0） |
| Forward PE | 今後4四半期予想EPSベース。黒字企業のみ単純平均 | 予想値でありアプリのTTMとは別物 |
| Aggregate Mkt Cap/Net Income (all firms) | 業種全社の時価総額合計 ÷ 純利益合計（赤字企業を含む） | 赤字が多い業種は上振れ、赤字が利益を上回ると NA |
| Aggregate Mkt Cap/Trailing NI (only money making) | 黒字企業のみの時価総額合計 ÷ TTM純利益合計 | 時価総額加重に近く、大型株中心。単純平均より安定 |
| % of Money Losing firms (Trailing) | TTM赤字企業の割合 | 赤字企業は平均から除外される（バイアス） |
| PBV | 業種全社の時価総額合計 ÷ 自己資本簿価合計（集計比率） | 大型株加重。赤字企業も含む（簿価がマイナスの企業の扱いは注記なし） |
| ROE | 純利益合計 ÷ 自己資本合計 | PBVとセットで読む |

## アプリ比較時の整合性注意点
1. アプリは個別銘柄の TTM PER と直近年度 PBR。最も近い基準は **Aggregate Mkt Cap/Trailing NI (only money making)**（TTM・黒字のみ）。Trailing PE（単純平均）は小型株・外れ値の影響が大きく、業種によっては100超になるため色付け基準には不向き。
2. 個別銘柄のPERが算出不能（赤字）の場合は比較対象外にすべき。業種側も黒字のみで算出しているので定義は揃う。
3. 業種が赤字企業ばかり（% money losing が 70-90%: Biotech 90%, Pharma 85%, Metals&Mining 85%, Auto&Truck 82%, Software 70%）の場合、黒字企業は少数の勝ち組なので基準値が高めになる（選別バイアス）。
4. 集計は時価総額加重（大型株が支配）。Auto & Truck の 119.6 は Tesla 支配、Computers/Peripherals PBV 34.1 は Apple 支配（自社株買いで簿価が小さい）。
5. PBRの比較は、アプリの「直近年度PBR」に対し Damodaran は直近簿価（最新四半期）ベースの可能性あり（定義にはその明記なし＝不明）。
6. PERは Damodaran 側が2026-01時点の静的値。株価水準が動けば（相場全体のPER）ズレる。Total Market のPERを併記し相対化する案も可。
7. 業種数が少ない区分（Reinsurance 1社、Rubber&Tires 3社、Chemical Diversified 4社、Oil/Gas Integrated 4社、Railroads 4社）は統計的に頑健でない。

## Finnhub業種 → Damodaran業種 対応表
値は「黒字企業のみ時価総額加重PER(aggPE profit) / PBV / ROE」。出典は上記2ファイル。
対応は私の判断（Finnhub側の定義詳細は未確認）。確度: ◎=ほぼ1対1、○=主要部分が一致、△=複数業種の混在で曖昧。

| Finnhub業種 | 対応Damodaran業種 | aggPE(黒字のみ) | Trailing PE(単純平均) | PBV | ROE | 確度/備考 |
|---|---|---|---|---|---|---|
| Aerospace & Defense | Aerospace/Defense | 35.3 | 92.8 | 7.9 | 15% | ◎ 赤字51%・新興宇宙銘柄が混在 |
| Automobiles | Auto & Truck | 119.6 | 64.3 | 7.7 | 3% | ○ Tesla支配で歪む。基準としては不安定。Auto Parts(20.2/1.9/2%)は Finnhub "Auto Components" 側 |
| Beverages | Beverage (Soft) 26.3/6.9 + Beverage (Alcoholic) 16.6/1.9 | 16.6〜26.3 | 47.8 / 28.3 | 1.9〜6.9 | 1%〜31% | △ ソフト(KO,PEP)とアルコール(BUD,STZ)で乖離。銘柄で使い分けるべき |
| Electrical Equipment | Electrical Equipment | 47.2 | 80.0 | 6.5 | 2% | ◎ aggPE(all)=NA（赤字合計）。AI電力関連の高評価が反映 |
| Media | Entertainment 32.5/4.8 ; Broadcasting 13.5/1.7 ; Cable TV 5.1/1.3 ; Publishing & Newspapers 16.7/2.3 ; Advertising 25.5/4.6 | 5.1〜32.5 | — | 1.3〜4.8 | — | △ 混在。単一値は不適切 |
| Metals & Mining | Metals & Mining 29.4/4.3 ; Steel 24.1/1.9 ; Precious Metals 18.6/3.6 ; Coal 19.7/2.2 | 18.6〜29.4 | 54.1 | 4.3 | 18% | ○ Damodaran「Metals & Mining」は鉱業全般（金銀以外）。金銀はPrecious Metals |
| Pharmaceuticals | Drugs (Pharmaceutical) | 24.9 | 55.7 | 6.6 | 24% | ◎ 赤字85%・Current PE 335.3は異常値。Biotechnologyは別(32.1/8.2/-6%) |
| Retail | Retail (General) 33.5/7.2 ; Retail (Special Lines) 24.9/7.3 ; Retail (Distributors) 22.4/4.0 ; Retail (Grocery and Food) 25.2/3.9 ; Retail (Automotive) 24.0/7.5 ; Retail (Building Supply) 22.3/PBV 132.2 | 22.3〜33.5 | 23.5〜54.8 | 3.9〜7.5 | 13%〜34% | △ 混在。PERは22〜34でそれなりに収束。PBVは使用不可 |
| Road & Rail | Transportation (Railroads) 20.9/5.8 ; Trucking 32.7/2.9 ; Transportation 13.3/4.8 | 13.3〜32.7 | 21.5 / 37.4 / 28.9 | 2.9〜5.8 | 8%〜37% | △ 鉄道(UNP, CSX)は Railroads(4社のみ)、トラックは Trucking |
| Semiconductors | Semiconductor 48.8 / Semiconductor Equip 34.2 | 48.8 (設備34.2) | 100.2 / 46.8 | 13.3 / 9.7 | 31% / 36% | ◎ Finnhubでは装置も含む可能性あり（未確認） |
| Technology | 単一対応なし。Software (System & Application) 37.5/9.1 ; Computer Services 26.1/4.7 ; Computers/Peripherals 34.3/34.1 ; Electronics (General) 40.2/5.0 ; Software (Internet) 34.8/10.9 ; Telecom. Equipment 35.1/8.4 | 26.1〜40.2 | — | — | — | △ 最も曖昧。Apple/MSFT/他が混在。Total Market (without financials) の 28.9 を代替にする案も |
| Utilities | Utility (General) 20.5/1.8 ; Utility (Water) 20.2/2.0 ; Power 21.0/2.3 | 20.2〜21.0 | 19.9〜24.7 | 1.8〜2.3 | 10〜12% | ◎ 値が収束しており信頼性高い |

### その他 Finnhub 業種になりうるもの
Finnhubの業種名リストは取得・検証していない。以下は一般的な名称を前提にした対応案（要確認）。

| Finnhub業種（想定） | Damodaran業種 | aggPE(黒字) | PBV | ROE | 確度 |
|---|---|---|---|---|---|
| Banks | Banks (Regional) 12.6/1.1/10% ; Bank (Money Center) 14.2/1.6/13% | 12.6〜14.2 | 1.1〜1.6 | 10〜13% | ◎ PBR主軸向き |
| Insurance | Insurance (General) 27.3/3.5 ; (Life) 11.8/1.4 ; (Prop/Cas.) 11.8/2.0 | 11.8〜27.3 | 1.4〜3.5 | 13〜19% | ○ 種類で差が大きい |
| Financial Services | Financial Svcs. (Non-bank & Insurance) 22.1/3.7/29% ; Brokerage & IB 19.8/2.8/18% ; Investments & Asset Mgmt 23.0/2.5/16% | 19.8〜23.0 | 2.5〜3.7 | 16〜29% | △ |
| Biotechnology | Drugs (Biotechnology) | 32.1 | 8.2 | -6% | ◎ 赤字90%で基準不安定 |
| Health Care（機器・サービス） | Healthcare Products 30.3/4.4 ; Healthcare Support Services 21.1/2.8 ; Hospitals/Healthcare Facilities 15.6/5.6 ; Heathcare Information and Technology 37.3/4.0 | 15.6〜37.3 | 2.8〜5.6 | — | △ |
| Communications / Telecommunication | Telecom. Services 8.0/1.6/20% ; Telecom (Wireless) 19.5/3.5/16% ; Cable TV 5.1/1.3 | 5.1〜19.5 | 1.3〜3.5 | — | △ Telecom Servicesの8.0は低め（Damodaran定義のTTM合算） |
| Energy | Oil/Gas (Production & Exploration) 12.7/1.4/12% ; Oil/Gas (Integrated) 19.0/1.7/10% (4社のみ) ; Oilfield Svcs/Equip. 19.4/1.9/9% ; Oil/Gas Distribution 18.5/2.9/18% | 12.7〜19.4 | 1.4〜2.9 | 9〜18% | ○ |
| Real Estate | R.E.I.T. 28.1/2.0/5% ; Real Estate (Development) 13.4/1.0 ; (General/Diversified) 19.4/1.2 ; (Operations & Services) 43.8/3.1 | 13.4〜43.8 | 1.0〜3.1 | 3〜11% | △ REITのPERはFFOでないため不適切（会計上純利益が小さい）。PERでなくP/FFOが本来の指標。本調査では取得不可 |
| Chemicals | Chemical (Basic) 18.4/1.1/-8% ; Specialty 23.2/2.4/3% ; Diversified NA(4社, 100%赤字) | 18.4〜23.2 | 1.1〜2.4 | — | ○ Current PE 161.7等は異常値 |
| Food Products | Food Processing | 16.5 | 1.8 | 5% | ◎ |
| Machinery | Machinery | 26.6 | 4.5 | 16% | ◎ |
| Building / Construction & Engineering | Building Materials 17.1/3.6 ; Construction Supplies 25.8/5.5 ; Engineering/Construction 24.8/5.5 ; Homebuilding 11.3/1.6 | 11.3〜25.8 | 1.6〜5.5 | — | △ |
| Logistics & Transportation | Transportation 13.3/4.8 ; Air Transport 12.9/2.8 ; Shipbuilding & Marine 13.7/1.5 | 12.9〜13.7 | 1.5〜4.8 | — | △ |
| Airlines | Air Transport | 12.9 | 2.8 | 14% | ◎ |
| Tobacco | Tobacco | 19.8 | NA（取得不可） | NA | ◎ PBV欠損（簿価がマイナス/極小と推測されるが未確認） |
| Hotels, Restaurants & Leisure | Hotel/Gaming 24.7/12.3 ; Restaurant/Dining 32.8/PBV 74.7 ; Recreation 18.2/4.0 | 18.2〜32.8 | — | — | △ PBV使用不可 |
| Household / Consumer products | Household Products 19.7/5.8/27% | 19.7 | 5.8 | 27% | ○ |
| Textiles/Apparel | Apparel 27.1/3.9 ; Shoe 30.5/6.1 | 27.1〜30.5 | 3.9〜6.1 | — | ○ |
| Professional Services / Commercial Services | Business & Consumer Services | 24.1 | 5.3 | 18% | ○ |
| Paper & Forest / Packaging | Paper/Forest Products 14.5/1.8 ; Packaging & Container 13.9/2.2 | 13.9〜14.5 | 1.8〜2.2 | — | ○ |
| Auto Components | Auto Parts | 20.2 | 1.9 | 2% | ◎ |
| Total Market（参照用） | Total Market / Total Market (without financials) | 26.6 / 28.9 | 4.6 / 5.1 | 17% / 16% | 全体比較用 |

## PBRを判定に使うべきか（見解。事実ではなく私の判断。根拠はデータ内の値）
Damodaranデータ内の事実: 米国全体PBV 4.6倍、ROE 17%（Total Market）。簿価ゼロ近傍の企業が多く、集計PBVが歪んでいる。

使える（簿価が事業価値の主要な代理で、ROEとの関係が安定）:
- Banks（Regional 1.1 / Money Center 1.6）、Insurance（Life 1.4, Prop/Cas 2.0）、Utilities（1.8〜2.3）、Oil/Gas、Steel/Metals 一部（1.9〜4.3）、Homebuilding(1.6)、Real Estate系、Auto Parts(1.9)。PBVが1〜3倍に収まり、ROEと整合（ROE10%前後でPBV1〜2）。

使うべきでない/参考表示に留める:
- 自社株買い・簿価縮小が顕著: Computers/Peripherals 34.1（Apple）、Restaurant/Dining 74.7、Retail (Building Supply) 132.2（ROE=NA、簿価がほぼゼロ/マイナス）、Tobacco NA、Hospitals 5.6 でROE51%、Hotel/Gaming 12.3でROE45%、Beverage(Soft) 6.9でROE31%。
- 無形資産中心でROEが高くPBVが成長・ブランドを反映: Software（9〜11倍）、Semiconductor（13.3）、Drugs(Pharma 6.6)、Healthcare Products、Retail Special/Automotive(7倍台)、Aerospace/Defense 7.9。
- 赤字・成長企業が多く簿価が意味を持たない: Biotech(ROE -6%)、Auto & Truck(Tesla支配)、Green Energy、Electrical Equipment。

実務的な方針案: PBR判定は「Banks / Insurance / Utilities / Energy / Real Estate(非REIT) / Steel・Mining / Auto Parts / Homebuilding」のみ有効にし、それ以外は PER のみで色付け、PBR は無色表示が妥当。ROEとの併用（PBV÷ROE の比較）も代替策だが、本調査には含めない。

## 信頼度
- 数値そのもの: 高（実ファイルから転記。ただし年1回更新で2026-01-05時点。2026-10現在で約9か月古い）
- 業種対応表: 中（Finnhubの業種定義の一次資料は未確認。Technology・Media・Retail・Road&Rail・Beverages は特に曖昧）
- 基準値としての妥当性: PERはaggPE(黒字のみ)を主基準、Trailing/Current PE(単純平均)は異常値多数のため非推奨。Biotech/Autos/Software/Metals のように赤字率70%超の業種は基準が不安定。
- 参考: Damodaran の業種名 "Heathcare Information and Technology" はファイル内の綴り（原文ママ）。

## 付録: 全業種表（2026-01-05版、US）
列: 業種 | 社数 | 赤字割合(TTM) | Current PE(黒字単純平均) | Trailing PE(同) | Forward PE(同) | 集計PER(全社) | 集計PER(黒字のみ) | PBV | ROE

| Industry | N | %loss | curPE | trailPE | fwdPE | aggPE all | aggPE profit | PBV | ROE |
|---|---|---|---|---|---|---|---|---|---|
| Advertising | 52 | 79% | 132.2 | 44.4 | 52.9 | 460.5 | 25.5 | 4.6 | -1% |
| Aerospace/Defense | 79 | 51% | 118.0 | 92.8 | 45.9 | 78.1 | 35.3 | 7.9 | 15% |
| Air Transport | 23 | 61% | 15.5 | 18.3 | 11.4 | 26.1 | 12.9 | 2.8 | 14% |
| Apparel | 35 | 63% | 82.1 | 37.8 | 24.9 | 41.2 | 27.1 | 3.9 | 10% |
| Auto & Truck | 33 | 82% | 44.6 | 64.3 | 49.0 | 148.5 | 119.6 | 7.7 | 3% |
| Auto Parts | 35 | 49% | 27.8 | 28.1 | 15.1 | 47.0 | 20.2 | 1.9 | 2% |
| Bank (Money Center) | 15 | 7% | 17.6 | 15.0 | 13.0 | 15.0 | 14.2 | 1.6 | 13% |
| Banks (Regional) | 568 | 14% | 34.7 | 33.6 | 11.0 | 15.0 | 12.6 | 1.1 | 10% |
| Beverage (Alcoholic) | 14 | 64% | 35.0 | 28.3 | 12.8 | 24.3 | 16.6 | 1.9 | 1% |
| Beverage (Soft) | 27 | 70% | 37.1 | 47.8 | 23.7 | 26.9 | 26.3 | 6.9 | 31% |
| Broadcasting | 24 | 71% | 6.4 | 44.8 | 17.5 | NA | 13.5 | 1.7 | 4% |
| Brokerage & Investment Banking | 32 | 22% | 26.8 | 86.5 | 19.9 | 24.4 | 19.8 | 2.8 | 18% |
| Building Materials | 41 | 29% | 21.1 | 32.8 | 18.4 | 14.7 | 17.1 | 3.6 | 16% |
| Business & Consumer Services | 155 | 57% | 35.4 | 36.4 | 18.7 | 30.8 | 24.1 | 5.3 | 18% |
| Cable TV | 9 | 44% | 13.7 | 6.1 | 9.2 | 8.7 | 5.1 | 1.3 | 11% |
| Chemical (Basic) | 29 | 76% | 14.1 | 24.4 | 22.8 | 18.5 | 18.4 | 1.1 | -8% |
| Chemical (Diversified) | 4 | 100% | 20.5 | NA | 14.2 | NA | NA | 1.1 | -15% |
| Chemical (Specialty) | 59 | 59% | 161.7 | 196.8 | 19.3 | 48.1 | 23.2 | 2.4 | 3% |
| Coal & Related Energy | 16 | 81% | 23.8 | 17.0 | 34.4 | 52.2 | 19.7 | 2.2 | -3% |
| Computer Services | 64 | 55% | 26.5 | 51.6 | 56.5 | 30.2 | 26.1 | 4.7 | 18% |
| Computers/Peripherals | 36 | 61% | 80.9 | 81.1 | 36.1 | 36.2 | 34.3 | 34.1 | -0% |
| Construction Supplies | 40 | 30% | 27.0 | 42.7 | 17.7 | 21.9 | 25.8 | 5.5 | 23% |
| Diversified | 20 | 80% | 14.1 | 15.7 | 14.9 | 13.4 | 16.8 | 1.8 | 11% |
| Drugs (Biotechnology) | 496 | 90% | 109.3 | 64.3 | 63.8 | NA | 32.1 | 8.2 | -6% |
| Drugs (Pharmaceutical) | 228 | 85% | 335.3 | 55.7 | 24.2 | 58.9 | 24.9 | 6.6 | 24% |
| Education | 32 | 50% | 27.6 | 27.0 | 18.1 | 39.3 | 15.0 | 2.6 | 16% |
| Electrical Equipment | 112 | 75% | 76.4 | 80.0 | 29.6 | NA | 47.2 | 6.5 | 2% |
| Electronics (Consumer & Office) | 8 | 100% | NA | NA | 19.5 | NA | NA | 3.9 | -29% |
| Electronics (General) | 114 | 60% | 52.0 | 48.4 | 29.6 | 56.7 | 40.2 | 5.0 | 13% |
| Engineering/Construction | 48 | 40% | 90.2 | 63.3 | 28.1 | 36.9 | 24.8 | 5.5 | 26% |
| Entertainment | 92 | 83% | 172.0 | 108.6 | 42.7 | NA | 32.5 | 4.8 | 6% |
| Environmental & Waste Services | 53 | 79% | 66.2 | 66.5 | 61.1 | 37.7 | 34.3 | 6.6 | 20% |
| Farming/Agriculture | 35 | 66% | 16.8 | 19.1 | 19.3 | 24.2 | 22.0 | 2.4 | 12% |
| Financial Svcs. (Non-bank & Insurance) | 176 | 41% | 65.6 | 26.1 | 16.4 | 22.9 | 22.1 | 3.7 | 29% |
| Food Processing | 78 | 60% | 31.0 | 48.0 | 17.2 | 16.8 | 16.5 | 1.8 | 5% |
| Food Wholesalers | 13 | 54% | 24.9 | 26.8 | 16.6 | 27.1 | 24.7 | 4.9 | 18% |
| Furn/Home Furnishings | 27 | 52% | 15.9 | 17.4 | 20.3 | 85.4 | 25.2 | 2.5 | 3% |
| Green & Renewable Energy | 15 | 87% | 44.6 | 32.2 | 42.7 | 66.4 | 26.0 | 1.3 | -7% |
| Healthcare Products | 204 | 76% | 55.9 | 43.1 | 42.3 | 46.5 | 30.3 | 4.4 | 11% |
| Healthcare Support Services | 104 | 62% | 94.4 | 83.8 | 55.3 | 23.6 | 21.1 | 2.8 | 10% |
| Heathcare Information and Technology | 115 | 77% | 219.6 | 506.5 | 37.4 | 109.9 | 37.3 | 4.0 | 6% |
| Homebuilding | 30 | 20% | 10.2 | 11.5 | 14.3 | 10.2 | 11.3 | 1.6 | 14% |
| Hospitals/Healthcare Facilities | 31 | 52% | 24.4 | 18.8 | 15.7 | 16.7 | 15.6 | 5.6 | 51% |
| Hotel/Gaming | 63 | 54% | 83.2 | 46.6 | 29.1 | 31.7 | 24.7 | 12.3 | 45% |
| Household Products | 110 | 72% | 65.9 | 33.1 | 16.9 | 24.2 | 19.7 | 5.8 | 27% |
| Information Services | 15 | 40% | 29.3 | 24.9 | 13.2 | 25.8 | 23.6 | 3.8 | 15% |
| Insurance (General) | 21 | 24% | 55.1 | 34.0 | 21.5 | 30.0 | 27.3 | 3.5 | 19% |
| Insurance (Life) | 20 | 25% | 19.9 | 12.6 | 9.3 | 10.2 | 11.8 | 1.4 | 13% |
| Insurance (Prop/Cas.) | 57 | 14% | 18.1 | 18.3 | 16.4 | 16.5 | 11.8 | 2.0 | 19% |
| Investments & Asset Management | 283 | 58% | 378.6 | 75.3 | 18.8 | 24.8 | 23.0 | 2.5 | 16% |
| Machinery | 105 | 43% | 42.6 | 35.8 | 24.1 | 26.2 | 26.6 | 4.5 | 16% |
| Metals & Mining | 73 | 85% | 92.9 | 54.1 | 29.8 | 45.6 | 29.4 | 4.3 | 18% |
| Office Equipment & Services | 14 | 43% | 13.4 | 18.6 | 10.3 | 55.6 | 18.2 | 2.9 | 13% |
| Oil/Gas (Integrated) | 4 | 0% | 13.0 | 16.2 | 21.9 | 15.7 | 19.0 | 1.7 | 10% |
| Oil/Gas (Production and Exploration) | 142 | 59% | 21.4 | 31.0 | 16.1 | 15.2 | 12.7 | 1.4 | 12% |
| Oil/Gas Distribution | 23 | 43% | 20.2 | 23.7 | 42.5 | 21.6 | 18.5 | 2.9 | 18% |
| Oilfield Svcs/Equip. | 97 | 58% | 38.0 | 33.3 | 21.2 | 19.0 | 19.4 | 1.9 | 9% |
| Packaging & Container | 19 | 37% | 60.5 | 16.4 | 15.3 | 12.7 | 13.9 | 2.2 | 12% |
| Paper/Forest Products | 6 | 50% | 7.0 | 12.9 | 16.6 | 11.0 | 14.5 | 1.8 | 7% |
| Power | 46 | 11% | 20.7 | 24.7 | 17.6 | 23.1 | 21.0 | 2.3 | 12% |
| Precious Metals | 56 | 86% | 119.7 | 26.8 | 16.5 | 50.9 | 18.6 | 3.6 | 24% |
| Publishing & Newspapers | 19 | 53% | 18.6 | 15.2 | 13.5 | 21.1 | 16.7 | 2.3 | 14% |
| R.E.I.T. | 190 | 34% | 63.0 | 37.0 | 45.1 | 56.1 | 28.1 | 2.0 | 5% |
| Real Estate (Development) | 14 | 79% | 29.4 | 11.4 | 12.3 | 23.3 | 13.4 | 1.0 | 5% |
| Real Estate (General/Diversified) | 12 | 58% | 46.2 | 40.2 | 14.3 | 41.7 | 19.4 | 1.2 | 11% |
| Real Estate (Operations & Services) | 54 | 59% | 61.3 | 107.3 | 93.6 | 405.5 | 43.8 | 3.1 | 3% |
| Recreation | 49 | 57% | 24.8 | 119.0 | 38.0 | 96.0 | 18.2 | 4.0 | -13% |
| Reinsurance | 1 | 0% | 18.6 | 15.4 | 8.1 | 18.6 | 15.4 | 1.0 | 8% |
| Restaurant/Dining | 64 | 47% | 67.7 | 48.3 | 31.9 | 36.4 | 32.8 | 74.7 | 0% |
| Retail (Automotive) | 34 | 56% | 34.3 | 54.8 | 21.9 | 27.4 | 24.0 | 7.5 | 34% |
| Retail (Building Supply) | 14 | 50% | 31.2 | 23.5 | 23.1 | 23.4 | 22.3 | 132.2 | NA |
| Retail (Distributors) | 62 | 45% | 103.6 | 28.8 | 43.8 | 25.4 | 22.4 | 4.0 | 18% |
| Retail (General) | 23 | 26% | 46.7 | 51.0 | 24.0 | 43.0 | 33.5 | 7.2 | 26% |
| Retail (Grocery and Food) | 15 | 40% | 17.5 | 20.5 | 14.3 | 17.7 | 25.2 | 3.9 | 13% |
| Retail (REITs) | 26 | 8% | 109.9 | 368.7 | 44.4 | 35.7 | 31.1 | 2.1 | 6% |
| Retail (Special Lines) | 94 | 62% | 26.0 | 314.0 | 21.5 | 27.7 | 24.9 | 7.3 | 30% |
| Rubber& Tires | 3 | 100% | 35.8 | NA | 7.3 | 42.7 | NA | 0.8 | -36% |
| Semiconductor | 66 | 62% | 70.1 | 100.2 | 37.3 | 77.4 | 48.8 | 13.3 | 31% |
| Semiconductor Equip | 31 | 45% | 47.6 | 46.8 | 41.6 | 35.9 | 34.2 | 9.7 | 36% |
| Shipbuilding & Marine | 8 | 62% | 13.7 | 18.9 | 11.6 | 12.9 | 13.7 | 1.5 | 10% |
| Shoe | 11 | 36% | 18.7 | 24.5 | 17.1 | 22.5 | 30.5 | 6.1 | 19% |
| Software (Entertainment) | 77 | 71% | 70.7 | 39.3 | 18.5 | 34.5 | 29.6 | 9.1 | 37% |
| Software (Internet) | 29 | 72% | 163.0 | 67.2 | 64.8 | NA | 34.8 | 10.9 | -1% |
| Software (System & Application) | 309 | 70% | 122.5 | 79.2 | 34.1 | 49.1 | 37.5 | 9.1 | 30% |
| Steel | 19 | 37% | 77.4 | 47.6 | 16.9 | 23.6 | 24.1 | 1.9 | 4% |
| Telecom (Wireless) | 12 | 75% | 21.6 | 107.1 | 35.6 | 21.4 | 19.5 | 3.5 | 16% |
| Telecom. Equipment | 57 | 68% | 125.5 | 84.7 | 39.5 | 48.0 | 35.1 | 8.4 | 25% |
| Telecom. Services | 39 | 79% | 26.5 | 16.8 | 26.5 | 14.4 | 8.0 | 1.6 | 20% |
| Tobacco | 10 | 50% | 23.0 | 23.2 | 17.6 | 19.0 | 19.8 | NA | NA |
| Transportation | 19 | 53% | 60.0 | 28.9 | 19.8 | 24.4 | 13.3 | 4.8 | 37% |
| Transportation (Railroads) | 4 | 25% | 21.5 | 21.5 | 20.5 | 21.5 | 20.9 | 5.8 | 29% |
| Trucking | 26 | 50% | 33.9 | 37.4 | 46.2 | 34.6 | 32.7 | 2.9 | 8% |
| Utility (General) | 14 | 0% | 21.0 | 19.9 | 18.1 | 21.7 | 20.5 | 1.8 | 10% |
| Utility (Water) | 14 | 29% | 22.0 | 23.1 | 21.3 | 22.0 | 20.2 | 2.0 | 10% |
| Total Market | 5994 | 57% | 72.2 | 57.9 | 27.7 | 34.2 | 26.6 | 4.6 | 17% |
| Total Market (without financials) | 4822 | 64% | 60.3 | 61.6 | 30.9 | 38.6 | 28.9 | 5.1 | 16% |

注: 上表の一部の「その他」対応表の一部セル（Real Estate 行のPER範囲等）は複数業種の混在値で、REIT系のPERは本来不適切。上の全業種表が一次転記。
