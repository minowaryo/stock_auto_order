"""Pre-screen: Claude Haiku 4.5 on Japanese financial aspect sentiment (chABSA, CC BY 4.0).

Runs via `claude -p` (subscription login, no API key). Items are batched (10 per call)
and each batch is sampled K times with shuffled order; the share of votes per label is
used as the probability. Two instruction variants: V1 = Japanese, V2 = English.
"""
import json
import random
import subprocess
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

HERE = Path(__file__).resolve().parents[2] / "storage" / "app" / "qualitative-eval" / "chabsa"  # gitignored
DATA = HERE / "chABSA.json"
OUT = HERE / "runs.jsonl"
RUN_DIR = HERE / "run"  # empty dir so no CLAUDE.md is picked up
LABELS = ["positive", "negative", "neutral"]
N_PER_LABEL = {"positive": 45, "negative": 45, "neutral": 30}
BATCH = 10
K = 5
SEED = 20261007

PROMPTS = {
    "V1": (
        "あなたは日本企業の有価証券報告書を読む証券アナリストです。"
        "各項目の文の中で、指定された対象（target）について述べられている状況が、"
        "会社や経済にとって好ましいなら positive、好ましくないなら negative、"
        "どちらでもない・事実の記述のみなら neutral を選んでください。"
        "各項目について、選んだラベルが正しい確率（0〜1）も答えてください。JSONのみで答えること。"
    ),
    "V2": (
        "You are a securities analyst reading Japanese annual securities reports. "
        "For each item, judge the situation described in the sentence about the given target: "
        "positive if it is favorable for the company or economy, negative if unfavorable, "
        "neutral if neither or purely factual. Also give the probability (0-1) that your label "
        "is correct. Answer with JSON only."
    ),
}

SCHEMA = {
    "type": "object",
    "properties": {
        "answers": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "id": {"type": "string"},
                    "label": {"type": "string", "enum": LABELS},
                    "prob": {"type": "number"},
                },
                "required": ["id", "label", "prob"],
            },
        }
    },
    "required": ["answers"],
}


def load_items():
    if not DATA.exists():
        url = "https://huggingface.co/datasets/TheFinAI/jp-chABSA/resolve/main/chABSA.json"
        urllib.request.urlretrieve(url, DATA)
    raw = json.loads(DATA.read_text(encoding="utf-8"))
    rows = raw if isinstance(raw, list) else raw.get("data", raw)
    rng = random.Random(SEED)
    by_label = {l: [] for l in LABELS}
    seen = set()
    for i, r in enumerate(rows):
        key = (r["sentence"], r["target"])
        if key in seen or r["polarity"] not in by_label or len(r["sentence"]) > 300:
            continue
        seen.add(key)
        by_label[r["polarity"]].append({"id": f"c{i}", **r})
    items = []
    for l, n in N_PER_LABEL.items():
        items += rng.sample(by_label[l], n)
    rng.shuffle(items)
    return items


def call_claude(variant, batch, sample_idx):
    rng = random.Random(f"{SEED}-{variant}-{sample_idx}-{batch[0]['id']}")
    order = batch[:]
    rng.shuffle(order)
    body = "\n".join(
        json.dumps({"id": it["id"], "target": it["target"], "sentence": it["sentence"]}, ensure_ascii=False)
        for it in order
    )
    cmd = [
        "claude", "-p", "--model", "haiku", "--output-format", "json",
        "--json-schema", json.dumps(SCHEMA), "--system-prompt", PROMPTS[variant],
        "--tools", "", "--setting-sources", "", "--strict-mcp-config", "--no-session-persistence",
    ]
    for attempt in range(3):
        t0 = time.time()
        p = subprocess.run(cmd, input=body, capture_output=True, text=True, encoding="utf-8", cwd=RUN_DIR, timeout=300)
        try:
            res = json.loads(p.stdout)
            answers = res["structured_output"]["answers"]
            return {
                "variant": variant, "sample": sample_idx, "batch_first": batch[0]["id"],
                "answers": answers, "latency_s": round(time.time() - t0, 1),
                "cost_usd_list": res.get("total_cost_usd"), "error": None,
            }
        except Exception as e:  # noqa: BLE001
            err = f"{type(e).__name__}: {e}; stderr={p.stderr[:300]}; stdout={p.stdout[:300]}"
            time.sleep(5 * (attempt + 1))
    return {"variant": variant, "sample": sample_idx, "batch_first": batch[0]["id"], "answers": [], "error": err}


def main():
    HERE.mkdir(parents=True, exist_ok=True)
    RUN_DIR.mkdir(exist_ok=True)
    items = load_items()
    (HERE / "items.json").write_text(json.dumps(items, ensure_ascii=False, indent=1), encoding="utf-8")
    batches = [items[i:i + BATCH] for i in range(0, len(items), BATCH)]
    jobs = [(v, b, s) for v in PROMPTS for b in batches for s in range(K)]
    done = set()
    if OUT.exists():
        for line in OUT.read_text(encoding="utf-8").splitlines():
            r = json.loads(line)
            if not r["error"]:
                done.add((r["variant"], r["batch_first"], r["sample"]))
    jobs = [j for j in jobs if (j[0], j[1][0]["id"], j[2]) not in done]
    print(f"items={len(items)} batches={len(batches)} pending_calls={len(jobs)}", flush=True)
    with ThreadPoolExecutor(max_workers=4) as ex, OUT.open("a", encoding="utf-8") as f:
        for n, r in enumerate(ex.map(lambda j: call_claude(*j), jobs), 1):
            f.write(json.dumps(r, ensure_ascii=False) + "\n")
            f.flush()
            print(f"{n}/{len(jobs)} {r['variant']} s{r['sample']} err={bool(r['error'])}", flush=True)


if __name__ == "__main__":
    sys.exit(main())
