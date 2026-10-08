"""Pre-screen 2: thesis-break question (Q1 format) on pairs built from chABSA (CC BY 4.0).

Why: pre-screen 1 found an aspect-attribution error (the model followed the sentence's overall
tone instead of the named target). Q1 has the same risk, so this builds pairs that test it:
  A_break / A_keep : the same sentence holds a positive and a negative target. The thesis names
                     one of them, so the gold answer flips with the thesis (counterfactual pairs).
  B_irrelevant     : the thesis names a target that does not appear in the sentence (gold: No).
  C_break          : single-target negative sentences (gold: Yes).
Thesis template: 「{target}の状況は良好で、改善が続いている。」 (a stand-in for the app's
auto-generated theses, proposal section 3.3).

Runs Claude Haiku 4.5 via `claude -p` (subscription, no API key); 10 pairs per call, K samples.
The same pairs file is used by jev_eval.py so Jev can be compared on the development PC.
"""
import json
import random
import subprocess
import sys
import time
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / "storage" / "app" / "qualitative-eval"
SRC = ROOT / "chabsa" / "chABSA.json"  # downloaded by prescreen_chabsa.py
HERE = ROOT / "thesis"
PAIRS = HERE / "pairs.json"
OUT = HERE / "runs_haiku.jsonl"
SEED = 20261008
N_A, N_B, N_C = 30, 20, 20
BATCH, K = 10, 3

THESIS = "「{target}」の状況は良好で、改善が続いている。"
SYSTEM = (
    "あなたは個人投資家の投資仮説を点検するアナリストです。各項目について、文（sentence）の情報が、"
    "投資仮説（thesis）を損なう（仮説が正しい可能性を下げる）内容を含むかを判定してください。"
    "仮説が名指ししている対象についての記述だけで判断し、文全体の印象や別の対象の記述に引きずられないこと。"
    "仮説と関係のない情報は「損なわない」とします。"
    "undermines（真偽）、prob_yes（損なう確率 0〜1）、evidence（判断の根拠となった文中の語句をそのまま引用。なければ空文字）を"
    "JSONのみで答えること。"
)
SCHEMA = {
    "type": "object",
    "properties": {"answers": {"type": "array", "items": {"type": "object", "properties": {
        "id": {"type": "string"}, "undermines": {"type": "boolean"},
        "prob_yes": {"type": "number"}, "evidence": {"type": "string"}},
        "required": ["id", "undermines", "prob_yes", "evidence"]}}},
    "required": ["answers"],
}


def build_pairs():
    rows = json.loads(SRC.read_text(encoding="utf-8"))
    by_sent = defaultdict(list)
    for r in rows:
        if len(r["sentence"]) <= 300:
            by_sent[r["sentence"]].append(r)
    rng = random.Random(SEED)

    def clean_targets(s, polarity):
        # targets whose every annotation in this sentence has the same polarity
        pols = defaultdict(set)
        for x in by_sent[s]:
            pols[x["target"]].add(x["polarity"])
        return sorted(t for t, ps in pols.items() if ps == {polarity})

    mixed = sorted(s for s in by_sent if clean_targets(s, "positive") and clean_targets(s, "negative")
                   and not set(clean_targets(s, "positive")) & set(clean_targets(s, "negative")))
    single_neg = sorted(s for s, v in by_sent.items() if len(v) == 1 and v[0]["polarity"] == "negative")
    pairs = []
    for n, s in enumerate(rng.sample(mixed, N_A)):
        neg = rng.choice(clean_targets(s, "negative"))
        pos = rng.choice(clean_targets(s, "positive"))
        pairs.append({"id": f"A{n}b", "kind": "A_break", "target": neg, "sentence": s, "gold": True})
        pairs.append({"id": f"A{n}k", "kind": "A_keep", "target": pos, "sentence": s, "gold": False})
    used = {p["sentence"] for p in pairs}
    pool = sorted(s for s in by_sent if s not in used)
    n = 0
    while n < N_B:
        s, other = rng.sample(pool, 2)
        tgt = rng.choice(by_sent[other])["target"]
        if tgt in s or any(tgt in x["target"] or x["target"] in tgt for x in by_sent[s]):
            continue
        pairs.append({"id": f"B{n}", "kind": "B_irrelevant", "target": tgt, "sentence": s, "gold": False})
        n += 1
    for n, s in enumerate(rng.sample([s for s in single_neg if s not in used], N_C)):
        pairs.append({"id": f"C{n}", "kind": "C_break", "target": by_sent[s][0]["target"], "sentence": s, "gold": True})
    for p in pairs:
        p["thesis"] = THESIS.format(target=p["target"])
    return pairs


def batches_of(pairs):
    # keep the two halves of each counterfactual pair in different batches
    first = [p for p in pairs if not p["id"].endswith("k")]
    second = [p for p in pairs if p["id"].endswith("k")]
    rng = random.Random(SEED + 1)
    rng.shuffle(first)
    rng.shuffle(second)
    out = []
    for group in (first, second):
        out += [group[i:i + BATCH] for i in range(0, len(group), BATCH)]
    return out


def call(batch, sample):
    rng = random.Random(f"{SEED}-{sample}-{batch[0]['id']}")
    order = batch[:]
    rng.shuffle(order)
    body = "\n".join(json.dumps({"id": p["id"], "thesis": p["thesis"], "sentence": p["sentence"]}, ensure_ascii=False) for p in order)
    cmd = ["claude", "-p", "--model", "haiku", "--output-format", "json", "--json-schema", json.dumps(SCHEMA),
           "--system-prompt", SYSTEM, "--tools", "", "--setting-sources", "", "--strict-mcp-config", "--no-session-persistence"]
    err = None
    for attempt in range(3):
        t0 = time.time()
        p = subprocess.run(cmd, input=body, capture_output=True, text=True, encoding="utf-8", cwd=HERE / "run", timeout=300)
        try:
            res = json.loads(p.stdout)
            return {"sample": sample, "batch_first": batch[0]["id"], "answers": res["structured_output"]["answers"],
                    "latency_s": round(time.time() - t0, 1), "cost_usd_list": res.get("total_cost_usd"), "error": None}
        except Exception as e:  # noqa: BLE001
            err = f"{type(e).__name__}: {e}; stderr={p.stderr[:300]}"
            time.sleep(5 * (attempt + 1))
    return {"sample": sample, "batch_first": batch[0]["id"], "answers": [], "error": err}


def main():
    (HERE / "run").mkdir(parents=True, exist_ok=True)
    if not SRC.exists():
        sys.exit("run prescreen_chabsa.py first (it downloads chABSA.json)")
    pairs = json.loads(PAIRS.read_text(encoding="utf-8")) if PAIRS.exists() else build_pairs()
    PAIRS.write_text(json.dumps(pairs, ensure_ascii=False, indent=1), encoding="utf-8")
    done = set()
    if OUT.exists():
        for line in OUT.read_text(encoding="utf-8").splitlines():
            r = json.loads(line)
            if not r["error"]:
                done.add((r["batch_first"], r["sample"]))
    jobs = [(b, s) for b in batches_of(pairs) for s in range(K) if (b[0]["id"], s) not in done]
    print(f"pairs={len(pairs)} pending_calls={len(jobs)}", flush=True)
    with ThreadPoolExecutor(max_workers=4) as ex, OUT.open("a", encoding="utf-8") as f:
        for n, r in enumerate(ex.map(lambda j: call(*j), jobs), 1):
            f.write(json.dumps(r, ensure_ascii=False) + "\n")
            f.flush()
            print(f"{n}/{len(jobs)} err={bool(r['error'])}", flush=True)


if __name__ == "__main__":
    sys.exit(main())
