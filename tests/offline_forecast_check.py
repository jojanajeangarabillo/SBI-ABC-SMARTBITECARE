#!/usr/bin/env python3
"""Offline check of the forecasting code - no database needed.

Loads the simulated training CSV with pandas, then for several historical
forecast dates (origins) trains the production forecaster on data up to that
date only and compares its 7/14/30-day totals with what actually happened.

Compared methods (same items, same origins, same actual totals):
    production   - forecasting.py as deployed: XGBoost or recent_mean per item,
                   chosen on an inner holdout, with the 50% sanity floor
    xgboost_only - the direct XGBoost models for every item (with the floor)
    recent_mean  - baseline: recent 28-day mean daily usage x H

MAE is in each item's base unit, pooled over items (large-volume items weigh
more). SMAPE is scale-free, so it is the fairer cross-item comparison.

Run from the project root:
    python tests/offline_forecast_check.py
    python tests/offline_forecast_check.py --csv path/to.csv --origins 10 --spacing 14
"""
import argparse
import sys
from pathlib import Path

import numpy as np
import pandas as pd

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import forecasting as fc  # noqa: E402

DEFAULT_CSV = ROOT / "database" / "data" / "smartbitecare_training_2025-09-30_to_2026-09-30.csv"


def load_csv(path):
    data = pd.read_csv(path)
    data["record_date"] = pd.to_datetime(data["record_date"], format="%d/%m/%Y")
    names = sorted(data["item_name"].str.strip().unique())
    ids = {name: index + 1 for index, name in enumerate(names)}
    return pd.DataFrame({
        "branch_id": "OFFLINE",
        "item_id": data["item_name"].str.strip().map(ids),
        "item_name": data["item_name"].str.strip(),
        "record_date": data["record_date"],
        "patient_count": data["total_patient_tally"],
        "quantity_used": data["quantity_used"],
        "animal_bite_cases": data["animal_bite_cases"],
    })


def score(actual, forecast):
    actual = np.asarray(actual, dtype=float)
    forecast = np.asarray(forecast, dtype=float)
    return float(np.mean(np.abs(actual - forecast))), fc.calculate_smape(actual, forecast)


def rolling_evaluation(history, origins):
    frame = fc.build_feature_frame(history)
    records = []
    for number, origin in enumerate(origins, 1):
        print(f"  origin {number}/{len(origins)}: training through {origin:%Y-%m-%d}", file=sys.stderr, flush=True)
        forecaster = fc.fit_forecaster(history[history["record_date"] <= origin])
        rows = frame[(frame["origin"] == origin) & frame["observed"]]
        for horizon in fc.HORIZONS:
            scored = rows[rows[f"target_{horizon}"].notna()]
            if scored.empty:
                continue
            production, methods = fc.predict_totals(forecaster, scored, horizon)
            xgb_raw, recent = fc.raw_predictions(forecaster["models"], scored, horizon)
            xgb_only = fc.apply_sanity_floor(xgb_raw, scored["recent_mean"].to_numpy(float), horizon)
            for i, (_, row) in enumerate(scored.iterrows()):
                records.append({
                    "origin": origin, "horizon": horizon, "item_id": int(row["item_id"]),
                    "item_name": row["item_name"], "actual": float(row[f"target_{horizon}"]),
                    "production": float(production[i]), "xgboost_only": float(xgb_only[i]),
                    "recent_mean": float(recent[i]), "method": methods[i],
                })
    return pd.DataFrame(records)


def accuracy_smoke_test(history):
    """Run the forecasting.py accuracy batch and check the rules PHP enforces."""
    catalog = [{"item_id": int(i), "item_name": n, "base_unit": "unit", "minimum_stock": 0}
               for i, n in history.groupby("item_id")["item_name"].first().items()]
    metrics, _, accuracy = fc.evaluate_forecaster(history, catalog)
    rows = accuracy["rows"]
    keys = {(r["item_id"], r["method"], r["horizon_days"]) for r in rows}
    assert len(keys) == len(rows) == 4 * len(catalog), "every item needs one_step:1 and recursive:7/14/30"
    one_step = [r for r in rows if r["method"] == "one_step"]
    assert sum(r["training_records"] for r in one_step) == accuracy["training_records"]
    assert sum(r["testing_records"] for r in one_step) == accuracy["testing_records"]
    assert accuracy["training_end"] < accuracy["testing_start"]
    for r in rows:
        if r["status"] == "Available":
            assert r["training_end"] < r["testing_start"]
            if r["method"] == "recursive":
                assert r["testing_records"] == r["horizon_days"] and r["testing_start"] == accuracy["testing_start"]
    assert "patient_count" not in fc.FEATURE_COLUMNS
    available = sum(r["status"] == "Available" for r in rows)
    print(f"Accuracy batch OK: {available}/{len(rows)} rows available, next-day SMAPE "
          f"{metrics['smape_percent']}% ({accuracy['testing_start']} to {accuracy['testing_end']}).")


def production_smoke_test(history, database_today):
    """Generate every item's forecast the way forecast_inventory does, minus the database."""
    fc.check_training_freshness(history["record_date"].max(), database_today)
    forecaster = fc.fit_forecaster(history)
    frame = fc.build_feature_frame(history)
    for horizon in fc.HORIZONS:
        methods = {}
        for item_id in sorted(history["item_id"].unique()):
            result, reason = fc.generate_item_forecast(forecaster, frame, history, int(item_id), 100.0, 10.0,
                                                       forecaster["errors"][horizon].get(int(item_id), 0.0),
                                                       horizon, database_today)
            assert result is not None, reason
            assert len(result["daily_forecasts"]) == horizon
            daily_sum = sum(d["forecasted_consumption"] for d in result["daily_forecasts"])
            assert abs(daily_sum - result["forecasted_consumption"]) <= (horizon + 1) * 0.0001
            floor = fc.SANITY_FLOOR_FRACTION * result["recent_daily_average"] * horizon
            assert result["forecasted_consumption"] >= floor - 0.0001
            methods[result["method"]] = methods.get(result["method"], 0) + 1
        print(f"{horizon}-day production forecasts OK for {sum(methods.values())} items: {methods}")

    stale_today = pd.Timestamp(history["record_date"].max()) + pd.Timedelta(days=fc.max_training_gap_days() + 1)
    try:
        fc.check_training_freshness(history["record_date"].max(), stale_today)
    except fc.TrainingDataOutdatedError as error:
        print(f"Outdated-data guard OK: {error}")
    else:
        raise AssertionError("Outdated training data was not refused.")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--csv", default=str(DEFAULT_CSV))
    parser.add_argument("--origins", type=int, default=8, help="number of historical forecast dates")
    parser.add_argument("--spacing", type=int, default=14, help="days between forecast dates")
    parser.add_argument("--today", default="2026-10-14", help="database date for the production smoke test")
    args = parser.parse_args()

    history = fc.prepare_training_data(load_csv(args.csv))
    end = history["record_date"].max()
    print(f"Loaded {len(history)} rows, {history['item_id'].nunique()} items, "
          f"{history['record_date'].min():%Y-%m-%d} to {end:%Y-%m-%d}.")

    last_origin = end - pd.Timedelta(days=max(fc.HORIZONS))
    origins = sorted(last_origin - pd.Timedelta(days=args.spacing * k) for k in range(args.origins))
    results = rolling_evaluation(history, origins)

    print()
    print(f"Rolling-origin evaluation: {len(origins)} forecast dates "
          f"({origins[0]:%Y-%m-%d} to {origins[-1]:%Y-%m-%d}, every {args.spacing} days), "
          f"{results['item_id'].nunique()} items. Each date trains only on earlier data.")
    print(f"{'Horizon':<8} {'Method':<13} {'MAE':>10} {'SMAPE %':>9} {'Item wins vs mean':>18}")
    for horizon in fc.HORIZONS:
        part = results[results["horizon"] == horizon]
        baseline_item = part.assign(err=(part["actual"] - part["recent_mean"]).abs()).groupby("item_id")["err"].mean()
        for method in ["production", "xgboost_only", "recent_mean"]:
            mae, smape = score(part["actual"], part[method])
            item_err = part.assign(err=(part["actual"] - part[method]).abs()).groupby("item_id")["err"].mean()
            wins = "" if method == "recent_mean" else f"{int((item_err < baseline_item - 1e-9).sum())}/{len(item_err)}"
            print(f"{horizon:<8} {method:<13} {mae:>10.3f} {smape:>9.2f} {wins:>18}")
        share = (part["method"] == fc.METHOD_XGBOOST).mean() * 100
        print(f"{'':<8} production used xgboost for {share:.0f}% of item forecasts")
    print()

    accuracy_smoke_test(history)
    production_smoke_test(history, pd.Timestamp(args.today))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
