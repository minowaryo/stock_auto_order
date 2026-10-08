# scripts/poc — CHG-0049 PoC scripts (outside the app)

Throwaway evaluation scripts for [CHG-0049](../../docs/product/qualitative-signal-proposal.md).
They are not part of the Laravel app, use only the Python 3 standard library, and write their
outputs under `storage/app/qualitative-eval/`, which is gitignored. Results are recorded in
[qualitative-signal-poc-results.md](../../docs/product/qualitative-signal-poc-results.md).

| Script | Purpose | Needs |
|---|---|---|
| `prescreen_chabsa.py` | Pre-screen 1: Claude Haiku 4.5 on 120 sentences of the public chABSA dataset (CC BY 4.0), Japanese vs English instructions, 5 samples each | Claude Code logged in (subscription; no API key) |
| `analyze_chabsa.py` | Metrics for the pre-screen (accuracy, macro-F1, calibration, coverage at high confidence) | Output of the script above |
| `prescreen_thesis.py` | Pre-screen 2: thesis-break question (Q1 format) on 100 pairs built from chABSA, including counterfactual pairs that test aspect attribution | Claude Code logged in; `chabsa/chABSA.json` from `prescreen_chabsa.py` |
| `analyze_thesis.py` | Metrics for pre-screen 2; compares Haiku and Jev when Jev results exist | Output of the scripts above / below |
| `jev_eval.py` | Run Jev on the same items as pre-screens 1 and 2 (Choice with shuffled candidate order / Noul). `--mock` tests the plumbing without a key | `OPENROUTER_API_KEY` (or `TYPESAFE_API_KEY` with `--route direct`) |
| `jquants_light_effect.py` | Estimate what J-Quants Light would change, using Free-plan data only (staleness, judgment flips, forecast revisions, suspected `fetchStatements()` issue) | The Free-plan J-Quants API key the app already uses, and a list of JP codes |

## Usage

```bash
# Pre-screen (about 10-30 minutes; runs `claude -p` in an empty directory with no tools)
python3 scripts/poc/prescreen_chabsa.py
python3 scripts/poc/analyze_chabsa.py

# Pre-screen 2 (thesis format) and the Jev comparison on the same items
python3 scripts/poc/prescreen_thesis.py
export OPENROUTER_API_KEY=...     # development PC only; never commit it
python3 scripts/poc/jev_eval.py thesis --limit 5      # first check the response shape on a few items
python3 scripts/poc/jev_eval.py thesis
python3 scripts/poc/jev_eval.py chabsa
python3 scripts/poc/analyze_thesis.py

# J-Quants Light effect (development PC)
export JQUANTS_API_KEY=...        # same key as the app's .env; never commit it
# codes.txt: one JP code per line, e.g. exported from the app DB:
#   ./vendor/bin/sail mysql -N -e "SELECT DISTINCT symbol_code FROM holdings WHERE market='jp'" > codes.txt
python3 scripts/poc/jquants_light_effect.py codes.txt --out storage/app/qualitative-eval/light_effect.json
```

`holdings.market` is `jp` / `us` / `mutual_fund`. The query lists every JP name ever imported;
filter it further (e.g. current holdings plus watchlist) if needed.
