#!/usr/bin/env python3
"""Remove the OLD historical CSV rows and old evaluation results for one branch,
so the new simulated dataset can be imported cleanly.

Keeps:   nurse daily-closing rows (source_type = 'daily_closing'), inventory stock,
         patients, users, and everything else.
Removes: training_dataset rows with source_type = 'historical_import' for the branch,
         saved single-holdout evaluations (forecast_evaluation_runs/items) and
         saved rolling evaluations (forecast_reliability_runs/windows) for the branch,
         and marks that branch's saved forecasts as stale.

Run from the project root (inside the Railway container):
    /opt/smartbitecare-venv/bin/python reset_training_data.py --branch SBI-002          # preview
    /opt/smartbitecare-venv/bin/python reset_training_data.py --branch SBI-002 --apply  # do it
"""
import argparse
import os

import mysql.connector


def env(name, default):
    return os.getenv(f"SMARTBITECARE_DB_{name}", default)


parser = argparse.ArgumentParser()
parser.add_argument("--branch", required=True)
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

conn = mysql.connector.connect(host=env("HOST", "localhost"), port=int(env("PORT", "3306")),
                               user=env("USER", "root"), password=env("PASSWORD", ""),
                               database=env("NAME", "smartbitecare"), autocommit=False)
cur = conn.cursor()
b = args.branch


def count(sql):
    cur.execute(sql, (b,))
    return cur.fetchone()[0]


plan = {
    "training rows (historical_import)":
        count("SELECT COUNT(*) FROM training_dataset WHERE branch_id=%s AND source_type='historical_import'"),
    "training rows kept (daily_closing)":
        count("SELECT COUNT(*) FROM training_dataset WHERE branch_id=%s AND source_type='daily_closing'"),
    "saved holdout evaluations":
        count("SELECT COUNT(*) FROM forecast_evaluation_runs WHERE branch_id=%s"),
    "saved rolling evaluations":
        count("SELECT COUNT(*) FROM forecast_reliability_runs WHERE branch_id=%s"),
}
print("APPLY" if args.apply else "PREVIEW (nothing changed)")
for k, v in plan.items():
    print(f"  {k}: {v}")

if args.apply:
    try:
        cur.execute("""DELETE i FROM forecast_evaluation_items i
                       JOIN forecast_evaluation_runs r ON r.evaluation_id = i.evaluation_id
                       WHERE r.branch_id=%s""", (b,))
        cur.execute("DELETE FROM forecast_evaluation_runs WHERE branch_id=%s", (b,))
        cur.execute("""DELETE w FROM forecast_reliability_windows w
                       JOIN forecast_reliability_runs r ON r.reliability_id = w.reliability_id
                       WHERE r.branch_id=%s""", (b,))
        cur.execute("DELETE FROM forecast_reliability_runs WHERE branch_id=%s", (b,))
        cur.execute("DELETE FROM training_dataset WHERE branch_id=%s AND source_type='historical_import'", (b,))
        cur.execute("""UPDATE forecast_results SET is_stale=1, stale_at=NOW(),
                       stale_reason='Training dataset replaced.' WHERE branch_id=%s""", (b,))
        cur.execute("INSERT IGNORE INTO forecast_training_branch_state (branch_id) VALUES (%s)", (b,))
        cur.execute("""UPDATE forecast_training_branch_state SET revision = revision + 1,
                       updated_at = NOW() WHERE branch_id=%s""", (b,))
        conn.commit()
        print("Done. Now run import_training_dataset.py with the new CSV.")
    except Exception:
        conn.rollback()
        raise
conn.close()