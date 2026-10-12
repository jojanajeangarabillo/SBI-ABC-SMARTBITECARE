#!/usr/bin/env python3
"""
forecasting.py

SmartBiteCare Medical Supply Demand Forecasting

Algorithm:
    XGBoost Regressor

Target:
    Daily quantity consumed per medical supply item

Evaluation:
    80/20 chronological split
    MAE, RMSE, and SMAPE

Usage:
    py forecasting.py <branch_id> [forecast_days]

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

FEATURE_COLUMNS = [
    "dow",
    "month",
    "day",
    "is_weekend",
    "patient_volume",
    "supply_type",
    "lag_1",
    "lag_7",
    "rolling_7",
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
# DATA PREPROCESSING AND FEATURE ENGINEERING
# ============================================================

def prepare_training_data(raw_data):
    """Clean records and create the nine model features."""
    data = raw_data.copy()

    data["record_date"] = pd.to_datetime(
        data["record_date"],
        errors="coerce"
    )

    numeric_columns = [
        "patient_count",
        "quantity_used",
        "beginning_stock",
        "stock_received",
        "ending_stock",
        "minimum_stock",
    ]

    for column in numeric_columns:
        data[column] = pd.to_numeric(
            data[column],
            errors="coerce"
        )

    # Missing records stay missing and are removed.
    # They are not converted to zero consumption.
    data = data.dropna(
        subset=[
            "record_date",
            "item_id",
            "item_name",
            "patient_count",
            "quantity_used",
        ]
    )

    # Remove invalid negative values.
    data = data[
        (data["quantity_used"] >= 0)
        & (data["patient_count"] >= 0)
    ].copy()

    data = data.sort_values(
        ["item_id", "record_date"]
    )

    # Remove duplicate item/date records.
    data = data.drop_duplicates(
        subset=[
            "branch_id",
            "item_id",
            "record_date"
        ],
        keep="last"
    )

    # Calendar features.
    data["dow"] = data["record_date"].dt.dayofweek
    data["month"] = data["record_date"].dt.month
    data["day"] = data["record_date"].dt.day

    data["is_weekend"] = (
        data["dow"] >= 5
    ).astype(int)

    # Patient-volume feature.
    data["patient_volume"] = data[
        "patient_count"
    ].astype(float)

    # Encoded supply-type feature.
    supply_information = data[
        "item_name"
    ].apply(get_supply_type)

    data["supply_type_name"] = (
        supply_information.apply(
            lambda value: value[0]
        )
    )

    data["supply_type"] = (
        supply_information.apply(
            lambda value: value[1]
        )
    )

    # Lag features are created per item.
    grouped_usage = data.groupby(
        "item_id",
        group_keys=False
    )["quantity_used"]

    data["lag_1"] = grouped_usage.shift(1)
    data["lag_7"] = grouped_usage.shift(7)

    # shift(1) prevents target leakage.
    data["rolling_7"] = (
        data.groupby(
            "item_id",
            group_keys=False
        )["quantity_used"]
        .transform(
            lambda series: (
                series.shift(1)
                .rolling(
                    window=7,
                    min_periods=7
                )
                .mean()
            )
        )
    )

    model_data = data.dropna(
        subset=FEATURE_COLUMNS + [TARGET_COLUMN]
    ).copy()

    return data, model_data


# ============================================================
# XGBOOST MODEL
# ============================================================

def create_xgboost_model():
    """Create the XGBoost Regressor."""
    return XGBRegressor(
        objective="reg:squarederror",
        n_estimators=500,
        learning_rate=0.03,
        max_depth=6,
        min_child_weight=2,
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
    actual = np.asarray(
        actual_values,
        dtype=float
    )

    forecasted = np.asarray(
        forecasted_values,
        dtype=float
    )

    denominator = (
        np.abs(actual)
        + np.abs(forecasted)
    )

    absolute_difference = np.abs(
        actual - forecasted
    )

    individual_errors = np.zeros_like(
        denominator,
        dtype=float
    )

    valid_mask = denominator > 0

    individual_errors[valid_mask] = (
        2.0
        * absolute_difference[valid_mask]
        / denominator[valid_mask]
    )

    return float(
        np.mean(individual_errors) * 100
    )


EVALUATION_VERSION = 'sbc-evaluation-v1'


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


def evaluate_xgboost_model(model_data, clean_history, item_catalog):
    """One chronological 80/20 holdout plus production-recursive backtests.

    One-step validation uses earlier observed consumption and that day's known
    patient count. Recursive backtests use ONLY pre-cutoff histories and their
    estimated patient volume, exactly as generate_item_forecast does in production.
    Missing holdout dates never become zero actual consumption.
    """
    dates = sorted(model_data['record_date'].dt.normalize().unique())
    split = max(1, min(int(len(dates) * .8), len(dates) - 1)) if len(dates) >= 10 else None
    test_start = pd.Timestamp(dates[split]) if split is not None else None
    training = model_data[model_data['record_date'] < test_start].copy() if test_start is not None else model_data.iloc[:0].copy()
    testing = model_data[model_data['record_date'] >= test_start].copy() if test_start is not None else model_data.iloc[:0].copy()
    ready = test_start is not None and not training.empty and not testing.empty
    def date_text(value):
        return pd.Timestamp(value).strftime('%Y-%m-%d') if pd.notna(value) else None

    source_columns = ['branch_id', 'item_id', 'record_date', 'patient_count', 'quantity_used', 'item_name']
    source_json = clean_history.sort_values(['item_id', 'record_date'])[source_columns].to_json(
        orient='records', date_format='iso', double_precision=15)
    params = create_xgboost_model().get_params()
    # Store only JSON-safe model parameters; None records explicit defaults.
    params = {k: v for k, v in params.items() if v is None or isinstance(v, (str, int, float, bool))}
    params = {k: str(v) if isinstance(v, float) and not math.isfinite(v) else v for k, v in params.items()}
    accuracy = {
        'version': EVALUATION_VERSION,
        'source_hash': hashlib.sha256(source_json.encode('utf-8')).hexdigest(),
        'model_version': str(xgboost.__version__),
        'model_parameters': params,
        'feature_columns': FEATURE_COLUMNS,
        'training_records': int(len(training)),
        'testing_records': int(len(testing)),
        'training_start': date_text(training['record_date'].min()),
        'training_end': date_text(training['record_date'].max()),
        'testing_start': date_text(testing['record_date'].min()),
        'testing_end': date_text(testing['record_date'].max()),
        'overall_smape_percent': None,
        'rows': [],
    }
    model_metrics = {'training_records': len(training), 'testing_records': len(testing),
        'training_percentage': 80, 'testing_percentage': 20,
        'testing_start_date': accuracy['testing_start'], 'mae': 0.0, 'rmse': 0.0,
        'smape_percent': None, 'evaluation_status': 'Insufficient History'}
    item_mae = {}
    evaluation_model = None
    if ready:
        evaluation_model = create_xgboost_model()
        evaluation_model.fit(training[FEATURE_COLUMNS].astype(float), training[TARGET_COLUMN].astype(float))
        predictions = np.maximum(evaluation_model.predict(testing[FEATURE_COLUMNS].astype(float)), 0)
        testing = testing.copy()
        testing['evaluation_forecast'] = np.round(predictions, 4)
        scores = forecast_error_scores(testing[TARGET_COLUMN], testing['evaluation_forecast'])
        model_metrics.update({k: scores[k] for k in ['mae', 'rmse', 'smape_percent']})
        model_metrics['evaluation_status'] = 'Available'
        accuracy['overall_smape_percent'] = scores['smape_percent']

    catalog = {int(row['item_id']): row for row in item_catalog}
    for item_id in sorted(clean_history['item_id'].astype(int).unique()):
        item = catalog.get(int(item_id))
        if not item:
            continue
        item_training = training[training['item_id'] == item_id]
        item_testing = testing[testing['item_id'] == item_id]
        history = clean_history[clean_history['item_id'] == item_id]
        common = {
            'item_id': int(item_id), 'item_name': str(item['item_name']).strip(),
            'base_unit': str(item.get('base_unit') or 'unit').strip(),
            'training_records': int(len(item_training)),
            'training_start': date_text(item_training['record_date'].min()),
            'training_end': date_text(item_training['record_date'].max()),
        }
        one = dict(common, method='one_step', horizon_days=1,
            testing_records=int(len(item_testing)),
            testing_start=date_text(item_testing['record_date'].min()),
            testing_end=date_text(item_testing['record_date'].max()),
            status='Insufficient History', reason='Not enough dates for a chronological 80/20 evaluation.')
        if ready and not item_testing.empty:
            scores = forecast_error_scores(item_testing[TARGET_COLUMN], item_testing['evaluation_forecast'])
            one.update(scores, status='Available', reason=None)
            item_mae[int(item_id)] = scores['mae']
        elif ready:
            one['reason'] = 'This item has no observed testing records after the shared chronological cutoff.'
        accuracy['rows'].append(one)

        for horizon in sorted(ALLOWED_FORECAST_DAYS):
            origin = test_start - timedelta(days=1) if ready else None
            start = origin + timedelta(days=1) if ready else None
            end = origin + timedelta(days=horizon) if ready else None
            row = dict(common, method='recursive', horizon_days=int(horizon),
                testing_records=0, testing_start=date_text(start), testing_end=date_text(end),
                status='Insufficient History', reason='Not enough dates for a chronological 80/20 evaluation.')
            if ready:
                # Raw actual observations are used for scoring, never future lag features.
                actual = history[(history['record_date'] >= start) & (history['record_date'] <= end)]
                row['testing_records'] = int(len(actual))
                prefix = history[history['record_date'] <= origin].copy()
                expected_dates = pd.date_range(start, end, freq='D')
                if len(prefix) < MINIMUM_HISTORY_RECORDS:
                    row['reason'] = f'Only {len(prefix)} pre-cutoff observations; {MINIMUM_HISTORY_RECORDS} are required.'
                elif len(actual) != horizon or set(actual['record_date'].dt.normalize()) != set(expected_dates):
                    row['reason'] = f'Holdout contains {len(actual)} of {horizon} required daily observations. Missing dates are not treated as zero.'
                else:
                    forecast, reason = generate_item_forecast(evaluation_model, prefix,
                        0.0, 0.0, 0.0, 0.0, int(horizon), origin)
                    if forecast is None:
                        row['reason'] = reason
                    else:
                        predicted = {pd.Timestamp(d['date']): d['forecasted_consumption']
                            for d in forecast['daily_forecasts']}
                        if set(predicted) != set(expected_dates):
                            raise ValueError('Recursive evaluation returned an incomplete horizon.')
                        actual_by_date = actual.set_index('record_date')[TARGET_COLUMN]
                        scores = forecast_error_scores([actual_by_date.loc[d] for d in expected_dates],
                            [predicted[d] for d in expected_dates])
                        # Preserve the production horizon total (raw daily predictions summed
                        # before four-decimal rounding), rather than summing rounded daily rows.
                        scores['forecast_total'] = forecast['forecasted_consumption']
                        scores['total_absolute_error'] = round(abs(scores['actual_total'] - scores['forecast_total']), 4)
                        row.update(scores, status='Available', reason=None)
            accuracy['rows'].append(row)
    return model_metrics, item_mae, accuracy


def train_final_model(model_data):
    """Train the final model using all prepared records."""
    x_all = model_data[
        FEATURE_COLUMNS
    ].astype(float)

    y_all = model_data[
        TARGET_COLUMN
    ].astype(float)

    final_model = create_xgboost_model()

    final_model.fit(
        x_all,
        y_all
    )

    return final_model


# ============================================================
# RECURSIVE ITEM FORECASTING
# ============================================================

def generate_item_forecast(
    model,
    item_history,
    current_stock,
    minimum_stock,
    item_validation_mae,
    overall_validation_mae,
    forecast_days,
    database_today
):
    """Generate a recursive forecast for one item."""
    history = item_history.copy()

    history = history.sort_values(
        "record_date"
    )

    history = history.drop_duplicates(
        subset=["record_date"],
        keep="last"
    )

    history = history[
        history["quantity_used"] >= 0
    ]

    # Only use records up to the database's current date. This prevents
    # accidental future-dated training records from leaking into a forecast.
    database_today = pd.Timestamp(database_today).normalize()
    history = history[
        pd.to_datetime(history["record_date"]).dt.normalize()
        <= database_today
    ]

    if len(history) < MINIMUM_HISTORY_RECORDS:
        return None, (
            f"Only {len(history)} valid historical "
            f"records were found. At least "
            f"{MINIMUM_HISTORY_RECORDS} are required."
        )

    item_id = int(
        history.iloc[0]["item_id"]
    )

    item_name = str(
        history.iloc[0]["item_name"]
    ).strip()

    supply_type_name, supply_type_code = (
        get_supply_type(item_name)
    )

    usage_history = (
        history["quantity_used"]
        .astype(float)
        .tolist()
    )

    # Use the recent seven-day average patient volume.
    recent_patient_volume = (
        history["patient_count"]
        .astype(float)
        .tail(7)
    )

    expected_patient_volume = clean_float(
        recent_patient_volume.mean()
    )

    last_record_date = pd.Timestamp(
        history["record_date"].max()
    ).normalize()

    # The user-facing forecast always starts tomorrow and covers exactly
    # the selected 7, 14, or 30 day horizon.
    forecast_start = (
        database_today
        + timedelta(days=1)
    )

    forecast_end = (
        forecast_start
        + timedelta(days=forecast_days - 1)
    )

    # If historical data is older than today, recursively simulate the gap
    # first so lag_1, lag_7, and rolling_7 are updated before the requested
    # forecast period begins. Gap predictions are NOT counted in the visible
    # forecast totals.
    simulation_start = (
        last_record_date
        + timedelta(days=1)
    )

    simulation_days = (
        forecast_end - simulation_start
    ).days + 1

    if simulation_days < forecast_days:
        simulation_days = forecast_days
        simulation_start = forecast_start

    daily_forecasts = []
    total_forecasted_consumption = 0.0
    expected_reorder_date = None
    expected_stockout_date = None

    for day_offset in range(simulation_days):
        forecast_date = (
            simulation_start
            + timedelta(days=day_offset)
        )

        dow = forecast_date.weekday()
        month = forecast_date.month
        day = forecast_date.day
        is_weekend = int(dow >= 5)

        lag_1 = (
            usage_history[-1]
            if usage_history
            else 0.0
        )

        lag_7 = (
            usage_history[-7]
            if len(usage_history) >= 7
            else lag_1
        )

        rolling_7 = (
            clean_float(
                np.mean(usage_history[-7:])
            )
            if usage_history
            else 0.0
        )

        future_features = pd.DataFrame(
            [[
                dow,
                month,
                day,
                is_weekend,
                expected_patient_volume,
                supply_type_code,
                lag_1,
                lag_7,
                rolling_7,
            ]],
            columns=FEATURE_COLUMNS,
            dtype=float
        )

        # This remains predict() because it is the official
        # XGBoost API method.
        daily_consumption = clean_float(
            model.predict(
                future_features
            )[0]
        )

        daily_consumption = max(
            0.0,
            daily_consumption
        )

        usage_history.append(
            daily_consumption
        )

        # Predictions before forecast_start only bridge stale historical data.
        # They update lag features but are not included in the selected horizon.
        if forecast_date < forecast_start:
            continue

        total_forecasted_consumption += (
            daily_consumption
        )

        estimated_remaining_stock = (
            current_stock
            - total_forecasted_consumption
        )

        if (
            expected_reorder_date is None
            and estimated_remaining_stock
            <= minimum_stock
        ):
            expected_reorder_date = (
                forecast_date.strftime(
                    "%Y-%m-%d"
                )
            )

        if (
            expected_stockout_date is None
            and estimated_remaining_stock <= 0
        ):
            expected_stockout_date = (
                forecast_date.strftime(
                    "%Y-%m-%d"
                )
            )

        daily_forecasts.append({
            "date": forecast_date.strftime(
                "%Y-%m-%d"
            ),
            "forecasted_consumption": round(
                daily_consumption,
            4
            ),
            "estimated_remaining_stock": round(
                max(
                    0.0,
                    estimated_remaining_stock
                ),
                2
            ),
            "is_weekend": is_weekend,
        })

    # Classify and publish the same four-decimal demand total.
    total_forecasted_consumption = forecast_quantity_ticks(total_forecasted_consumption) / 10000

    average_daily_consumption = (
        total_forecasted_consumption
        / forecast_days
        if forecast_days > 0
        else 0.0
    )

    if average_daily_consumption > 0:
        days_of_supply = (
            current_stock
            / average_daily_consumption
        )
    else:
        days_of_supply = None

    estimated_ending_stock = max(
        0.0,
        current_stock
        - total_forecasted_consumption
    )

    validation_mae = clean_float(
        item_validation_mae,
        default=overall_validation_mae
    )

    total_uncertainty = max(
        validation_mae
        * math.sqrt(forecast_days),
        total_forecasted_consumption * 0.05,
        0.000001
    )

    conservative_consumption = (
        total_forecasted_consumption
        + (1.645 * total_uncertainty)
    )

    # Reorder quantity is intentionally based on an explainable replenishment
    # target: enough stock to cover the selected forecast horizon and finish at
    # the configured minimum-stock level. Being below minimum no longer forces
    # an artificial 100% shortage probability.
    decision = classify_forecast_status(
        current_stock, minimum_stock, total_forecasted_consumption, forecast_days
    )
    recommended_reorder = decision["recommended_reorder"]
    stock_status = decision["stock_status"]
    forecast_status = decision["forecast_status"]

    # Approximate probability that demand over the selected horizon exceeds
    # current usable stock (stockout), rather than probability of merely going
    # below the minimum-stock target.
    if current_stock <= 0:
        shortage_probability = (
            1.0 if total_forecasted_consumption > 0 else 0.0
        )
    else:
        z_score = (
            total_forecasted_consumption
            - current_stock
        ) / total_uncertainty

        shortage_probability = 0.5 * (
            1.0
            + math.erf(
                z_score / math.sqrt(2.0)
            )
        )

    shortage_probability = min(
        1.0,
        max(
            0.0,
            shortage_probability
        )
    )

    return {
        "item_id": item_id,
        "item_name": item_name,
        "supply_type": supply_type_name,
        "current_stock": round(
            current_stock,
            4
        ),
        "minimum_stock": round(
            minimum_stock,
            4
        ),
        "stock_status": stock_status,
        "forecasted_daily_consumption": round(
            average_daily_consumption,
            4
        ),
        "forecasted_consumption": round(
            total_forecasted_consumption,
            4
        ),
        "conservative_consumption": round(
            conservative_consumption,
            4
        ),
        "estimated_ending_stock": round(
            estimated_ending_stock,
            4
        ),
        "days_of_supply_remaining": (
            round(days_of_supply, 2)
            if days_of_supply is not None
            else None
        ),
        "restocking_lead_days": (
            RESTOCKING_LEAD_DAYS
        ),
        "safety_buffer_days": (
            SAFETY_BUFFER_DAYS
        ),
        "status_rule_version": STATUS_RULE_VERSION,
        "forecast_status": forecast_status,
        "shortage_probability": round(
            shortage_probability,
            4
        ),
        "recommended_reorder": (
            recommended_reorder
        ),
        "expected_reorder_date": (
            expected_reorder_date
        ),
        "expected_stockout_date": (
            expected_stockout_date
        ),
        "validation_mae": round(
            validation_mae,
            4
        ),
        "forecast_start": (
            forecast_start.strftime(
                "%Y-%m-%d"
            )
        ),
        "forecast_end": (
            forecast_end.strftime(
                "%Y-%m-%d"
            )
        ),
        "daily_forecasts": daily_forecasts,
    }, None


# ============================================================
# COMPLETE FORECASTING PROCESS
# ============================================================

def forecast_inventory(branch_id, forecast_days):
    """Evaluate, train, and run the forecasting model."""
    forecastable_items = load_forecastable_items()
    raw_training_data = load_training_data(branch_id)
    current_stocks = load_current_stocks(branch_id)
    database_today = load_database_today()

    if not forecastable_items:
        raise ValueError(
            "No inventory items are enabled for forecasting."
        )

    if raw_training_data.empty:
        raise ValueError(
            "No historical training records were found "
            f"for branch {branch_id}."
        )

    # Exclude future-dated data before evaluation and final model training,
    # not only while building each item's forecast history.
    raw_dates = pd.to_datetime(raw_training_data['record_date'], errors='coerce')
    raw_training_data = raw_training_data.loc[
        raw_dates.dt.normalize() <= pd.Timestamp(database_today).normalize()
    ].copy()

    clean_history, model_data = prepare_training_data(
        raw_training_data
    )

    if model_data.empty:
        raise ValueError(
            "No usable model records remained after "
            "feature engineering."
        )

    model_metrics, item_validation_errors, accuracy = evaluate_xgboost_model(
        model_data, clean_history, forecastable_items
    )

    final_model = train_final_model(
        model_data
    )

    forecastable_item_map = {
        int(item["item_id"]): item
        for item in forecastable_items
    }

    history_item_ids = set(
        clean_history["item_id"]
        .astype(int)
        .tolist()
    )

    forecasts = []
    skipped_items = []

    for item_id, item in forecastable_item_map.items():
        item_name = str(
            item["item_name"]
        ).strip()

        if item_id not in history_item_ids:
            skipped_items.append({
                "item_id": item_id,
                "item_name": item_name,
                "reason": (
                    "No historical training records "
                    "were found for this branch."
                )
            })

            continue

        item_history = clean_history[
            clean_history["item_id"] == item_id
        ].copy()

        # A missing inventory_stocks row is not the same thing as confirmed
        # zero stock. Skip it instead of manufacturing a false 100% risk.
        if item_id not in current_stocks:
            skipped_items.append({
                "item_id": item_id,
                "item_name": item_name,
                "reason": (
                    "No inventory stock record exists for this branch. "
                    "Initialize the item in Inventory Stock Management first."
                )
            })
            continue

        current_stock = current_stocks[item_id]

        minimum_stock = max(
            0.0,
            clean_float(
                item["minimum_stock"]
            )
        )

        item_mae = item_validation_errors.get(
            item_id,
            model_metrics["mae"]
        )

        forecast_result, skip_reason = (
            generate_item_forecast(
                model=final_model,
                item_history=item_history,
                current_stock=current_stock,
                minimum_stock=minimum_stock,
                item_validation_mae=item_mae,
                overall_validation_mae=(
                    model_metrics["mae"]
                ),
                forecast_days=forecast_days,
                database_today=database_today
            )
        )

        if forecast_result is None:
            skipped_items.append({
                "item_id": item_id,
                "item_name": item_name,
                "reason": skip_reason
            })

            continue

        forecasts.append(
            forecast_result
        )

    forecasts.sort(
        key=lambda result: (
            {"Shortage Risk": 3, "Reorder Needed": 2, "Sufficient": 1}.get(result["forecast_status"], 0),
            result["shortage_probability"],
            result["recommended_reorder"],
        ),
        reverse=True
    )

    skipped_item_ids = sorted({
        int(item["item_id"])
        for item in skipped_items
    })

    return {
        "model_metrics": model_metrics,
        "accuracy": accuracy,
        "forecasts": forecasts,
        "skipped_items": skipped_items,
        "skipped_item_ids": skipped_item_ids,
        "total_forecastable_items": len(
            forecastable_items
        ),
        "total_forecasted_items": len(
            forecasts
        ),
        "total_skipped_items": len(
            skipped_items
        ),
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
                "XGBoost Regressor recursive "
                "demand forecasting"
            ),
            "forecast_target": (
                "Daily quantity consumed"
            ),
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