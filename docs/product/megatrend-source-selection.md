# メガトレンド候補の無料情報源と調査カード（CHG-0031）

> 状態: 手動試行の方針を2026-10-03に確認。Gate 1承認済み（2026-10-03、正式反映10-04）。共通処理のGate 2・Gate 3・Gate 4は2026-10-04本人承認済み。アプリはGreen実装中で、情報源の自動登録は対象外。

## 目的と選定方法

現行[UC-012](use-cases.md)は登録済みの楽天証券お気に入り銘柄を分析する。市場から未知の銘柄を調査候補として拾う範囲は、CHG-0031で[要件 F-016](requirements.md)と[UC-016](use-cases.md)に追加された。この台帳は[ADR-0022](../adr/ADR-0022-megatrend-source-radar.md)とともに、情報源の品質を見極める手動試行を定義する。

採用条件は、無料で継続閲覧できること、発行主体・発表日・元URLを追えること、今回のテーマとの関係が明瞭なこと、銘柄へつなぐ経路があること、取得条件を守れること。発行者の権威に加え、**その資料が直接証明する範囲**を評価する。需要予測、採択、規制承認、受注、売上を同じ証拠にしない。

以下は試行対象を選ぶための候補台帳。初回の試行で定期確認するのはS03・S09・S10・H01・H02の5源で、ほかは必要に応じた照合先に留める。自動取得が可能と明記したもの以外はブラウザで手動確認する。**台帳への記載はアプリへの実装済み登録を意味しない。**

## 一次情報の候補（12系統）

| ID | 領域・情報源 | 無料の取得経路と頻度 | 使い道・証明できないこと |
|---|---|---|---|
| S01 | 半導体: [SEMIプレス](https://www.semi.org/en/news-media-press/semi-press-releases/press-archive) | 公開記事・[メール](https://discover.semi.org/join-media-list-registration.html)、月次確認 | 装置投資の変化。詳細統計は有料で、個社受注は示さない。 |
| S02 | 半導体: [NIST CHIPS採択・交付先](https://www.nist.gov/chips/funding) | 公開ページを発表時に手動確認 | 工場・材料・装置にかかわる事業者を発見。交付と売上を区別する。 |
| S03 | AI基盤: [Open Compute ProjectのAI/製品掲載](https://www.opencompute.org/ai-marketplace) | 公開ページを月次手動確認 | 電源、冷却、光接続など周辺工程を把握する。[Marketplace掲載](https://www.opencompute.org/marketplace)は承認されたSolution Providerによる製品・サービス紹介で、顧客採用や販売実績の証明ではない。 |
| S04 | 日本の電源: [OCCTO長期脱炭素電源オークション](https://www.occto.or.jp/various/capacity-market/jitsujukyukanren/2025_boshuyoukou_long.html) | 公開PDFを結果発表時に手動確認 | [落札一覧](https://www.occto.or.jp/assets/various/capacity-market/jitsujukyukanren/2025_boshuyoukou_long/260513_longauction_youryouyakujokekka_kouhyou_besshi_ousatsu2025.pdf)に事業者・電源・容量。建設・稼働・利益は別途確認。 |
| S05 | 日本の蓄電: [SII系統用蓄電池交付決定](https://sii.or.jp/chikudenchi07/decision.html) | 公開PDFを公募結果ごとに手動確認 | [交付先一覧](https://sii.or.jp/chikudenchi07/uploads/R7kess_koufukettei.pdf)に事業者・地域・補助額。補助先と機器納入者を混同しない。 |
| S06 | 米国の電源・蓄電: [EIA-860M](https://www.eia.gov/electricity/data/eia860m/index.php) | 公式XLS、月次 | 発電・蓄電設備の事業者と計画・稼働状態。月次値は暫定で、計画は建設確約ではない。 |
| S07 | 日本のロボット: [JARA四半期統計](https://www.jara.jp/data/quarterly.html) | 公開PDF/Excel、四半期 | 受注・生産・出荷の方向。会員調査の集計値であり、個社の受注ではない。 |
| S08 | 北米のロボット: [A3受注統計の公開概要](https://www.automate.org/robotics/industry-statistics/robot-orders-increase-in-q2-as-automation-demand-broadens-across-industries) | 無料記事、四半期 | 北米の需要先産業と受注の方向。詳細データは別条件、個社別受注ではない。 |
| S09 | 産業技術: [NEDO公募・採択結果](https://www.nedo.go.jp/koubo/2026_list_05_03.html) | 公開ページを採択時に手動確認 | 研究・実証を担う企業名を発見。採択は商用受注ではない。 |
| S10 | 米国医療AI: [FDA AI医療機器一覧](https://www.fda.gov/medical-devices/artificial-intelligence-enabled-medical-devices/list-artificial-intelligence-enabled-medical-devices) | 公式CSV/Excel/XML、更新時 | 製品と企業名を発見。FDA自身が非網羅と明記。認可は臨床効果・導入・売上を保証しない。 |
| S11 | 日本医療AI: [PMDA医療機器承認品目一覧](https://www.pmda.go.jp/review-services/drug-reviews/review-information/devices/0018.html) | 公開表・PDF、掲載時に手動確認 | 製品と承認取得者を発見。AIの包括タグはなく審査資料を個別に読む。 |
| S12 | 医療AIの臨床: [ClinicalTrials.gov](https://clinicaltrials.gov/data-api/api) | 公式API v2/CSV、週次 | 試験設計、主要評価項目、結果登録。登録・試験終了だけでは有効性を証明しない。 |

初回5源以外は4週間で採否を判定しない。公開頻度の低い資料を新着の有無だけで評価せず、固定した過去12か月のサンプルも確認する。個社につながらない統計はテーマの強弱を測る用途に限定する。

S04・S05・S08・S09は年度・四半期でURLが変わる資料の**現行例**を載せた。次回の確認時は同じ発行主体の新しい公表ページを探し、調査カードに実際に読んだ版のURL・発表日を残す。補助的に[SIAの月次市場データ](https://www.semiconductors.org/policies/market-data/)・[SEAJの国内装置統計](https://www.seaj.or.jp/statistics/)を需要の方向確認に使えるが、詳細データは有料・個社売上は分からないため定期12系統には入れていない。

## 候補が出た時の照合先（7系統）

| ID | 情報源 | 無料の取得経路 | 照合事項 |
|---|---|---|---|
| V01 | [SEC EDGAR](https://www.sec.gov/search-filings/edgar-application-programming-interfaces) | 公式JSON API、鍵不要。[公平アクセス規則](https://www.sec.gov/about/webmaster-frequently-asked-questions)を守る | 米上場主体・ティッカー・事業・設備投資・部門売上。製品単位の売上が非開示なら未確認。 |
| V02 | [JPX適時開示（TDnet）](https://www.jpx.co.jp/equities/listing/disclosure/tdnet/index.html) | 最新31日分を手動閲覧。[公開画面の自動収集は控える](https://www.jpx.co.jp/listing/disclosure/01.html) | 国内の会社コード、受注、提携、業績修正。[公式APIは有料](https://www.jpx.co.jp/markets/paid-info-listing/tdnet/02.html)。 |
| V03 | [EDINET](https://disclosure2.edinet-fsa.go.jp/) | 公開画面、[無償の公式API](https://disclosure2dl.edinet-fsa.go.jp/guide/static/disclosure/download/ESE140191.pdf)（アカウント登録・[APIキー](https://disclosure2dl.edinet-fsa.go.jp/guide/static/disclosure/download/ESE140206.pdf)が必要）。将来の正規自動取得候補 | 日本の法定開示書類から上場主体、事業セグメント、子会社を照合。TDnetの適時開示とは対象書類が異なり、受注・提携等の全件を代替しない。 |
| V04 | [FDA/openFDA医療機器API](https://open.fda.gov/apis/device/)・[PMDA製品検索](https://www.pmda.go.jp/PmdaSearch/kikiSearch/) | 公式API／個別手動検索 | 医療AIの製品、使用目的、規制状態。規制承認と保険償還を分ける。 |
| V05 | [厚労省・中医協](https://www.mhlw.go.jp/stf/shingi/shingi-chuo_128154.html)・[CMS Coverage Database](https://api.coverage.cms.gov/docs/swagger/index.html) | 公開PDF／公式API（CMSは一部に規約同意トークン） | 日米の保険適用条件。給付対象でも製品の採用数・売上は不明。 |
| V06 | [EIA-860確定版](https://www.eia.gov/electricity/data/eia860/index.php)・[EIA-923](https://www.eia.gov/electricity/data/eia923/) | 公式ZIP | 米国電源案件の設備状態・事後稼働。機器供給者は通常分からない。 |
| V07 | 上場企業の公式IR・決算資料（候補ごとに発行企業の公式URLを記録） | 企業サイトを手動閲覧 | 上場主体・事業セグメント・売上・見通し・逆風を照合する。当事者資料なので、顧客採用や競争優位の主張は独立確認する。 |

## 専門家・メディア: 仮説を得る5源

| ID | 公開範囲 | 向くテーマ | 取扱い |
|---|---|---|---|
| H01 | [ものづくり太郎の公開YouTube](https://www.youtube.com/channel/UCY9KXoezyo6cp-YwguOOCcg)、[公式RSS](https://www.youtube.com/feeds/videos.xml?channel_id=UCY9KXoezyo6cp-YwguOOCcg) | 日本の製造装置、部材、工場自動化 | RSSは公開日・URLの手動照合用。全動画をテーマ候補として数えない。企業との協業・スポンサー企画があるため、紹介企業の評価を一次資料で照合。 |
| H02 | [中島聡さんの公開note](https://note.com/lifeisbeautiful/all)、[公式RSS](https://note.com/lifeisbeautiful/rss) | AIの利用形態と技術転換 | RSSは公開日・URLの手動照合用。記事日付と参照した元発表日を分ける。有料メルマガ本文は無料取得源に含めない。本人の購読内容は本人が私的に要点を手入力する場合のみ。 |
| H03 | [SemiAnalysisの公開記事](https://newsletter.semianalysis.com/about) | HBM、AI計算、電力・ネットワーク制約 | 無料記事と有料分析を区別し、個社の数字は開示資料で再確認。 |
| H04 | [Semiconductor Engineering](https://semiengineering.com/about-us/)の無料記事 | 半導体工程・設計・製造の変化 | 独立編集の記事とスポンサー掲載を区別する。記事が引用する原資料を確認。 |
| H05 | [Eric TopolのGround Truths](https://erictopol.substack.com/newsletters)の公開記事 | 医療AIの臨床・研究品質 | 論文・臨床試験・FDA/PMDA原資料で照合。臨床上の有望性を売上とみなさない。 |

[Asianometryの公開YouTube](https://www.youtube.com/@Asianometry)、[Benedict Evansの無料週刊版](https://www.ben-evans.com/newsletter/)は補助候補。Asianometryのニュースレターは[2025年に有料バンドルへ移行](https://www.asianometry.com/p/an-interview-with-stratechery-a-new)したため無料登録先にはしない。SNSはX、Bluesky、LinkedIn等で人が注目した投稿URLをカードへ手動記録する。再投稿・動画・ニュースレターが同じ発表を扱えば、根拠は原発表1件と数える。

### SNSの通知先（投稿そのものは証拠にしない）

| 通知先 | 発見する変化 | 元資料への戻り先 |
|---|---|---|
| [FDA医療機器部門のX](https://x.com/FDADevices) | 米国の承認・安全性・規制更新 | [FDAが公式アカウントとして掲載](https://www.fda.gov/news-events/interactive-and-social-media)。製品一覧・規制文書を確認 |
| [IFRのLinkedIn](https://www.linkedin.com/company/international-federation-of-robotics/) | ロボット市場・各国の発表 | [IFRのプレス](https://ifr.org/ifr-press-releases/)とJARA/A3の原統計を確認 |
| [IEAのLinkedIn](https://www.linkedin.com/company/international-energy-agency) | 電力・データセンターの新しい報告 | [IEAの原報告](https://www.iea.org/reports/energy-and-ai)を確認 |
| [IEEE SpectrumのBluesky](https://bsky.app/profile/spectrum.ieee.org) | 研究・工学の盲点 | リンク先の論文、企業・規制・決算資料を確認 |

[Blueskyの公開GET API](https://docs.bsky.app/docs/api/app-bsky-feed-get-author-feed)は認証なしで読めるが、初期試行は手動URL記録に留める。[LinkedInの投稿API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-09)は他者投稿の閲覧権限に制限があり、無料の全量取得経路とみなさない。SNSの独立性は投稿者数ではなく、**元の出来事が異なるか**で判定する。

## 今回は採用しないものと制約

- [J-Quants Free](https://jpx-jquants.com/?lang=ja)の株価は12週間遅延し、今週の日本株ランキングに使えない。
- [Alpha Vantageランキング](https://www.alphavantage.co/documentation/)は米国株の上位20件ずつの入口に限られ、[無料枠25リクエスト/日](https://www.alphavantage.co/support/)で全銘柄の深掘りも難しい。補助的な話題検出に留める。
- IFR年次統計とJARA/A3統計を**独立した複数証拠**として加算しない。IFRの集計には各国・地域の統計が含まれる。[経産省生産動態統計](https://www.meti.go.jp/statistics/tyo/seidou/result/ichiran/08_seidou.html)は日本の品目別生産を補足するが個社別受注ではない。
- [IEA Energy and AI](https://www.iea.org/reports/energy-and-ai)、[PJM負荷見通し](https://www.pjm.com/planning/resource-adequacy-planning/load-forecast-dev-process)、[OCCTO需要想定](https://www.occto.or.jp/various/kyoukei/torimatome/260330_kyokyukeikaku_torimatome_1.html)は重要な需要背景として随時読むが、企業発見や個社受注を直接示さない。
- 有料のSEMI/WSTS/SEAJ詳細統計、有料メルマガ本文、X/LinkedIn全量取得、TDnet公開画面のスクレイピングは無料の定期取得源として登録しない。YouTubeの[公式投稿一覧API](https://developers.google.com/youtube/v3/docs/playlistItems/list)は投稿発見に使えるが、[他人の動画の字幕ダウンロードは編集権限が必要](https://developers.google.com/youtube/v3/docs/captions/download)。

## 運用と採否判定

各発見は[調査カードのテンプレート](megatrend-research-card-template.md)に1件ずつ記録する。`テーマの変化 → 工程・製品 → 事業者 → 上場親会社とティッカー → 採用/受注/売上 → 反証`を追い、企業名や親会社が曖昧なら未確定のまま止める。候補の掲載は売買推奨ではない。

初回の手動試行では、[試行ログ](../investment-research/megatrend/source-pilot-log.md)でS03・S09・S10・H01・H02の固定した過去12か月サンプルと4週新着を分け、`新しい上場企業数 / 元資料到達数 / 既存ウォッチリストとの非重複数 / 銘柄誤同定数 / 反証発見数 / 調査時間`を記録する。実績が乏しい源は公表時だけの確認に下げるか入れ替える。EDINETは正規の自動取得候補だが、キー管理・書類種別・取得頻度・利用条件を試行後に別途設計する。

**UC-012への受け渡し**: 企業と上場主体・銘柄コードが確認でき、元の一次資料と反証がカードに残り、本人が「監視する」と判断した時に限る。UC-016からの直接登録はGate 2承認済み。本人確認前の自動昇格は行わず、保存形式は本人がGate 3承認した[ADR-0025](../adr/ADR-0025-research-candidate-storage.md)と[データモデル](../architecture/data-model.md)に従う。

**変更案件の次段階**: [Gate 1要件草案](megatrend-discovery-gate1-requirements-draft.md)を本人が承認し、`requirements.md`のF-016へ反映した。4週試行の最低条件は**情報源の継続・定期取得対象の採否**に使い、Gate 1審議の前提条件にしない。Gate 1承認後、Gate 2では情報源に依存しない候補の記録・同定・人手確認を先に定義できる。源固有の取得フローと既存候補ダイジェストは試行結果を見て別途判断する。Gate 3では必要なカード/出典/企業対応の保存形式を設計し、Gate 4では出典重複、上場親会社誤同定、本人未確認の自動昇格を防ぐテストを承認後に実装する。[UC-016](use-cases.md#uc-016-市場からの調査候補発見確認)とUC-012受け渡し差分はGate 2承認済み。[保存設計](../architecture/data-model.md)は2026-10-04 Gate 3本人承認済み。今回のモックは本人指示で省略し、品質再精査を[PLAN](../../PLAN.md)に残す。アプリは未実装。
