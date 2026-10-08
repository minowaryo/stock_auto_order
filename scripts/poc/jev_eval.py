"""Run Jev on the same items as the Haiku pre-screens, so the two can be compared directly.

Tasks (inputs are produced by the Haiku scripts and are reused unchanged):
  chabsa : storage/app/qualitative-eval/chabsa/items.json  -> Choice (positive/negative/neutral)
  thesis : storage/app/qualitative-eval/thesis/pairs.json  -> Noul (does the sentence undermine the thesis?)

Routes (see docs/product/qualitative-signal-proposal.md section 2.2):
  openrouter (default): POST https://openrouter.ai/api/alpha/decisions, model typesafe/jev-1.13,
                        key in OPENROUTER_API_KEY
  direct              : POST https://api.typesafe.ai/v1/systemone, model jev-1.13.0, key in TYPESAFE_API_KEY

Choice is run with 3 different candidate orders (Jev leans toward the first candidate); Noul twice.
`--mock` replaces the HTTP call with a fake response to test the plumbing without a key.

  python3 scripts/poc/jev_eval.py chabsa [--route direct] [--lang ja|en] [--limit N] [--mock]
  python3 scripts/poc/jev_eval.py thesis [--route direct] [--limit N] [--mock]
  python3 scripts/poc/analyze_thesis.py      # compares Haiku and Jev on the thesis task
"""
import argparse
import json
import os
import random
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / "storage" / "app" / "qualitative-eval"
ROUTES = {
    "openrouter": ("https://openrouter.ai/api/alpha/decisions", "typesafe/jev-1.13", "OPENROUTER_API_KEY"),
    "direct": ("https://api.typesafe.ai/v1/systemone", "jev-1.13.0", "TYPESAFE_API_KEY"),
}
CHABSA_CRITERIA = {
    "ja": {
        "positive": "対象について述べられている状況が、会社や経済にとって好ましい",
        "negative": "対象について述べられている状況が、会社や経済にとって好ましくない",
        "neutral": "どちらでもない、または事実の記述のみ",
    },
    "en": {
        "positive": "The situation described about the target is favorable for the company or economy",
        "negative": "The situation described about the target is unfavorable for the company or economy",
        "neutral": "Neither, or a purely factual statement",
    },
}
CHABSA_INSTR = {
    "ja": "日本企業の有価証券報告書の文です。文の中で、指定された対象（target）について述べられている状況はどれに当たりますか？",
    "en": "This is a sentence from a Japanese annual securities report. Which option describes the situation stated about the given target?",
}
THESIS_INSTR = {
    "ja": "文（sentence）の情報は、投資仮説（thesis）を損なう（仮説が正しい可能性を下げる）内容を含んでいますか？"
          "仮説が名指ししている対象についての記述だけで判断してください。",
    "en": "Does the information in the sentence undermine the investment thesis (make it less likely to be true)? "
          "Judge only by what the sentence says about the target named in the thesis.",
}
THESIS_CRITERIA = {
    "ja": {"true": "仮説の対象について、悪化・減少・不振など仮説に反する記述がある",
           "false": "仮説の対象について仮説に反する記述がない（好調な記述、または仮説と無関係な情報）"},
    "en": {"true": "The sentence reports deterioration, decline or weakness of the thesis target",
           "false": "No statement against the thesis target (favorable, or unrelated to the thesis)"},
}


def post(route, payload, mock):
    if mock:
        q = next(iter(payload["questions"].values()))
        if q["type"] == "noul":
            return {"answers": {"q": {"type": "noul", "noul": round(random.random(), 3)}}, "usage": {"input_tokens": 300}}
        keys = list(q["criteria"])
        probs = {k: random.random() for k in keys}
        s = sum(probs.values())
        probs = {k: v / s for k, v in probs.items()}
        top = max(probs, key=probs.get)
        return {"answers": {"q": {"type": "choice", "choice": top, "confidence": 0.5, "probabilities": probs}},
                "usage": {"input_tokens": 300}}
    url, _, env = ROUTES[route]
    req = urllib.request.Request(url, data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
                                 headers={"Authorization": f"Bearer {os.environ[env]}", "Content-Type": "application/json"})
    for attempt in range(5):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return json.loads(r.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            if e.code in (429, 529, 500, 502, 503):
                time.sleep(2 ** attempt)
                continue
            raise RuntimeError(f"HTTP {e.code}: {e.read()[:300]!r}") from e
    raise RuntimeError("retries exhausted")


def run(task, route, lang, limit, mock):
    model = ROUTES[route][1]
    if task == "chabsa":
        src = json.loads((ROOT / "chabsa" / "items.json").read_text(encoding="utf-8"))
        out = ROOT / "chabsa" / f"runs_jev_{lang}.jsonl"
        orders = 3
    else:
        src = json.loads((ROOT / "thesis" / "pairs.json").read_text(encoding="utf-8"))
        out = ROOT / "thesis" / f"runs_jev_{lang}.jsonl"
        orders = 2
    if not mock and not os.environ.get(ROUTES[route][2]):
        sys.exit(f"{ROUTES[route][2]} is not set")
    src = src[:limit] if limit else src
    done = set()
    if out.exists() and not mock:
        for line in out.read_text(encoding="utf-8").splitlines():
            r = json.loads(line)
            if not r.get("error"):
                done.add((r["id"], r["order"]))
    with out.open("a" if not mock else "w", encoding="utf-8") as f:
        for it in src:
            for o in range(orders):
                if (it["id"], o) in done:
                    continue
                if task == "chabsa":
                    keys = list(CHABSA_CRITERIA[lang])
                    random.Random(f"{it['id']}-{o}").shuffle(keys)
                    q = {"type": "choice", "instructions": CHABSA_INSTR[lang],
                         "criteria": {k: CHABSA_CRITERIA[lang][k] for k in keys}}
                    state = {"target": it["target"], "sentence": it["sentence"]}
                else:
                    q = {"type": "noul", "instructions": THESIS_INSTR[lang], "criteria": THESIS_CRITERIA[lang]}
                    state = {"thesis": it["thesis"], "sentence": it["sentence"]}
                payload = {"model": model, "state": state, "questions": {"q": q}}
                rec = {"id": it["id"], "order": o, "model": model, "lang": lang, "error": None}
                try:
                    res = post(route, payload, mock)
                    a = res["answers"]["q"]
                    rec.update(answer=a, served_model=res.get("model"), usage=res.get("usage"))
                    if a.get("type") == "noul" and not isinstance(a.get("noul"), (int, float)):
                        rec["error"] = "unexpected noul shape"
                    if a.get("type") == "choice" and not isinstance(a.get("probabilities"), dict):
                        rec["error"] = "unexpected choice shape"
                except Exception as e:  # noqa: BLE001
                    rec["error"] = f"{type(e).__name__}: {e}"
                f.write(json.dumps(rec, ensure_ascii=False) + "\n")
                f.flush()
                time.sleep(0.05)
    print(f"wrote {out}")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("task", choices=["chabsa", "thesis"])
    ap.add_argument("--route", choices=list(ROUTES), default="openrouter")
    ap.add_argument("--lang", choices=["ja", "en"], default="ja")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--mock", action="store_true")
    a = ap.parse_args()
    run(a.task, a.route, a.lang, a.limit, a.mock)
