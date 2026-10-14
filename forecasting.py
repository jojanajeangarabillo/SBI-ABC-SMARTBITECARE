#!/usr/bin/env python3
"""
forecasting.py

SmartBiteCare Medical Supply Demand Forecasting

Algorithm:
    Direct multi-horizon XGBoost Regressors (one model per 7, 14 and 30 day
    horizon) with a per-item "recent 28-day mean" fallback.

Target:
    Total quantity consumed over the next H days, divided by H times the
    item's recent 28-day mean daily usage so one model serves every item.

Features (all known on the forecast date; same-day patient counts are
never used):
    rolling 7/28-day usage, previous-7-day average patient count,
    animal-bite cases (lags 3, 7, 28 and 7-day mean), day of week and month
    of the first forecast day, and supply type.

Evaluation:
    Chronological 80/20 holdout; MAE, RMSE and SMAPE.

Usage:
    py forecasting.py <branch_id> [forecast_days]

Environment:
    SMARTBITECARE_MAX_TRAINING_GAP_DAYS  (default 21) - refuse to forecast when
    the newest training record is older than this many days.

Example:
    py forecasting.py SBI-002 30
"""

import json
import hashlib
import xgboost
import math
import re
from decimal import Decimal, ROUND_HALF_UP
import os
import sys
import warnings
from datetime import timedelta
from pathlib import Path

import mysql.connector
import numpy as np
import pandas as pd
from sklearn.metrics import mean_absolute_error, mean_squared_error
from xgboost import XGBRegressor

warnings.filterwarnings("ignore")


# ============================================================
# DATABASE CONFIGURATION
# ============================================================

DB_CONFIG = {
    "host": os.getenv("SMARTBITECARE_DB_HOST", "localhost"),
    "user": os.getenv("SMARTBITECARE_DB_USER", "root"),
    "password": os.getenv("SMARTBITECARE_DB_PASSWORD", ""),
    "database": os.getenv(
        "SMARTBITECARE_DB_NAME",
        "smartbitecare"
    ),
    "port": int(
        os.getenv("SMARTBITECARE_DB_PORT", "3306")
    ),
}


# ============================================================
# FORECAST CONFIGURATION
# ============================================================

DEFAULT_FORECAST_DAYS = 30
ALLOWED_FORECAST_DAYS = {7, 14, 30}
MINIMUM_HISTORY_RECORDS = 15

def load_status_policy():
    path = Path(__file__).resolve().parent / "sources" / "forecast_status_rules.json"
    policy = json.loads(path.read_text(encoding="utf-8"))
    if policy.get("version") != "sbc-status-v2":
        raise ValueError("Install the matching Step 8 forecasting rule file.")
    for field, variable in [("restocking_lead_days", "SMARTBITECARE_RESTOCK_LEAD_DAYS"),
                            ("safety_buffer_days", "SMARTBITECARE_SAFETY_BUFFER_DAYS")]:
        value = os.getenv(variable) or policy[field]
        text = str(value).strip()
        if not re.fullmatch(r"0|[1-9][0-9]*", text) or int(text) > 3650:
            raise ValueError("Lead time and safety buffer must be whole days from 0 to 3650.")
        policy[field] = int(text)
    return policy


STATUS_POLICY = load_status_policy()
STATUS_RULE_VERSION = STATUS_POLICY["version"]
RESTOCKING_LEAD_DAYS = STATUS_POLICY["restocking_lead_days"]
SAFETY_BUFFER_DAYS = STATUS_POLICY["safety_buffer_days"]


# ============================================================
# MODEL FEATURES
# ============================================================

HORIZONS = (7, 14, 30)
RECENT_WINDOW_DAYS = 28
SANITY_FLOOR_FRACTION = 0.5
DEFAULT_MAX_TRAINING_GAP_DAYS = 21
HOLDOUT_FRACTION = 0.2
# Per-item method choice: XGBoost must beat the recent mean in at least
# SELECTION_MIN_WINS of the last SELECTION_ORIGINS rolling origins AND have an
# average MAE at least SELECTION_MIN_IMPROVEMENT lower. Each origin retrains on
# earlier targets and scores a block of SELECTION_BLOCK_DAYS forecast dates.
SELECTION_ORIGINS = 4
SELECTION_MIN_WINS = 3
SELECTION_MIN_IMPROVEMENT = 0.05
SELECTION_SPACING_DAYS = 14
SELECTION_BLOCK_DAYS = 7
MAXIMUM_TARGET_RATIO = 10.0
METHOD_XGBOOST = "xgboost"
METHOD_RECENT_MEAN = "recent_mean"

FEATURE_COLUMNS = [
    "usage_7_scaled",
    "usage_28_scaled",
    "usage_28_log",
    "patient_avg_7",
    "bite_lag_3",
    "bite_lag_7",
    "bite_lag_28",
    "bite_mean_7",
    "dow",
    "month",
    "supply_type",
]

TARGET_COLUMN = "quantity_used"


# ============================================================
# SUPPLY TYPE ENCODING
# ============================================================

SUPPLY_TYPE_CODES = {
    "vaccine": 0,
    "immunoglobulin": 1,
    "antiserum": 2,
    "medicine": 3,
    "diagnostic": 4,
    "syringe_needle": 5,
    "wound_care": 6,
    "ppe": 7,
    "forms": 8,
    "equipment": 9,
    "other_medical_supply": 10,
}


ITEM_SUPPLY_TYPE = {
    # Vaccines
    "SPEEDA": "vaccine",
    "ABHAYRAB": "vaccine",
    "VAXIRAB": "vaccine",
    "CHIRORAB": "vaccine",
    "TT (TETANUS TOXOID)": "vaccine",
    "TOXOID BETT": "vaccine",
    "TOXOID ABHAYTOX": "vaccine",
    "FLU": "vaccine",
    "HEPA B": "vaccine",

    # Immunoglobulins
    "ERIG": "immunoglobulin",
    "ERIG (EQUINE RABIES IMMUNOGLOBULIN)": "immunoglobulin",
    "HRIG": "immunoglobulin",
    "HTIG": "immunoglobulin",
    "HEPA BIG": "immunoglobulin",

    # Antiserum
    "ATS (ANTI-TETANUS SERUM)": "antiserum",
    'ATS 1,500 "IU"': "antiserum",
    'ATS 3000 "IU"': "antiserum",
    'ATS 5,000 "IU"': "antiserum",

    # Medicines
    "AMOXICILLIN 500MG": "medicine",
    "CEFALEXIN 500 MG": "medicine",
    "MEFENAMIC 500 MG": "medicine",
    "CETIRIZINE": "medicine",
    "INSULIN": "medicine",

    # Diagnostic
    "PPD": "diagnostic",

    # Syringes and needles
    "1CC/3CC": "syringe_needle",
    "5CC/10CC": "syringe_needle",
    "G23": "syringe_needle",
    "G27": "syringe_needle",

    # Wound-care consumables
    "ALCOHOL": "wound_care",
    "BETADINE": "wound_care",
    "COTTON BALLS": "wound_care",
    "GAUZE PAD": "wound_care",
    "STERILE WATER": "wound_care",
    "MICROPRE": "wound_care",

    # Personal protective equipment
    "FACEMASK": "ppe",
    "GLOVES": "ppe",

    # Forms
    "CSF FORMS": "forms",

    # Reusable equipment
    "WEIGHING SCALE": "equipment",
    "SCALE": "equipment",
    "COOLER": "equipment",
    "FORCEPS": "equipment",
    "KIDNEY BASIN": "equipment",
    "ACRYLIC CONTAINERS": "equipment",
    "ALCOHOL PUMP CONTAINER": "equipment",
    "BETADINE CONTAINER": "equipment",
    "REF THERMOMETER": "equipment",
}


# ============================================================
# GENERAL HELPERS
# ============================================================

def get_db_connection():
    """Create a MariaDB/MySQL connection."""
    connection = mysql.connector.connect(**DB_CONFIG)
    cursor = connection.cursor()
    try:
        cursor.execute("SET time_zone = '+08:00'")
    finally:
        cursor.close()
    return connection


def clean_float(value, default=0.0):
    """Convert database and NumPy values to safe floats."""
    try:
        result = float(value)

        if math.isnan(result) or math.isinf(result):
            return default

        return result

    except (TypeError, ValueError):
        return default


def print_json(payload):
    """Print JSON without NaN or Infinity."""
    print(
        json.dumps(
            payload,
            ensure_ascii=False,
            allow_nan=False
        )
    )


def normalize_item_name(item_name):
    """Normalize an item name for matching."""
    return " ".join(
        str(item_name).strip().upper().split()
    )


def get_supply_type(item_name):
    """Return the name and encoded value of the supply type."""
    normalized_name = normalize_item_name(item_name)

    supply_type_name = ITEM_SUPPLY_TYPE.get(
        normalized_name,
        "other_medical_supply"
    )

    return (
        supply_type_name,
        SUPPLY_TYPE_CODES[supply_type_name]
    )


def forecast_quantity_ticks(value):
    number = Decimal(str(value))
    if not number.is_finite() or number < 0 or number > Decimal("99999999.9999"):
        raise ValueError("Invalid nonnegative forecast quantity.")
    return int((number * 10000).quantize(Decimal("1"), rounding=ROUND_HALF_UP))


def classify_forecast_status(stock, minimum, demand, days, lead=None, buffer=None):
    lead = RESTOCKING_LEAD_DAYS if lead is None else lead
    buffer = SAFETY_BUFFER_DAYS if buffer is None else buffer
    if days not in ALLOWED_FORECAST_DAYS or not isinstance(lead, int) or not isinstance(buffer, int) or not 0 <= lead <= 3650 or not 0 <= buffer <= 3650:
        raise ValueError("Invalid forecast horizon or replenishment window.")
    s, m, d = map(forecast_quantity_ticks, (stock, minimum, demand))
    shortage = s == 0 or (d > 0 and (d >= s or s * days < d * (lead + buffer)))
    difference = max(0, d + m - s)
    return {
        "stock_status": "OUT OF STOCK" if s == 0 else ("LOW STOCK" if s <= m else "SUFFICIENT STOCK"),
        "forecast_status": "Shortage Risk" if shortage else ("Reorder Needed" if difference > 0 else "Sufficient"),
        "recommended_reorder": (difference + 9999) // 10000,
        "estimated_ending_stock": max(0, s - d) / 10000,
        "days_of_supply_remaining": s * days / d if d > 0 else None,
        "restocking_lead_days": lead,
        "safety_buffer_days": buffer,
        "status_rule_version": STATUS_RULE_VERSION,
    }


# ============================================================
# DATABASE QUERIES
# ============================================================

def load_database_today():
    """Return the database server's current date for consistent forecasting dates."""
    connection = get_db_connection()
    cursor = connection.cursor(dictionary=True)

    try:
        cursor.execute("SELECT CURDATE() AS database_today")
        row = cursor.fetchone()

        if not row or row.get("database_today") is None:
            raise ValueError("Could not determine the database current date.")

        return pd.Timestamp(row["database_today"]).normalize()

    finally:
        cursor.close()
        connection.close()


def load_forecastable_items():
    """Load all items enabled for forecasting."""
    connection = get_db_connection()
    cursor = connection.cursor(dictionary=True)

    try:
        query = """
            SELECT
                i.item_id,
                i.item_name,
                i.minimum_stock,
                COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name, 'unit') AS base_unit,
                c.category_name
            FROM inventory_items AS i
            LEFT JOIN units AS u ON u.unit_id = i.unit_id
            LEFT JOIN inventory_categories AS c
                ON c.category_id = i.category_id
            WHERE i.is_forecastable = 1
            ORDER BY i.item_name
        """

        cursor.execute(query)
        return cursor.fetchall()

    finally:
        cursor.close()
        connection.close()


def load_training_data(branch_id):
    """Load historical records for one branch."""
    connection = get_db_connection()
    cursor = connection.cursor(dictionary=True)

    try:
        query = """
            SELECT
                t.training_id,
                t.branch_id,
                t.item_id,
                t.record_date,
                t.patient_count,
                t.beginning_stock,
                t.quantity_used,
                t.stock_received,
                t.ending_stock,
                t.animal_bite_cases,
                t.vaccinations_administered,
                t.minimum_stock_level,
                t.low_stock_target,
                i.item_name,
                i.minimum_stock,
                c.category_name
            FROM training_dataset AS t
            INNER JOIN inventory_items AS i
                ON i.item_id = t.item_id
            LEFT JOIN inventory_categories AS c
                ON c.category_id = i.category_id
            WHERE t.branch_id = %s
              AND i.is_forecastable = 1
              AND t.record_date <= CURDATE()
            ORDER BY t.record_date, t.item_id
        """

        cursor.execute(query, (branch_id,))
        records = cursor.fetchall()

        return pd.DataFrame(records)

    finally:
        cursor.close()
        connection.close()


def load_current_stocks(branch_id):
    """
    Load current usable inventory stock.

    Expired batches are excluded. Records without expiration
    dates are counted because some supplies do not expire.
    """
    connection = get_db_connection()
    cursor = connection.cursor(dictionary=True)

    try:
        # PHP and Python read exactly the same aggregation definition.
        definition = json.loads(
            (Path(__file__).resolve().parent / "sources" / "inventory_stock_query.json")
            .read_text(encoding="utf-8")
        )
        if definition.get("version") != 1 or not isinstance(definition.get("query"), str):
            raise ValueError("Invalid shared inventory stock query definition.")
        cursor.execute("SET time_zone = '+08:00'")
        query = f"""
            SELECT item_id, usable_stock AS total_stock
            FROM ({definition['query']}) stock_totals
            WHERE branch_id = %s
        """

        cursor.execute(query, (branch_id,))
        records = cursor.fetchall()

        current_stocks = {}

        for record in records:
            current_stocks[int(record["item_id"])] = max(
                0.0,
                clean_float(record["total_stock"])
            )

        return current_stocks

    finally:
        cursor.close()
        connection.close()


# ============================================================
# TRAINING-DATA FRESHNESS
# ============================================================

class TrainingDataOutdatedError(ValueError):
    """The newest training record is too old to forecast from."""


def max_training_gap_days():
    """Allowed days between the newest training record and the database date."""
    text = str(os.getenv("SMARTBITECARE_MAX_TRAINING_GAP_DAYS") or DEFAULT_MAX_TRAINING_GAP_DAYS).strip()
    if not re.fullmatch(r"0|[1-9][0-9]*", text) or int(text) > 3650:
        raise ValueError("SMARTBITECARE_MAX_TRAINING_GAP_DAYS must be a whole number of days from 0 to 3650.")
    return int(text)


def check_training_freshness(newest_record_date, database_today, limit_days=None):
    """Refuse to forecast when the newest record is more than limit_days old.

    Older code recursively simulated the missing days, which made forecasts
    drift toward zero. A clear error is safer than an invented history.
    """
    limit_days = max_training_gap_days() if limit_days is None else limit_days
    newest = pd.Timestamp(newest_record_date).normalize()
    today = pd.Timestamp(database_today).normalize()
    gap = (today - newest).days
    if gap > limit_days:
        raise TrainingDataOutdatedError(
            "Training data outdated: the newest usage record is "
            f"{newest:%Y-%m-%d}, {gap} days before the database date {today:%Y-%m-%d} "
            f"(limit {limit_days} days). Record or import recent daily closings, "
            "then generate the forecast again."
        )
    return gap


# ============================================================
# DATA PREPROCESSING AND FEATURE ENGINEERING
# ============================================================

def prepare_training_data(raw_data):
    """Clean records: typed, nonnegative, one row per item and date."""
    data = raw_data.copy()
    data["record_date"] = pd.to_datetime(data["record_date"], errors="coerce").dt.normalize()
    for column in ["patient_count", "quantity_used", "animal_bite_cases"]:
        if column not in data.columns:
            data[column] = np.nan
        data[column] = pd.to_numeric(data[column], errors="coerce")
    if "branch_id" not in data.columns:
        data["branch_id"] = ""

    # Missing usage stays missing and is removed, never converted to zero.
    data = data.dropna(subset=["record_date", "item_id", "item_name", "quantity_used"])
    data = data[data["quantity_used"] >= 0].copy()
    for column in ["patient_count", "animal_bite_cases"]:
        data.loc[data[column] < 0, column] = np.nan
    data["item_id"] = data["item_id"].astype(int)
    data = data.sort_values(["item_id", "record_date"]).drop_duplicates(
        subset=["branch_id", "item_id", "record_date"], keep="last")
    supply = data["item_name"].apply(get_supply_type)
    data["supply_type_name"] = supply.apply(lambda value: value[0])
    data["supply_type"] = supply.apply(lambda value: value[1])
    return data.reset_index(drop=True)


def build_feature_frame(clean_history):
    """One row per item and calendar origin date (the last day of known data).

    Every feature uses data up to and including the origin date only. Targets
    target_<H> hold the observed total over origin+1 .. origin+H and are NaN
    unless all H days were recorded (missing days are never treated as zero).
    dow/month describe the first forecast day (origin + 1).
    """
    if clean_history.empty:
        return pd.DataFrame()

    # Branch-level daily context (identical on every item's row for a date).
    daily = clean_history.groupby("record_date")[["patient_count", "animal_bite_cases"]].median()
    calendar = pd.date_range(daily.index.min(), daily.index.max(), freq="D")
    daily = daily.reindex(calendar)
    bites = daily["animal_bite_cases"]
    context = pd.DataFrame({
        "patient_avg_7": daily["patient_count"].rolling(7, min_periods=1).mean(),
        "bite_lag_3": bites.shift(3),
        "bite_lag_7": bites.shift(7),
        "bite_lag_28": bites.shift(28),
        "bite_mean_7": bites.rolling(7, min_periods=1).mean(),
    }, index=calendar)

    frames = []
    for item_id, item in clean_history.groupby("item_id"):
        usage = item.set_index("record_date")["quantity_used"].astype(float)
        dates = pd.date_range(usage.index.min(), usage.index.max(), freq="D")
        usage = usage.reindex(dates)
        rolling_7 = usage.rolling(7, min_periods=4).mean()
        rolling_28 = usage.rolling(RECENT_WINDOW_DAYS, min_periods=7).mean()
        long_run = usage.expanding(min_periods=1).mean()
        # Scale = recent 28-day mean, floored so near-zero items stay finite.
        scale = np.maximum(rolling_28.fillna(0.0), np.maximum(0.25 * long_run.fillna(0.0), 1e-4))
        frame = pd.DataFrame({
            "item_id": int(item_id),
            "item_name": str(item["item_name"].iloc[-1]).strip(),
            "origin": dates,
            "observed": usage.notna().to_numpy(),
            "recent_mean": rolling_28.to_numpy(),
            "scale": scale.to_numpy(),
            "usage_7_scaled": (rolling_7 / scale).to_numpy(),
            "usage_28_scaled": (rolling_28 / scale).to_numpy(),
            "usage_28_log": np.log1p(rolling_28).to_numpy(),
            "supply_type": int(item["supply_type"].iloc[-1]),
        })
        first_day = dates + pd.Timedelta(days=1)
        frame["dow"] = first_day.dayofweek
        frame["month"] = first_day.month
        for horizon in HORIZONS:
            total = usage.rolling(horizon, min_periods=horizon).sum().shift(-horizon)
            frame[f"target_{horizon}"] = total.to_numpy()
        frames.append(frame)

    frame = pd.concat(frames, ignore_index=True)
    frame = frame.join(context, on="origin")
    # Origins need a recent mean; earlier rows have no usable scale.
    return frame[frame["recent_mean"].notna()].reset_index(drop=True)


def dow_weights(clean_history, item_id, origin, weeks=8):
    """Day-of-week usage profile (mean 1.0) used to spread a horizon total."""
    item = clean_history[(clean_history["item_id"] == item_id)
                         & (clean_history["record_date"] <= origin)
                         & (clean_history["record_date"] > origin - pd.Timedelta(days=7 * weeks))]
    weights = np.ones(7)
    if not item.empty:
        means = item.groupby(item["record_date"].dt.dayofweek)["quantity_used"].mean()
        overall = float(item["quantity_used"].mean())
        if overall > 0:
            for dow, value in means.items():
                weights[int(dow)] = max(0.0, float(value) / overall)
    if weights.sum() <= 0:
        weights = np.ones(7)
    return weights


def spread_total(total, start, days, weights):
    """Split a horizon total into daily quantities that add back to it."""
    dates = pd.date_range(start, periods=days, freq="D")
    shares = np.array([weights[d.dayofweek] for d in dates], dtype=float)
    shares = shares / shares.sum() if shares.sum() > 0 else np.full(days, 1.0 / days)
    return dates, total * shares


# ============================================================
# XGBOOST MODEL
# ============================================================

def create_xgboost_model():
    """Create one direct-horizon XGBoost Regressor."""
    return XGBRegressor(
        objective="reg:squarederror",
        n_estimators=300,
        learning_rate=0.05,
        max_depth=4,
        min_child_weight=5,
        subsample=0.80,
        colsample_bytree=0.80,
        reg_alpha=0.10,
        reg_lambda=1.00,
        gamma=0.00,
        random_state=42,
        n_jobs=-1,
        tree_method="hist",
        eval_metric="mae",
    )


def training_rows(frame, horizon, before=None):
    """Rows whose whole target window is observed (and ends before `before`)."""
    rows = frame[frame[f"target_{horizon}"].notna()]
    if before is not None:
        rows = rows[rows["origin"] + pd.Timedelta(days=horizon) < pd.Timestamp(before)]
    return rows


def fit_horizon_model(rows, horizon, model_factory=None):
    if rows.empty:
        return None
    ratio = (rows[f"target_{horizon}"] / (rows["scale"] * horizon)).clip(0, MAXIMUM_TARGET_RATIO)
    model = (model_factory or create_xgboost_model)()
    model.fit(rows[FEATURE_COLUMNS].astype(float), ratio.astype(float))
    return model


def raw_predictions(models, rows, horizon):
    """XGBoost and recent-mean totals before method choice and sanity floor."""
    recent_total = rows["recent_mean"].to_numpy(dtype=float) * horizon
    model = models.get(horizon)
    if model is None or rows.empty:
        return np.full(len(rows), np.nan), recent_total
    ratio = np.maximum(model.predict(rows[FEATURE_COLUMNS].astype(float)), 0.0)
    return ratio * rows["scale"].to_numpy(dtype=float) * horizon, recent_total


def apply_sanity_floor(total, recent_mean, horizon):
    """Never forecast less than half of the recent 28-day average usage."""
    return np.maximum(np.nan_to_num(total, nan=0.0), SANITY_FLOOR_FRACTION * recent_mean * horizon)


def selection_origins(frame, horizon):
    """The last SELECTION_ORIGINS rolling origins whose scored blocks are fully observed."""
    if frame.empty:
        return []
    last_origin = frame.loc[frame[f"target_{horizon}"].notna(), "origin"].max()
    if pd.isna(last_origin):
        return []
    latest = pd.Timestamp(last_origin) - pd.Timedelta(days=SELECTION_BLOCK_DAYS - 1)
    # Space origins at least H days apart so their target windows do not overlap.
    spacing = max(SELECTION_SPACING_DAYS, horizon)
    return sorted(latest - pd.Timedelta(days=spacing * k) for k in range(SELECTION_ORIGINS))


def fit_forecaster(clean_history, model_factory=None):
    """Train direct 7/14/30-day models and pick xgboost or recent_mean per item.

    For each horizon, the last 4 rolling origins before the forecast date each
    retrain the model on target windows that end by that origin, then score the
    H-day totals forecast on that origin and the next 6 days. An item keeps
    XGBoost only if it beats recent 28-day mean x H on MAE in at least 3 of the
    4 origins AND its average MAE across them is at least 5% lower; otherwise
    the recent mean is used. Final models are refitted on all rows.
    """
    frame = build_feature_frame(clean_history)
    forecaster = {"models": {}, "choices": {}, "errors": {}, "selection_origins": {}}
    if frame.empty:
        return forecaster
    for horizon in HORIZONS:
        target = f"target_{horizon}"
        origins = selection_origins(frame, horizon)
        per_origin = []
        for origin in origins:
            # Targets ending on or before the origin date were known on it.
            model = fit_horizon_model(training_rows(frame, horizon, origin + pd.Timedelta(days=1)),
                                      horizon, model_factory)
            block = frame[(frame["origin"] >= origin)
                          & (frame["origin"] < origin + pd.Timedelta(days=SELECTION_BLOCK_DAYS))
                          & frame[target].notna()]
            if model is None or block.empty:
                continue
            xgb_total, recent_total = raw_predictions({horizon: model}, block, horizon)
            xgb_total = apply_sanity_floor(xgb_total, block["recent_mean"].to_numpy(float), horizon)
            per_origin.append(block[["item_id"]].assign(
                origin=origin,
                xgb_error=np.abs(xgb_total - block[target].to_numpy(float)),
                mean_error=np.abs(recent_total - block[target].to_numpy(float))))
        choice, errors = {}, {}
        if per_origin:
            scores = pd.concat(per_origin).groupby(["item_id", "origin"])[["xgb_error", "mean_error"]].mean()
            for item_id, item in scores.groupby(level="item_id"):
                xgb_mae = float(item["xgb_error"].mean())
                mean_mae = float(item["mean_error"].mean())
                wins = int((item["xgb_error"] < item["mean_error"]).sum())
                use_xgb = (len(item) >= SELECTION_ORIGINS and wins >= SELECTION_MIN_WINS
                           and xgb_mae < mean_mae and xgb_mae <= (1 - SELECTION_MIN_IMPROVEMENT) * mean_mae)
                choice[int(item_id)] = METHOD_XGBOOST if use_xgb else METHOD_RECENT_MEAN
                errors[int(item_id)] = xgb_mae if use_xgb else mean_mae
        forecaster["choices"][horizon] = choice
        forecaster["errors"][horizon] = errors
        forecaster["selection_origins"][horizon] = [o.strftime("%Y-%m-%d") for o in origins]
        forecaster["models"][horizon] = fit_horizon_model(training_rows(frame, horizon), horizon, model_factory)
    return forecaster


def predict_totals(forecaster, rows, horizon):
    """Production horizon totals for feature rows: (totals, methods)."""
    xgb_total, recent_total = raw_predictions(forecaster["models"], rows, horizon)
    choices = forecaster["choices"].get(horizon, {})
    methods = np.array([choices.get(int(i), METHOD_RECENT_MEAN) for i in rows["item_id"]], dtype=object)
    use_xgb = (methods == METHOD_XGBOOST) & np.isfinite(xgb_total)
    methods[~use_xgb] = METHOD_RECENT_MEAN
    chosen = np.where(use_xgb, xgb_total, recent_total)
    return apply_sanity_floor(chosen, rows["recent_mean"].to_numpy(float), horizon), methods


def latest_feature_row(frame, item_id, origin, first_forecast_day):
    """Features on the item's newest observed date <= origin, for a window starting first_forecast_day.

    `frame` comes from build_feature_frame; a row's features only use data up to
    its own origin date, so one frame serves every origin.
    """
    if frame.empty:
        return None
    rows = frame[(frame["item_id"] == item_id) & frame["observed"]
                 & (frame["origin"] <= pd.Timestamp(origin))]
    if rows.empty:
        return None
    row = rows.iloc[[-1]].copy()
    first_forecast_day = pd.Timestamp(first_forecast_day)
    row["dow"] = first_forecast_day.dayofweek
    row["month"] = first_forecast_day.month
    return row


def forecast_item_path(forecaster, frame, clean_history, item_id, origin, horizon):
    """Daily path (dates, quantities), total, method and recent mean for one item.

    The forecast window always starts the day after `origin`; features come from
    the newest record on or before it.
    """
    origin = pd.Timestamp(origin).normalize()
    start = origin + pd.Timedelta(days=1)
    row = latest_feature_row(frame, item_id, origin, start)
    if row is None:
        return None
    totals, methods = predict_totals(forecaster, row, horizon)
    total = float(totals[0])
    weights = dow_weights(clean_history, item_id, origin)
    dates, daily = spread_total(total, start, horizon, weights)
    return {"dates": dates, "daily": daily, "total": total, "method": str(methods[0]),
            "recent_mean": float(row["recent_mean"].iloc[0]),
            "last_record_date": pd.Timestamp(row["origin"].iloc[0])}


# ============================================================
# EVALUATION METRICS
# ============================================================

def calculate_smape(actual_values, forecasted_values):
    """
    Calculate Symmetric Mean Absolute Percentage Error.

    Formula:
        SMAPE = mean(
            2 * |actual - forecast|
            / (|actual| + |forecast|)
        ) * 100

    When both actual and forecast are zero, the error
    contribution is defined as zero.

    Result range:
        0% to 200%
    """
    actual = np.asarray(actual_values, dtype=float)
    forecasted = np.asarray(forecasted_values, dtype=float)
    denominator = np.abs(actual) + np.abs(forecasted)
    absolute_difference = np.abs(actual - forecasted)
    individual_errors = np.zeros_like(denominator, dtype=float)
    valid_mask = denominator > 0
    individual_errors[valid_mask] = 2.0 * absolute_difference[valid_mask] / denominator[valid_mask]
    return float(np.mean(individual_errors) * 100)


EVALUATION_VERSION = 'sbc-evaluation-v2'


def forecast_error_scores(actual, predicted):
    actual = np.asarray(actual, dtype=float)
    predicted = np.asarray(predicted, dtype=float)
    if not len(actual) or actual.shape != predicted.shape:
        raise ValueError('Evaluation needs matching, nonempty actual and forecast values.')
    if not np.isfinite(actual).all() or not np.isfinite(predicted).all():
        raise ValueError('Evaluation values must be finite.')
    return {
        'mae': round(float(mean_absolute_error(actual, predicted)), 8),
        'rmse': round(math.sqrt(float(mean_squared_error(actual, predicted))), 8),
        'smape_percent': round(calculate_smape(actual, predicted), 4),
        'actual_total': round(float(actual.sum()), 4),
        'forecast_total': round(float(predicted.sum()), 4),
        'total_absolute_error': round(abs(float(actual.sum() - predicted.sum())), 4),
    }


def evaluate_forecaster(clean_history, item_catalog):
    """One chronological 80/20 holdout of the production forecaster.

    The forecaster (models and per-item method choice) sees only pre-holdout
    history. "one_step" rows score next-day forecasts made each holdout day
    from information known the evening before (no same-day patient counts).
    "recursive" rows keep their stored name for compatibility but now score the
    direct 7/14/30-day forecast made on the day before the holdout starts.
    Missing holdout dates never become zero actual consumption.
    """
    dates = sorted(clean_history['record_date'].dt.normalize().unique())
    split = int(len(dates) * (1 - HOLDOUT_FRACTION)) if len(dates) >= 60 else None
    test_start = pd.Timestamp(dates[split]) if split is not None else None
    training = clean_history[clean_history['record_date'] < test_start] if test_start is not None else clean_history.iloc[:0]
    testing = clean_history[clean_history['record_date'] >= test_start] if test_start is not None else clean_history.iloc[:0]

    def date_text(value):
        return pd.Timestamp(value).strftime('%Y-%m-%d') if pd.notna(value) else None

    forecaster = fit_forecaster(training) if test_start is not None and not training.empty else None
    one_step = pd.DataFrame()
    frame = build_feature_frame(clean_history)
    if forecaster is not None and forecaster['models'].get(7) is not None:
        # Next-day forecast for day d = 7-day total at origin d-1 x that weekday's share.
        origins = frame[(frame['origin'] >= test_start - pd.Timedelta(days=1))
                        & (frame['origin'] < testing['record_date'].max())].copy()
        if not origins.empty:
            totals, _ = predict_totals(forecaster, origins, 7)
            origins['total_7'] = totals
            origins['record_date'] = origins['origin'] + pd.Timedelta(days=1)
            profiles = {int(i): dow_weights(training, int(i), test_start - pd.Timedelta(days=1))
                        for i in origins['item_id'].unique()}
            origins['evaluation_forecast'] = [
                total * profiles[int(i)][d.dayofweek] / profiles[int(i)].sum()
                for total, i, d in zip(origins['total_7'], origins['item_id'], origins['record_date'])]
            one_step = testing.merge(origins[['item_id', 'record_date', 'evaluation_forecast']],
                                     on=['item_id', 'record_date'], how='inner')
            one_step['evaluation_forecast'] = one_step['evaluation_forecast'].round(4)
    ready = not one_step.empty

    source_columns = ['branch_id', 'item_id', 'record_date', 'patient_count', 'animal_bite_cases',
                      'quantity_used', 'item_name']
    source_json = clean_history.sort_values(['item_id', 'record_date'])[source_columns].to_json(
        orient='records', date_format='iso', double_precision=15)
    params = create_xgboost_model().get_params()
    # Store only JSON-safe model parameters; None records explicit defaults.
    params = {k: v for k, v in params.items() if v is None or isinstance(v, (str, int, float, bool))}
    params = {k: str(v) if isinstance(v, float) and not math.isfinite(v) else v for k, v in params.items()}
    scored_training = training[training['item_id'].isin(one_step['item_id'].unique())] if ready else training.iloc[:0]
    accuracy = {
        'version': EVALUATION_VERSION,
        'source_hash': hashlib.sha256(source_json.encode('utf-8')).hexdigest(),
        'model_version': str(xgboost.__version__),
        'model_parameters': params,
        'feature_columns': FEATURE_COLUMNS,
        'training_records': int(len(scored_training)),
        'testing_records': int(len(one_step)),
        'training_start': date_text(scored_training['record_date'].min()) if ready else None,
        'training_end': date_text(scored_training['record_date'].max()) if ready else None,
        'testing_start': date_text(one_step['record_date'].min()) if ready else None,
        'testing_end': date_text(one_step['record_date'].max()) if ready else None,
        'overall_smape_percent': None,
        'rows': [],
    }
    model_metrics = {'training_records': accuracy['training_records'], 'testing_records': accuracy['testing_records'],
        'training_percentage': 80, 'testing_percentage': 20,
        'testing_start_date': accuracy['testing_start'], 'mae': 0.0, 'rmse': 0.0,
        'smape_percent': None, 'evaluation_status': 'Insufficient History'}
    if ready:
        scores = forecast_error_scores(one_step[TARGET_COLUMN], one_step['evaluation_forecast'])
        model_metrics.update({k: scores[k] for k in ['mae', 'rmse', 'smape_percent']})
        model_metrics['evaluation_status'] = 'Available'
        accuracy['overall_smape_percent'] = scores['smape_percent']

    catalog = {int(row['item_id']): row for row in item_catalog}
    origin = test_start - timedelta(days=1) if ready else None
    for item_id in sorted(clean_history['item_id'].astype(int).unique()):
        item = catalog.get(int(item_id))
        if not item:
            continue
        item_scored = one_step[one_step['item_id'] == item_id] if ready else one_step
        item_training = training[training['item_id'] == item_id] if not item_scored.empty else training.iloc[:0]
        history = clean_history[clean_history['item_id'] == item_id]
        common = {
            'item_id': int(item_id), 'item_name': str(item['item_name']).strip(),
            'base_unit': str(item.get('base_unit') or 'unit').strip(),
            'training_records': int(len(item_training)),
            'training_start': date_text(item_training['record_date'].min()),
            'training_end': date_text(item_training['record_date'].max()),
        }
        one = dict(common, method='one_step', horizon_days=1,
            testing_records=int(len(item_scored)),
            testing_start=date_text(item_scored['record_date'].min()) if len(item_scored) else None,
            testing_end=date_text(item_scored['record_date'].max()) if len(item_scored) else None,
            status='Insufficient History', reason='Not enough dates for a chronological 80/20 evaluation.')
        if not item_scored.empty:
            one.update(forecast_error_scores(item_scored[TARGET_COLUMN], item_scored['evaluation_forecast']),
                       status='Available', reason=None)
        elif ready:
            one['reason'] = 'This item has no scored next-day forecasts after the shared chronological cutoff.'
        accuracy['rows'].append(one)

        for horizon in HORIZONS:
            start = origin + timedelta(days=1) if ready else None
            end = origin + timedelta(days=horizon) if ready else None
            row = dict(common, method='recursive', horizon_days=int(horizon),
                testing_records=0, testing_start=date_text(start), testing_end=date_text(end),
                status='Insufficient History', reason='Not enough dates for a chronological 80/20 evaluation.')
            if ready:
                actual = history[(history['record_date'] >= start) & (history['record_date'] <= end)]
                row['testing_records'] = int(len(actual))
                prefix = history[history['record_date'] <= origin]
                expected_dates = pd.date_range(start, end, freq='D')
                if item_training.empty:
                    row['reason'] = 'This item has no scored training history before the cutoff.'
                elif len(prefix) < MINIMUM_HISTORY_RECORDS:
                    row['reason'] = f'Only {len(prefix)} pre-cutoff observations; {MINIMUM_HISTORY_RECORDS} are required.'
                elif len(actual) != horizon or set(actual['record_date'].dt.normalize()) != set(expected_dates):
                    row['reason'] = f'Holdout contains {len(actual)} of {horizon} required daily observations. Missing dates are not treated as zero.'
                else:
                    path = forecast_item_path(forecaster, frame, training, int(item_id), origin, int(horizon))
                    if path is None:
                        row['reason'] = 'No recent usage was available on the cutoff date.'
                    else:
                        actual_by_date = actual.set_index('record_date')[TARGET_COLUMN]
                        predicted = np.round(path['daily'], 4)
                        scores = forecast_error_scores([actual_by_date.loc[d] for d in expected_dates], predicted)
                        # Score the published horizon total, not the sum of rounded daily rows.
                        scores['forecast_total'] = round(path['total'], 4)
                        scores['total_absolute_error'] = round(abs(scores['actual_total'] - scores['forecast_total']), 4)
                        row.update(scores, status='Available', reason=None)
            accuracy['rows'].append(row)

    item_mae = {}
    if forecaster is not None:
        for horizon, errors in forecaster['errors'].items():
            item_mae[horizon] = dict(errors)
    return model_metrics, item_mae, accuracy


# ============================================================
# ITEM FORECASTING
# ============================================================

def generate_item_forecast(
    forecaster,
    frame,
    clean_history,
    item_id,
    current_stock,
    minimum_stock,
    total_validation_mae,
    forecast_days,
    database_today
):
    """Generate a direct horizon forecast for one item, starting tomorrow."""
    database_today = pd.Timestamp(database_today).normalize()
    history = clean_history[(clean_history["item_id"] == item_id)
                            & (clean_history["record_date"] <= database_today)]
    if len(history) < MINIMUM_HISTORY_RECORDS:
        return None, (
            f"Only {len(history)} valid historical records were found. "
            f"At least {MINIMUM_HISTORY_RECORDS} are required."
        )
    newest = pd.Timestamp(history["record_date"].max()).normalize()
    limit = max_training_gap_days()
    if (database_today - newest).days > limit:
        return None, (
            f"Training data outdated for this item: newest record is {newest:%Y-%m-%d}, "
            f"more than {limit} days before {database_today:%Y-%m-%d}."
        )

    # Features come from the newest record; the window starts tomorrow.
    start = database_today + timedelta(days=1)
    row = latest_feature_row(frame, item_id, database_today, start)
    if row is None or not np.isfinite(float(row["recent_mean"].iloc[0])):
        return None, "No usage was recorded in the last 28 days, so there is no recent average to forecast from."
    totals, methods = predict_totals(forecaster, row, forecast_days)
    weights = dow_weights(clean_history, item_id, database_today)
    dates, daily_values = spread_total(float(totals[0]), start, forecast_days, weights)
    recent_mean = float(row["recent_mean"].iloc[0])

    item_name = str(history.iloc[-1]["item_name"]).strip()
    supply_type_name, _ = get_supply_type(item_name)
    forecast_end = start + timedelta(days=forecast_days - 1)

    daily_forecasts = []
    running_total = 0.0
    expected_reorder_date = None
    expected_stockout_date = None
    for forecast_date, daily_consumption in zip(dates, daily_values):
        daily_consumption = max(0.0, clean_float(daily_consumption))
        running_total += daily_consumption
        remaining = current_stock - running_total
        date_text = forecast_date.strftime("%Y-%m-%d")
        if expected_reorder_date is None and remaining <= minimum_stock:
            expected_reorder_date = date_text
        if expected_stockout_date is None and remaining <= 0:
            expected_stockout_date = date_text
        daily_forecasts.append({
            "date": date_text,
            "forecasted_consumption": round(daily_consumption, 4),
            "estimated_remaining_stock": round(max(0.0, remaining), 2),
            "is_weekend": int(forecast_date.dayofweek >= 5),
        })

    # Classify and publish the same four-decimal demand total.
    total_forecasted_consumption = forecast_quantity_ticks(running_total) / 10000
    average_daily_consumption = total_forecasted_consumption / forecast_days
    days_of_supply = current_stock / average_daily_consumption if average_daily_consumption > 0 else None
    estimated_ending_stock = max(0.0, current_stock - total_forecasted_consumption)

    # Holdout MAE of the chosen method is on H-day totals; MAE ~ 0.8 sigma.
    total_mae = clean_float(total_validation_mae, default=0.0)
    total_uncertainty = max(1.25 * total_mae, total_forecasted_consumption * 0.05, 0.000001)
    conservative_consumption = total_forecasted_consumption + 1.645 * total_uncertainty

    # Status rules come from sources/forecast_status_rules.json (lead + buffer).
    decision = classify_forecast_status(
        current_stock, minimum_stock, total_forecasted_consumption, forecast_days
    )

    # Approximate probability that horizon demand exceeds usable stock.
    if current_stock <= 0:
        shortage_probability = 1.0 if total_forecasted_consumption > 0 else 0.0
    else:
        z_score = (total_forecasted_consumption - current_stock) / total_uncertainty
        shortage_probability = 0.5 * (1.0 + math.erf(z_score / math.sqrt(2.0)))
    shortage_probability = min(1.0, max(0.0, shortage_probability))

    return {
        "item_id": int(item_id),
        "item_name": item_name,
        "supply_type": supply_type_name,
        "method": str(methods[0]),
        "recent_daily_average": round(recent_mean, 4),
        "training_data_end": newest.strftime("%Y-%m-%d"),
        "current_stock": round(current_stock, 4),
        "minimum_stock": round(minimum_stock, 4),
        "stock_status": decision["stock_status"],
        "forecasted_daily_consumption": round(average_daily_consumption, 4),
        "forecasted_consumption": round(total_forecasted_consumption, 4),
        "conservative_consumption": round(conservative_consumption, 4),
        "estimated_ending_stock": round(estimated_ending_stock, 4),
        "days_of_supply_remaining": round(days_of_supply, 2) if days_of_supply is not None else None,
        "restocking_lead_days": RESTOCKING_LEAD_DAYS,
        "safety_buffer_days": SAFETY_BUFFER_DAYS,
        "status_rule_version": STATUS_RULE_VERSION,
        "forecast_status": decision["forecast_status"],
        "shortage_probability": round(shortage_probability, 4),
        "recommended_reorder": decision["recommended_reorder"],
        "expected_reorder_date": expected_reorder_date,
        "expected_stockout_date": expected_stockout_date,
        "validation_mae": round(total_mae / forecast_days, 4),
        "forecast_start": start.strftime("%Y-%m-%d"),
        "forecast_end": forecast_end.strftime("%Y-%m-%d"),
        "daily_forecasts": daily_forecasts,
    }, None


# ============================================================
# COMPLETE FORECASTING PROCESS
# ============================================================

def forecast_inventory(branch_id, forecast_days):
    """Evaluate, train, and run the forecasting models."""
    forecastable_items = load_forecastable_items()
    raw_training_data = load_training_data(branch_id)
    current_stocks = load_current_stocks(branch_id)
    database_today = load_database_today()

    if not forecastable_items:
        raise ValueError("No inventory items are enabled for forecasting.")
    if raw_training_data.empty:
        raise ValueError(f"No historical training records were found for branch {branch_id}.")

    # Exclude future-dated data before evaluation and final model training.
    raw_dates = pd.to_datetime(raw_training_data['record_date'], errors='coerce')
    raw_training_data = raw_training_data.loc[
        raw_dates.dt.normalize() <= pd.Timestamp(database_today).normalize()
    ].copy()

    clean_history = prepare_training_data(raw_training_data)
    if clean_history.empty:
        raise ValueError("No usable usage records remained after cleaning.")

    # Stale history is refused instead of simulated (see check_training_freshness).
    check_training_freshness(clean_history["record_date"].max(), database_today)

    model_metrics, holdout_errors, accuracy = evaluate_forecaster(clean_history, forecastable_items)

    forecaster = fit_forecaster(clean_history)
    frame = build_feature_frame(clean_history)
    if forecaster["models"].get(forecast_days) is None:
        raise ValueError("Not enough complete history to train the forecasting model.")

    history_item_ids = set(clean_history["item_id"].astype(int).tolist())
    forecasts = []
    skipped_items = []

    for item in forecastable_items:
        item_id = int(item["item_id"])
        item_name = str(item["item_name"]).strip()

        if item_id not in history_item_ids:
            skipped_items.append({"item_id": item_id, "item_name": item_name,
                                  "reason": "No historical training records were found for this branch."})
            continue

        # A missing inventory_stocks row is not the same thing as confirmed
        # zero stock. Skip it instead of manufacturing a false 100% risk.
        if item_id not in current_stocks:
            skipped_items.append({"item_id": item_id, "item_name": item_name, "reason": (
                "No inventory stock record exists for this branch. "
                "Initialize the item in Inventory Stock Management first.")})
            continue

        # Uncertainty uses the final forecaster's holdout error for this horizon.
        total_mae = forecaster["errors"].get(forecast_days, {}).get(item_id)
        if total_mae is None:
            total_mae = holdout_errors.get(forecast_days, {}).get(item_id, 0.0)

        forecast_result, skip_reason = generate_item_forecast(
            forecaster=forecaster,
            frame=frame,
            clean_history=clean_history,
            item_id=item_id,
            current_stock=current_stocks[item_id],
            minimum_stock=max(0.0, clean_float(item["minimum_stock"])),
            total_validation_mae=total_mae,
            forecast_days=forecast_days,
            database_today=database_today,
        )
        if forecast_result is None:
            skipped_items.append({"item_id": item_id, "item_name": item_name, "reason": skip_reason})
            continue
        forecast_result["item_name"] = item_name
        forecasts.append(forecast_result)

    forecasts.sort(
        key=lambda result: (
            {"Shortage Risk": 3, "Reorder Needed": 2, "Sufficient": 1}.get(result["forecast_status"], 0),
            result["shortage_probability"],
            result["recommended_reorder"],
        ),
        reverse=True
    )

    skipped_item_ids = sorted({int(item["item_id"]) for item in skipped_items})
    method_counts = {METHOD_XGBOOST: 0, METHOD_RECENT_MEAN: 0}
    for result in forecasts:
        method_counts[result["method"]] += 1

    return {
        "model_metrics": model_metrics,
        "accuracy": accuracy,
        "forecasts": forecasts,
        "method_counts": method_counts,
        "training_data_end": pd.Timestamp(clean_history["record_date"].max()).strftime("%Y-%m-%d"),
        "skipped_items": skipped_items,
        "skipped_item_ids": skipped_item_ids,
        "total_forecastable_items": len(forecastable_items),
        "total_forecasted_items": len(forecasts),
        "total_skipped_items": len(skipped_items),
    }


# ============================================================
# MAIN EXECUTION
# ============================================================

def main():
    if len(sys.argv) < 2:
        print_json({
            "success": False,
            "error": (
                "Missing branch_id. Usage: "
                "py forecasting.py "
                "<branch_id> [forecast_days]"
            )
        })

        return 1

    if len(sys.argv) > 3:
        print_json({
            "success": False,
            "error": (
                "Too many arguments. Usage: "
                "py forecasting.py "
                "<branch_id> [forecast_days]"
            )
        })

        return 1

    branch_id = str(
        sys.argv[1]
    ).strip()

    if not branch_id:
        print_json({
            "success": False,
            "error": "branch_id cannot be empty."
        })

        return 1

    try:
        forecast_days = (
            int(sys.argv[2])
            if len(sys.argv) == 3
            else DEFAULT_FORECAST_DAYS
        )

    except ValueError:
        print_json({
            "success": False,
            "error": (
                "forecast_days must be a whole number."
            )
        })

        return 1

    if forecast_days not in ALLOWED_FORECAST_DAYS:
        print_json({
            "success": False,
            "error": (
                "forecast_days must be one of: 7, 14, or 30."
            )
        })

        return 1

    try:
        result = forecast_inventory(
            branch_id=branch_id,
            forecast_days=forecast_days
        )

        if not result["forecasts"]:
            print_json({
                "success": False,
                "branch_id": branch_id,
                "forecast_days": forecast_days,
                "error": (
                    "No forecasts could be generated."
                ),
                "model_metrics": (
                    result["model_metrics"]
                ),
                "skipped_items": (
                    result["skipped_items"]
                ),
                "skipped_item_ids": (
                    result["skipped_item_ids"]
                ),
            })

            return 1

        print_json({
            "success": True,
            "branch_id": branch_id,
            "forecast_days": forecast_days,
            "forecast_method": (
                "Direct 7/14/30-day XGBoost Regressors with a "
                "recent 28-day mean fallback per item"
            ),
            "forecast_target": (
                f"Total quantity consumed over the next {forecast_days} days"
            ),
            "method_counts": result["method_counts"],
            "training_data_end": result["training_data_end"],
            "max_training_gap_days": max_training_gap_days(),
            "evaluation_metrics": [
                "MAE",
                "RMSE",
                "SMAPE"
            ],
            "feature_columns": FEATURE_COLUMNS,
            "accuracy": result["accuracy"],
            "model_metrics": (
                result["model_metrics"]
            ),
            "forecasts": (
                result["forecasts"]
            ),
            "total_forecastable_items": (
                result["total_forecastable_items"]
            ),
            "total_forecasted_items": (
                result["total_forecasted_items"]
            ),
            "total_skipped_items": (
                result["total_skipped_items"]
            ),
            "skipped_items": (
                result["skipped_items"]
            ),
            "skipped_item_ids": (
                result["skipped_item_ids"]
            ),
        })

        return 0

    except TrainingDataOutdatedError as outdated:
        print_json({
            "success": False,
            "branch_id": branch_id,
            "forecast_days": forecast_days,
            "error_code": "training_data_outdated",
            "error": str(outdated),
        })

        return 1

    except mysql.connector.Error as database_error:
        print_json({
            "success": False,
            "error": (
                "Database error: "
                f"{str(database_error)}"
            )
        })

        return 1

    except Exception as error:
        print_json({
            "success": False,
            "error": str(error)
        })

        return 1


if __name__ == "__main__":
    sys.exit(main())