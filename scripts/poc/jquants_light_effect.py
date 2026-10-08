"""Estimate the effect of upgrading J-Quants Free -> Light, using Free-plan data only.

Why this works without a Light contract: the Free plan returns statements disclosed between
about 2 years 12 weeks ago and 12 weeks ago. For any past week t that is at least 12 weeks old,
we can reconstruct both views:
  - Light view at t: rows with DiscDate <= t
  - Free view at t:  rows with DiscDate <= t - 84 days
and compare them.

Outputs (stdout + JSON file):
  1. Staleness: how many days older the latest actual-results row is under Free than under Light.
  2. Judgment flips: a simplified version of the app's fundamental health check
     (ROE >= 10, equity ratio >= 40, operating margin >= 10; FundamentalHealthEvaluator) is
     evaluated on both views; weeks where the result differs are counted. This is an
     approximation: the app also has a growth OR-condition and a rescue branch.
  3. Forecast revisions: EarnForecastRevision / DividendForecastRevision rows per code, with the
     direction of the revised forecast vs the previous forecast, and the 84-day lag Free imposes.
  4. Suspected bug check (CHG-0049 proposal section 7.2): JQuantsClient::fetchStatements() keeps
     the newest 16 rows without filtering by document type. Count how many of those 16 are
     forecast-revision rows with empty actuals.

Usage (on the development PC; the Free API key the app already uses is enough):
  export JQUANTS_API_KEY=...            # never commit the key
  python3 scripts/poc/jquants_light_effect.py codes.txt --out light_effect.json
codes.txt: one 4- or 5-character JP securities code per line (e.g. 7203, 285A).
"""
import argparse
import datetime as dt
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = "https://api.jquants.com/v2"
LAG = dt.timedelta(days=84)
MIN_ROE, MIN_EQ, MIN_OPM = 10.0, 40.0, 10.0
REVISION_TYPES = ("EarnForecastRevision", "DividendForecastRevision")


def fetch_summary(code, key):
    url = f"{BASE}/fins/summary?" + urllib.parse.urlencode({"code": code})
    req = urllib.request.Request(url, headers={"x-api-key": key})
    for attempt in range(5):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return json.loads(r.read().decode("utf-8")).get("data") or []
        except urllib.error.HTTPError as e:
            if e.code in (429, 500, 502, 503, 504):
                time.sleep(10 * (attempt + 1))
                continue
            raise
    raise RuntimeError(f"giving up on {code}")


def fnum(v):
    try:
        return float(v) if v not in (None, "") else None
    except (TypeError, ValueError):
        return None


def doc_type(row):
    return row.get("DocType") or row.get("TypeOfDocument") or ""


def is_revision(row):
    return doc_type(row).startswith(REVISION_TYPES)


def is_actual(row):
    return not is_revision(row) and fnum(row.get("Sales")) is not None


def disc_date(row):
    return dt.date.fromisoformat(row["DiscDate"][:10])


def health(row):
    roe, eq = fnum(row.get("ROE")), fnum(row.get("EqAR"))
    sales, op = fnum(row.get("Sales")), fnum(row.get("OP"))
    if roe is None or eq is None or not sales or op is None:
        return None
    # J-Quants reports ROE/EqAR as ratios (e.g. 0.12); normalise to percent if needed.
    roe = roe * 100 if abs(roe) <= 1.5 else roe
    eq = eq * 100 if abs(eq) <= 1.5 else eq
    return roe >= MIN_ROE and eq >= MIN_EQ and (op / sales * 100) >= MIN_OPM


def latest(rows, cutoff):
    vis = [r for r in rows if disc_date(r) <= cutoff]
    return max(vis, key=lambda r: (r["DiscDate"], r.get("DiscTime") or "")) if vis else None


def analyse(code, rows):
    actual = [r for r in rows if is_actual(r)]
    revisions = [r for r in rows if is_revision(r)]
    res = {"code": code, "rows": len(rows), "actual_rows": len(actual), "revision_rows": len(revisions)}

    # 4. suspected bug: newest 16 rows as the app takes them
    newest16 = sorted(rows, key=lambda r: r["DiscDate"], reverse=True)[:16]
    res["newest16_revision_rows"] = sum(1 for r in newest16 if is_revision(r))
    res["newest16_empty_actual_rows"] = sum(1 for r in newest16 if fnum(r.get("Sales")) is None)

    if not actual:
        return res
    start = min(disc_date(r) for r in actual) + LAG
    end = max(disc_date(r) for r in rows)
    t = start
    weeks = stale_days = flips = comparable = 0
    while t <= end:
        light, free = latest(actual, t), latest(actual, t - LAG)
        if light and free:
            weeks += 1
            stale_days += (disc_date(light) - disc_date(free)).days
            hl, hf = health(light), health(free)
            if hl is not None and hf is not None:
                comparable += 1
                flips += hl != hf
        t += dt.timedelta(days=7)
    res.update(
        weeks=weeks,
        avg_staleness_days=round(stale_days / weeks, 1) if weeks else None,
        health_comparable_weeks=comparable,
        health_flip_weeks=flips,
    )

    # 3. forecast revisions: direction of revised full-year net-income forecast vs previous forecast
    revs = []
    with_fc = sorted([r for r in rows if fnum(r.get("FNP")) is not None], key=lambda r: (r["DiscDate"], r.get("DiscTime") or ""))
    for i, r in enumerate(with_fc):
        if not doc_type(r).startswith("EarnForecastRevision") or i == 0:
            continue
        prev, new = fnum(with_fc[i - 1].get("FNP")), fnum(r.get("FNP"))
        pct = round((new - prev) / abs(prev) * 100, 1) if prev else None
        revs.append({"disc_date": r["DiscDate"], "fnp_prev": prev, "fnp_new": new, "change_pct": pct})
    res["earn_forecast_revisions"] = revs
    return res


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("codes_file")
    ap.add_argument("--out", default="light_effect.json")
    ap.add_argument("--sleep", type=float, default=2.0)
    a = ap.parse_args()
    key = os.environ.get("JQUANTS_API_KEY")
    if not key:
        sys.exit("JQUANTS_API_KEY is not set")
    codes = [c.strip() for c in open(a.codes_file, encoding="utf-8") if c.strip() and not c.startswith("#")]
    results = []
    for c in codes:
        api_code = c + "0" if len(c) == 4 else c  # J-Quants uses 5-character codes
        try:
            results.append(analyse(c, fetch_summary(api_code, key)))
        except Exception as e:  # noqa: BLE001
            results.append({"code": c, "error": f"{type(e).__name__}: {e}"})
        time.sleep(a.sleep)
    ok = [r for r in results if "weeks" in r]
    summary = {
        "codes": len(codes),
        "analysed": len(ok),
        "avg_staleness_days": round(sum(r["avg_staleness_days"] for r in ok) / len(ok), 1) if ok else None,
        "health_flip_share": round(
            sum(r["health_flip_weeks"] for r in ok) / max(1, sum(r["health_comparable_weeks"] for r in ok)), 3
        ),
        "codes_with_any_flip": sum(1 for r in ok if r["health_flip_weeks"]),
        "earn_forecast_revisions": sum(len(r["earn_forecast_revisions"]) for r in ok),
        "codes_with_revision_rows_in_newest16": sum(1 for r in results if r.get("newest16_revision_rows")),
    }
    json.dump({"summary": summary, "per_code": results}, open(a.out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(json.dumps(summary, ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()
