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
import math
import os
import sys
import warnings
from datetime import timedelta

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
MINIMUM_HISTORY_RECORDS = 15

RESTOCKING_LEAD_DAYS = int(
    os.getenv("SMARTBITECARE_RESTOCK_LEAD_DAYS", "7")
)

SAFETY_BUFFER_DAYS = int(
    os.getenv("SMARTBITECARE_SAFETY_BUFFER_DAYS", "3")
)


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
    return mysql.connector.connect(**DB_CONFIG)


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


def determine_risk_status(
    days_of_supply,
    estimated_ending_stock,
    minimum_stock
):
    """
    Apply the shortage-risk decision rule.

    A shortage risk exists when:
    1. Days of supply is less than the restocking lead time
       plus the safety buffer; or
    2. Forecasted ending stock is below minimum stock.
    """
    required_supply_days = (
        RESTOCKING_LEAD_DAYS
        + SAFETY_BUFFER_DAYS
    )

    insufficient_supply_days = (
        days_of_supply is not None
        and days_of_supply < required_supply_days
    )

    below_minimum_stock = (
        estimated_ending_stock < minimum_stock
    )

    if insufficient_supply_days or below_minimum_stock:
        return "Shortage Risk"

    return "Sufficient"


# ============================================================
# DATABASE QUERIES
# ============================================================

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
                c.category_name
            FROM inventory_items AS i
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
        query = """
            SELECT
                item_id,
                COALESCE(
                    SUM(
                        CASE
                            WHEN expiration_date IS NULL
                                 OR expiration_date >= CURDATE()
                            THEN quantity_available
                            ELSE 0
                        END
                    ),
                    0
                ) AS total_stock
            FROM inventory_stocks
            WHERE branch_id = %s
            GROUP BY item_id
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

    valid_mask = denominator > 0.000001

    individual_errors[valid_mask] = (
        2.0
        * absolute_difference[valid_mask]
        / denominator[valid_mask]
    )

    return float(
        np.mean(individual_errors) * 100
    )


def evaluate_xgboost_model(model_data):
    """
    Evaluate XGBoost using an 80/20 chronological split.

    Earlier dates are used for training.
    Later dates are used for testing.
    """
    unique_dates = sorted(
        model_data["record_date"]
        .dt.normalize()
        .unique()
    )

    if len(unique_dates) < 10:
        raise ValueError(
            "Not enough unique dates for an 80/20 "
            "chronological evaluation."
        )

    split_position = int(
        len(unique_dates) * 0.80
    )

    split_position = max(
        1,
        min(
            split_position,
            len(unique_dates) - 1
        )
    )

    testing_start_date = pd.Timestamp(
        unique_dates[split_position]
    )

    training_part = model_data[
        model_data["record_date"]
        < testing_start_date
    ].copy()

    testing_part = model_data[
        model_data["record_date"]
        >= testing_start_date
    ].copy()

    if training_part.empty or testing_part.empty:
        raise ValueError(
            "The 80/20 split produced an empty "
            "training or testing dataset."
        )

    x_training = training_part[
        FEATURE_COLUMNS
    ].astype(float)

    y_training = training_part[
        TARGET_COLUMN
    ].astype(float)

    x_testing = testing_part[
        FEATURE_COLUMNS
    ].astype(float)

    y_testing = testing_part[
        TARGET_COLUMN
    ].astype(float)

    evaluation_model = create_xgboost_model()

    evaluation_model.fit(
        x_training,
        y_training,
        eval_set=[(x_testing, y_testing)],
        verbose=False
    )

    # predict() is the required XGBoost method name.
    testing_forecasts = evaluation_model.predict(
        x_testing
    )

    testing_forecasts = np.maximum(
        testing_forecasts,
        0
    )

    mae = mean_absolute_error(
        y_testing,
        testing_forecasts
    )

    rmse = math.sqrt(
        mean_squared_error(
            y_testing,
            testing_forecasts
        )
    )

    smape = calculate_smape(
        y_testing,
        testing_forecasts
    )

    # Calculate validation MAE for each item.
    testing_results = testing_part[
        ["item_id"]
    ].copy()

    testing_results["actual"] = (
        y_testing.to_numpy()
    )

    testing_results["forecast"] = (
        testing_forecasts
    )

    testing_results["absolute_error"] = np.abs(
        testing_results["actual"]
        - testing_results["forecast"]
    )

    item_mae = (
        testing_results
        .groupby("item_id")["absolute_error"]
        .mean()
        .to_dict()
    )

    metrics = {
        "training_records": int(
            len(training_part)
        ),
        "testing_records": int(
            len(testing_part)
        ),
        "training_percentage": 80,
        "testing_percentage": 20,
        "testing_start_date": (
            testing_start_date.strftime("%Y-%m-%d")
        ),
        "mae": round(
            clean_float(mae),
            4
        ),
        "rmse": round(
            clean_float(rmse),
            4
        ),
        "smape_percent": round(
            clean_float(smape),
            4
        ),
    }

    return metrics, item_mae


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
    forecast_days
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
    )

    forecast_start = (
        last_record_date
        + timedelta(days=1)
    )

    forecast_end = (
        last_record_date
        + timedelta(days=forecast_days)
    )

    daily_forecasts = []
    total_forecasted_consumption = 0.0
    expected_reorder_date = None
    expected_stockout_date = None

    for day_offset in range(forecast_days):
        forecast_date = (
            forecast_start
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
                2
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

    recommended_reorder = max(
        0,
        math.ceil(
            conservative_consumption
            + minimum_stock
            - current_stock
        )
    )

    forecast_status = determine_risk_status(
        days_of_supply=days_of_supply,
        estimated_ending_stock=estimated_ending_stock,
        minimum_stock=minimum_stock
    )

    shortage_threshold = max(
        0.0,
        current_stock - minimum_stock
    )

    if current_stock <= minimum_stock:
        shortage_probability = 1.0
    else:
        z_score = (
            total_forecasted_consumption
            - shortage_threshold
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
            2
        ),
        "minimum_stock": round(
            minimum_stock,
            2
        ),
        "forecasted_daily_consumption": round(
            average_daily_consumption,
            2
        ),
        "forecasted_consumption": round(
            total_forecasted_consumption,
            2
        ),
        "conservative_consumption": round(
            conservative_consumption,
            2
        ),
        "estimated_ending_stock": round(
            estimated_ending_stock,
            2
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

    if not forecastable_items:
        raise ValueError(
            "No inventory items are enabled for forecasting."
        )

    if raw_training_data.empty:
        raise ValueError(
            "No historical training records were found "
            f"for branch {branch_id}."
        )

    clean_history, model_data = prepare_training_data(
        raw_training_data
    )

    if model_data.empty:
        raise ValueError(
            "No usable model records remained after "
            "feature engineering."
        )

    model_metrics, item_validation_errors = (
        evaluate_xgboost_model(model_data)
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

        current_stock = current_stocks.get(
            item_id,
            0.0
        )

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
                forecast_days=forecast_days
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
            result["forecast_status"]
            == "Shortage Risk",
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

    if forecast_days < 1 or forecast_days > 365:
        print_json({
            "success": False,
            "error": (
                "forecast_days must be between "
                "1 and 365."
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