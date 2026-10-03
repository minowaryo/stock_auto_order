# US sector/industry ETF PER/PBR cross-check (all as of 2026-10-01 unless noted)

Method: WebFetch of official fund pages (ssga.com, ishares.com). WebFetch summarises pages with a small model; values transcribed from its output, not independently re-read from PDFs. Vendor numbers are fund-level holdings aggregates, NOT sector medians.

## Definitions
- SSGA: "FY1" P/E = weighted harmonic average of price / forecast 1-yr EPS (forward). "Price/Earnings" (Index Characteristics) = index trailing P/E (SSGA page does not print the formula for this one; treat as trailing). P/B = weighted harmonic avg of price / latest book value (LTM).
- iShares: page shows only "P/E Ratio" / "P/B Ratio" under Portfolio Characteristics with NO definition on the fetched page (iShares generally = trailing, harmonic-type; unverified here, fact-sheet PDF unreadable).
- Pitfall: iShares IVV P/E 30.16 vs SSGA SPY index trailing P/E 24.62 for the same S&P 500 -> vendor definitions differ by ~5 pts (earnings basis / negative-earnings handling). Do NOT mix vendors in one comparison; use one vendor per ETF family, or compare only within-vendor.

## A. SPDR Select Sector ETFs (SSGA)  URL: https://www.ssga.com/us/en/intermediary/etfs/<fund page>
| ETF | Sector | Fwd P/E (FY1) | Trailing P/E | P/B |
|---|---|---|---|---|
| XLK | Technology | 24.79 | 33.90 | 12.58 |
| XLF | Financials | 15.00 | 15.19 | 2.27 |
| XLV | Health Care | 19.83 | 30.25 (*) | 4.68 |
| XLY | Cons. Discretionary | 21.65 | 25.34 | 5.60 |
| XLP | Cons. Staples | 19.06 | 23.95 | 4.47 |
| XLE | Energy | 12.20 | 17.80 | 2.54 |
| XLI | Industrials | 24.61 | 28.58 | 6.64 |
| XLB | Materials | 16.80 | 22.70 | 2.57 |
| XLU | Utilities | 16.57 | 17.89 | 2.02 |
| XLRE | Real Estate | 32.44 | 30.56 | 2.56 (P/E not meaningful for REITs; FFO-based would be better) |
| XLC | Comm. Services | 13.49 (**) | 16.03 | 3.25 |
| SPY (S&P 500) | Market | 20.43 | 24.62 | 5.31 |
(*) XLV: forward 19.83 vs trailing 30.25 is a large gap; plausible (one-off charges) but unverified. (**) XLC forward 13.49 re-fetched and confirmed as printed; low vs trailing 16.03, treat with caution.
URLs: .../the-<technology|financial|health-care|consumer-discretionary|consumer-staples|energy|industrial|materials|utilities|real-estate|communication-services>-select-sector-spdr-fund-<xlk|xlf|xlv|xly|xlp|xle|xli|xlb|xlu|xlre|xlc>; SPY: .../spdr-sp-500-etf-trust-spy

## B. SPDR S&P industry ETFs (SSGA, equal-weight style indices)
| ETF | Industry | Fwd P/E | Trailing P/E (index) | P/B |
|---|---|---|---|---|
| XAR | Aerospace & Defense | 25.93 | 31.52 | 3.85 |
| XSD | Semiconductors | 27.01 | 40.72 | 5.60 |
| XSW | Software & Services | 17.71 | 26.19 | 3.99 |
| XME | Metals & Mining | 14.42 | 17.92 | 2.16 |
| XRT | Retail | 13.76 | 15.36 | 2.27 |
| XBI | Biotech | 20.22 | 21.07 | 4.88 |
| XPH | Pharmaceuticals | 10.25 | 15.39 | 2.95 |
| XTN | Transportation | 20.11 | 17.19 | 2.44 |
| XHB | Homebuilders | 17.10 | 18.61 | 2.10 |
| XES | Oil&Gas Equip/Services | 22.56 | 19.95 | 1.78 |
| KBE | Banks | 10.97 | 12.39 | 1.24 |
URL pattern: https://www.ssga.com/us/en/intermediary/etfs/spdr-sp-<aerospace-defense|semiconductor|software-services|metals-mining|retail|biotech|pharmaceuticals|transportation|homebuilders|oil-gas-equipment-services|bank>-etf-<ticker>
Note: equal-weighted indices -> closer to a "typical constituent" than cap-weighted funds (good for a sector baseline), but small-cap heavy.

## C. iShares (ishares.com/us/products/<id>/...)  P/E & P/B, undefined on page
| ETF | Industry | P/E | P/B | ID |
|---|---|---|---|---|
| ITA | Aerospace & Defense | 34.49 | 5.84 | 239502 |
| SOXX | Semiconductors | 68.66 | 11.84 | 239705 |
| IGV | Software (+interactive media) | 35.51 | 7.08 | 239771 |
| IYT | Transportation (airlines/rail/trucking) | 19.30 | 4.04 | 239501 |
| IHE | Pharmaceuticals | 25.98 | 5.14 | 239519 |
| PICK | Global Metals & Mining producers (non-US heavy, ex gold/silver) | 21.02 | 2.17 | 239655 |
| IYW | US Technology | 41.29 | 12.52 | 239522 (page fetched under this id returned IYW) |
| IAI | Broker-Dealers & Exchanges | 22.61 | 3.45 | 239504 |
| ITB | Home Construction | 13.35 | 1.78 | 239512 |
| IVV | S&P 500 | 30.16 | 5.68 | 239726 |
| IYY | Dow Jones US (total market) | 29.62 | 5.39 | 239513 |
(iShares P/E appears to be on a different (higher) earnings basis than SSGA trailing: IVV 30.16 vs SPY 24.62.)
iShares-vs-SSGA comparable pairs: Semis SOXX 68.66 (cap-wtd, NVDA-heavy) vs XSD 40.72; A&D ITA 34.49 vs XAR 31.52; Software IGV 35.51 vs XSW 26.19.

## D. Third-party (not official) -- low confidence
- PAVE (Global X, globalxetfs.com/funds/pave/, as of 2026-10-02): P/E 29.28 (labelled 2025) / 24.27 (labelled 2026), P/B 3.86 / 3.47. Column meaning (trailing vs forward) ambiguous -> likely 2025 = trailing, 2026 = forward estimate; unverified.
- SMH (VanEck): official page failed (redirect loop). Via search snippet (aggregator, date unknown): P/E 37.21, P/B 11.12. Treat as reference only.
- QQQ / Nasdaq-100: Invesco pages did not load values. Snippet (Yahoo/Investing aggregator): QQQ P/E TTM 30.55, price 753.41 on 2026-10-02. P/B: 取得不可. Vanguard (VGT etc.): 取得不可 (page rendered title only). iShares Medical Devices (IHI): 取得不可 (wrong id fetched). S&P DJI sp-500 page: HTTP 403 (取得不可). MSCI/FTSE Russell factsheets: not attempted (time).

## E. Market-wide long-run reference (multpl.com, secondary source built on Shiller & S&P data), 2026-10-02
- S&P 500 P/E (TTM as-reported): current 26.34; mean 16.23; median 15.08 (since 1871; min 5.31 Dec 1917, max 123.73 May 2009). https://www.multpl.com/s-p-500-pe-ratio
- S&P 500 P/B: current 6.13; mean 3.16; median 2.91 (min 1.78 Mar 2009). Note says book value "as of September, 2025" (stale) and max listed 5.39 < current 6.13 -> stats inconsistent; treat P/B history as rough. https://www.multpl.com/s-p-500-price-to-book
- Caveat: long-run mean covers 1871+ ; modern (post-1990) averages are higher. Not an official S&P DJI value. Current SSGA/iShares S&P 500 values (20.43 fwd / 24.62 or 30.16 trailing / 5.3-5.7 P/B) vs. multpl 26.34 show source-to-source spread of several points.

## F. Finnhub industry -> ETF mapping (my judgment; Finnhub industry names are GICS-like, hence ambiguous)
| Finnhub industry | Best ETF proxy (SSGA / iShares) | Fit |
|---|---|---|
| Aerospace & Defense | XAR / ITA | Good |
| Automobiles | XLY (no pure ETF; CARZ/ Tesla-heavy) | Weak: XLY dominated by AMZN/TSLA |
| Beverages | XLP (staples) | Weak: no beverage ETF fetched |
| Electrical Equipment | XLI; (PAVE partial) | Weak |
| Media | XLC | Medium (XLC mixes telecom/interactive media) |
| Metals & Mining | XME / PICK / XLB | Good (XME US-based, PICK global) |
| Pharmaceuticals | XPH / IHE; XLV broad | Good. XPH 10.25 fwd vs IHE 25.98 PE: large spread, constituent mix (JNJ+LLY in IHE) |
| Retail | XRT; XLY | Good |
| Road & Rail | XTN / IYT | Good |
| Semiconductors | XSD / SOXX / SMH | Good; cap-weighted (SOXX) is very high due to mega-caps |
| Technology | XLK / IYW / IGV (software) | Medium; Finnhub "Technology" is a catch-all (hardware/software/IT services) |
| Utilities | XLU | Good |
| Banks / Financial Services | KBE / XLF | Good |
| Insurance / Capital markets | XLF; IAI for brokers/exchanges | Medium |
| Biotechnology | XBI | Good |
| Health care (equipment/providers) | XLV | Medium (IHI not obtained) |
| Energy / Oil & Gas | XLE; XES (services) | Good |
| Building / Homebuilding | XHB / ITB | Good |
| Real Estate | XLRE | P/E meaningless; prefer P/B or P/FFO |
| Communications / Telecom | XLC | Medium |
| Consumer products/Food | XLP | Medium |
| Machinery / Industrial conglomerates / Airlines etc. | XLI / XTN | Medium |
| Chemicals / Packaging / Paper | XLB | Medium |

## Confidence & cautions
- High: SSGA values (11 sector ETFs + SPY + XAR etc.): official source, explicit weighted-harmonic definition, same-day basis.
- Medium: iShares values (official, but definition not shown; apparently different earnings basis than SSGA).
- Low: PAVE, SMH, QQQ (aggregators/ambiguous).
- Fund aggregates are weighted harmonic means -> dominated by mega-caps for cap-weighted funds (XLK, XLC, XLY, SOXX); NOT comparable to a median sector multiple (Damodaran-style). For a "typical stock in industry" baseline prefer the equal-weight SPDR industry ETFs (section B).
- Dates look like the system date (2026-10-01) so data is current; the fetch tool summarises with a small model, so a spot-check of key numbers against the live pages is advisable before using in production.
