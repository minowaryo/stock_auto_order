"""Metrics for the chABSA pre-screen: accuracy, macro-F1, confusion, calibration (vote share and
self-reported probability), Brier, ECE, and run-to-run stability. Bootstrap 95% CIs."""
import json
import random
from collections import Counter, defaultdict
from pathlib import Path

HERE = Path(__file__).resolve().parents[2] / "storage" / "app" / "qualitative-eval" / "chabsa"  # gitignored
LABELS = ["positive", "negative", "neutral"]
items = {i["id"]: i for i in json.loads((HERE / "items.json").read_text(encoding="utf-8"))}
runs = [json.loads(l) for l in (HERE / "runs.jsonl").read_text(encoding="utf-8").splitlines()]

votes = defaultdict(lambda: defaultdict(list))  # variant -> id -> [(label, prob)]
errors = Counter()
for r in runs:
    if r["error"]:
        errors[r["variant"]] += 1
        continue
    for a in r["answers"]:
        if a["id"] in items:
            votes[r["variant"]][a["id"]].append((a["label"], float(a["prob"])))


def macro_f1(gold, pred):
    f1s = []
    for l in LABELS:
        tp = sum(g == l and p == l for g, p in zip(gold, pred))
        fp = sum(g != l and p == l for g, p in zip(gold, pred))
        fn = sum(g == l and p != l for g, p in zip(gold, pred))
        prec = tp / (tp + fp) if tp + fp else 0
        rec = tp / (tp + fn) if tp + fn else 0
        f1s.append(2 * prec * rec / (prec + rec) if prec + rec else 0)
    return sum(f1s) / len(f1s)


def ece(conf, correct, bins=5):
    total, n = 0.0, len(conf)
    for b in range(bins):
        lo, hi = b / bins, (b + 1) / bins
        idx = [i for i, c in enumerate(conf) if (lo < c <= hi) or (b == 0 and c == 0)]
        if idx:
            total += len(idx) / n * abs(sum(correct[i] for i in idx) / len(idx) - sum(conf[i] for i in idx) / len(idx))
    return total


def reliability(conf, correct, edges=(0, 0.6, 0.8, 0.99, 1.0)):
    out = []
    for lo, hi in zip(edges, edges[1:]):
        idx = [i for i, c in enumerate(conf) if lo < c <= hi or (lo == 0 and c == 0)]
        if idx:
            out.append({"range": f"({lo},{hi}]", "n": len(idx),
                        "mean_conf": round(sum(conf[i] for i in idx) / len(idx), 3),
                        "accuracy": round(sum(correct[i] for i in idx) / len(idx), 3)})
    return out


def boot_ci(ids, fn, B=1000, seed=1):
    rng = random.Random(seed)
    vals = sorted(fn([rng.choice(ids) for _ in ids]) for _ in range(B))
    return round(vals[int(0.025 * B)], 3), round(vals[int(0.975 * B)], 3)


report = {"errors": dict(errors)}
for v, per in votes.items():
    ids = [i for i in per if len(per[i]) >= 3]

    def stats(sub):
        gold = [items[i]["polarity"] for i in sub]
        pred = [Counter(l for l, _ in per[i]).most_common(1)[0][0] for i in sub]
        return gold, pred

    gold, pred = stats(ids)
    acc_fn = lambda s: sum(g == p for g, p in zip(*stats(s))) / len(s)  # noqa: E731
    f1_fn = lambda s: macro_f1(*stats(s))  # noqa: E731
    vote_conf = [Counter(l for l, _ in per[i]).most_common(1)[0][1] / len(per[i]) for i in ids]
    self_conf = [sum(p for l, p in per[i] if l == pred[k]) / max(1, sum(1 for l, _ in per[i] if l == pred[k])) for k, i in enumerate(ids)]
    correct = [int(g == p) for g, p in zip(gold, pred)]
    # multi-class Brier with vote-share distribution
    brier = sum(
        sum((sum(1 for l, _ in per[i] if l == lab) / len(per[i]) - (items[i]["polarity"] == lab)) ** 2 for lab in LABELS)
        for i in ids
    ) / len(ids)
    unanimous = sum(1 for c in vote_conf if c == 1.0) / len(ids)
    conf_m = {g: Counter(p for gg, p in zip(gold, pred) if gg == g) for g in LABELS}
    report[v] = {
        "n_items": len(ids),
        "samples_per_item_min": min(len(per[i]) for i in ids),
        "accuracy": round(acc_fn(ids), 3), "accuracy_ci95": boot_ci(ids, acc_fn),
        "macro_f1": round(f1_fn(ids), 3), "macro_f1_ci95": boot_ci(ids, f1_fn),
        "confusion_gold_to_pred": {g: dict(c) for g, c in conf_m.items()},
        "per_class_recall": {g: round(conf_m[g][g] / max(1, sum(conf_m[g].values())), 3) for g in LABELS},
        "binary_pos_neg_accuracy": round(
            sum(g == p for g, p in zip(gold, pred) if g != "neutral") / sum(1 for g in gold if g != "neutral"), 3),
        "brier_vote_share": round(brier, 3),
        "ece_vote_share": round(ece(vote_conf, correct), 3),
        "ece_self_reported": round(ece(self_conf, correct), 3),
        "reliability_vote_share": reliability(vote_conf, correct),
        "reliability_self_reported": reliability(self_conf, correct),
        "unanimous_share": round(unanimous, 3),
        "coverage_at_unanimous": {"share": round(unanimous, 3), "accuracy": round(
            sum(c for c, vc in zip(correct, vote_conf) if vc == 1.0) / max(1, sum(1 for vc in vote_conf if vc == 1.0)), 3)},
    }
    report[v]["errors_sample"] = [
        {"id": i, "gold": items[i]["polarity"], "pred": p, "target": items[i]["target"], "sentence": items[i]["sentence"][:120]}
        for i, g, p in zip(ids, gold, pred) if g != p
    ][:12]

# Jev (jev_eval.py chabsa): average the per-candidate probabilities over the shuffled orders
for lang in ("ja", "en"):
    path = HERE / f"runs_jev_{lang}.jsonl"
    if not path.exists():
        continue
    probs = defaultdict(list)
    for line in path.read_text(encoding="utf-8").splitlines():
        r = json.loads(line)
        if not r.get("error") and r["id"] in items:
            probs[r["id"]].append(r["answer"]["probabilities"])
    ids = sorted(probs)
    avg = {i: {l: sum(p.get(l, 0) for p in probs[i]) / len(probs[i]) for l in LABELS} for i in ids}
    pred = {i: max(avg[i], key=avg[i].get) for i in ids}
    gold = [items[i]["polarity"] for i in ids]
    top = [avg[i][pred[i]] for i in ids]
    correct = [int(pred[i] == g) for i, g in zip(ids, gold)]
    order_stable = sum(1 for i in ids if len({max(p, key=p.get) for p in probs[i]}) == 1) / len(ids)
    report[f"jev_{lang}"] = {
        "n_items": len(ids),
        "accuracy": round(sum(correct) / len(ids), 3),
        "macro_f1": round(macro_f1(gold, [pred[i] for i in ids]), 3),
        "binary_pos_neg_accuracy": round(
            sum(c for c, g in zip(correct, gold) if g != "neutral") / sum(1 for g in gold if g != "neutral"), 3),
        "ece_top_prob": round(ece(top, correct), 3),
        "reliability_top_prob": reliability(top, correct),
        "top_choice_stable_across_orders": round(order_stable, 3),
    }

(HERE / "report.json").write_text(json.dumps(report, ensure_ascii=False, indent=1), encoding="utf-8")
print(json.dumps({k: {kk: vv for kk, vv in v.items() if kk != "errors_sample"} if isinstance(v, dict) and "n_items" in v else v
                  for k, v in report.items()}, ensure_ascii=False, indent=1))
