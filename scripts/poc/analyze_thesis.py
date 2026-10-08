"""Metrics for pre-screen 2 (thesis-break, Q1 format): Haiku and, if present, Jev on the same pairs.

p_yes per pair: Haiku = mean self-reported prob_yes across samples (vote share kept as a side metric);
Jev = mean `noul` across runs. Metrics: ROC-AUC, accuracy at 0.5, Brier, ECE, coverage/precision
in the auto regions (p>=0.9 -> yes, p<=0.1 -> no), misses in the auto-no region, and accuracy by
pair kind (A_break/A_keep test aspect attribution; B_irrelevant; C_break).
"""
import json
import random
from collections import defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parents[2] / "storage" / "app" / "qualitative-eval" / "thesis"
pairs = {p["id"]: p for p in json.loads((HERE / "pairs.json").read_text(encoding="utf-8"))}


def load(name):
    path = HERE / name
    if not path.exists():
        return None
    ps = defaultdict(list)
    for line in path.read_text(encoding="utf-8").splitlines():
        r = json.loads(line)
        if r.get("error"):
            continue
        if "answers" in r:  # haiku batch record
            for a in r["answers"]:
                if a["id"] in pairs:
                    ps[a["id"]].append(float(a["prob_yes"]))
        elif r.get("id") in pairs:  # jev record
            ps[r["id"]].append(float(r["answer"]["noul"]))
    return {k: sum(v) / len(v) for k, v in ps.items()}


def auc(ids, p):
    pos = [p[i] for i in ids if pairs[i]["gold"]]
    neg = [p[i] for i in ids if not pairs[i]["gold"]]
    s = sum((a > b) + 0.5 * (a == b) for a in pos for b in neg)
    return s / (len(pos) * len(neg))


def ece(ids, p, bins=5):
    tot = 0.0
    for b in range(bins):
        lo, hi = b / bins, (b + 1) / bins
        sel = [i for i in ids if lo <= p[i] < hi or (b == bins - 1 and p[i] == 1.0)]
        if sel:
            tot += len(sel) / len(ids) * abs(sum(pairs[i]["gold"] for i in sel) / len(sel) - sum(p[i] for i in sel) / len(sel))
    return tot


def ci(ids, fn, B=1000):
    rng = random.Random(1)
    v = sorted(fn([rng.choice(ids) for _ in ids]) for _ in range(B))
    return round(v[25], 3), round(v[974], 3)


report = {}
for label, fname in [("haiku", "runs_haiku.jsonl"), ("jev_ja", "runs_jev_ja.jsonl"), ("jev_en", "runs_jev_en.jsonl")]:
    p = load(fname)
    if not p:
        continue
    ids = [i for i in pairs if i in p]
    acc = lambda s: sum((p[i] >= 0.5) == pairs[i]["gold"] for i in s) / len(s)  # noqa: E731
    yes = [i for i in ids if p[i] >= 0.9]
    no = [i for i in ids if p[i] <= 0.1]
    kinds = defaultdict(list)
    for i in ids:
        kinds[pairs[i]["kind"]].append((p[i] >= 0.5) == pairs[i]["gold"])
    report[label] = {
        "n": len(ids), "missing": len(pairs) - len(ids),
        "auc": round(auc(ids, p), 3), "auc_ci95": ci(ids, lambda s: auc(s, p)),
        "accuracy@0.5": round(acc(ids), 3), "accuracy_ci95": ci(ids, acc),
        "brier": round(sum((p[i] - pairs[i]["gold"]) ** 2 for i in ids) / len(ids), 3),
        "ece": round(ece(ids, p), 3),
        "auto_yes(p>=0.9)": {"n": len(yes), "precision": round(sum(pairs[i]["gold"] for i in yes) / max(1, len(yes)), 3)},
        "auto_no(p<=0.1)": {"n": len(no), "misses": sum(pairs[i]["gold"] for i in no)},
        "auto_coverage": round((len(yes) + len(no)) / len(ids), 3),
        "accuracy_by_kind": {k: f"{sum(v)}/{len(v)}" for k, v in sorted(kinds.items())},
        "errors": [{"id": i, "kind": pairs[i]["kind"], "p_yes": round(p[i], 2), "target": pairs[i]["target"],
                    "sentence": pairs[i]["sentence"][:100]} for i in ids if (p[i] >= 0.5) != pairs[i]["gold"]],
    }
(HERE / "report.json").write_text(json.dumps(report, ensure_ascii=False, indent=1), encoding="utf-8")
for k, v in report.items():
    print(k, json.dumps({kk: vv for kk, vv in v.items() if kk != "errors"}, ensure_ascii=False))
    for e in v["errors"]:
        print("   x", e["kind"], e["p_yes"], e["target"], "|", e["sentence"])
