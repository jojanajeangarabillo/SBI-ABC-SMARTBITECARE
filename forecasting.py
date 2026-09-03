#!/usr/bin/env python3
"""
forecasting.py - SmartBiteCare Inventory Forecasting

Forecasts inventory consumption and possible shortages for a branch.

Command:
    python forecasting.py <branch_id> <forecast_days>

Example:
    python forecasting.py SBI-002 30

Output:
    JSON containing forecasts, shortage risk scores,
    daily projected stock, and evaluation metrics.
"""

import sys
import json
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
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "smartbitecare",
}


# ============================================================
# MODEL CONFIGURATION
# ============================================================

MINIMUM_REQUIRED_RECORDS = 30

FEATURE_COLUMNS = [
    "day_of_week",
    "month",
    "day_of_year",
    "is_weekend",
    "patient_count",
    "lag_1",
    "lag_7",
    "rolling_mean_7",
]


# ============================================================
# DATABASE FUNCTIONS
# ============================================================

def get_db_connection():
    """Create and return a MariaDB connection."""
    return mysql.connector.connect(**DB_CONFIG)


def load_training_data(branch_id):
    """
    Load forecasting records for predictable inventory items.
    """

    connection = None
    cursor = None

    try:
        connection = get_db_connection()
        cursor = connection.cursor(dictionary=True)

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
                i.minimum_stock AS current_minimum_stock,
                i.is_predictable
            FROM training_dataset AS t
            INNER JOIN inventory_items AS i
                ON i.item_id = t.item_id
            WHERE t.branch_id = %s
              AND i.is_predictable = 1
            ORDER BY t.item_id, t.record_date
        """

        cursor.execute(query, (branch_id,))
        records = cursor.fetchall()

        if not records:
            return pd.DataFrame()

        return pd.DataFrame(records)

    finally:
        if cursor is not None:
            cursor.close()

        if connection is not None and connection.is_connected():
            connection.close()


def get_current_stock(item_id, branch_id):
    """
    Return the item's total available stock for the selected branch.

    The old query used inventory_stocks.is_active, but that column
    does not exist in the current database.
    """

    connection = None
    cursor = None

    try:
        connection = get_db_connection()
        cursor = connection.cursor(dictionary=True)

        query = """
            SELECT
                COALESCE(SUM(quantity_available), 0) AS total_stock
            FROM inventory_stocks
            WHERE item_id = %s
              AND branch_id = %s
        """

        cursor.execute(query, (item_id, branch_id))
        result = cursor.fetchone()

        if not result:
            return 0.0

        return float(result["total_stock"] or 0)

    finally:
        if cursor is not None:
            cursor.close()

        if connection is not None and connection.is_connected():
            connection.close()


# ============================================================
# DATA PREPARATION
# ============================================================

def prepare_item_data(item_group):
    """
    Prepare date and lag features for one inventory item.

    The rolling mean is shifted by one day to prevent the current
    day's actual usage from leaking into its own input features.
    """

    group = item_group.copy()

    group["record_date"] = pd.to_datetime(
        group["record_date"],
        errors="coerce"
    )

    numeric_columns = [
        "patient_count",
        "beginning_stock",
        "quantity_used",
        "stock_received",
        "ending_stock",
        "minimum_stock_level",
        "current_minimum_stock",
    ]

    for column in numeric_columns:
        group[column] = pd.to_numeric(
            group[column],
            errors="coerce"
        )

    group = group.sort_values("record_date").reset_index(drop=True)

    group["day_of_week"] = group["record_date"].dt.dayofweek
    group["month"] = group["record_date"].dt.month
    group["day_of_year"] = group["record_date"].dt.dayofyear

    group["is_weekend"] = (
        group["day_of_week"] >= 5
    ).astype(int)

    # Historical consumption features
    group["lag_1"] = group["quantity_used"].shift(1)
    group["lag_7"] = group["quantity_used"].shift(7)

    group["rolling_mean_7"] = (
        group["quantity_used"]
        .shift(1)
        .rolling(window=7)
        .mean()
    )

    group = group.dropna(
        subset=[
            "record_date",
            "patient_count",
            "quantity_used",
            *FEATURE_COLUMNS,
        ]
    ).reset_index(drop=True)

    return group


# ============================================================
# EVALUATION FUNCTIONS
# ============================================================

def calculate_metrics(actual_values, predicted_values):
    """
    Calculate MAE, RMSE, MAPE, and WMAPE.

    Zero actual values are excluded from MAPE to prevent
    division-by-zero errors.
    """

    actual = np.asarray(actual_values, dtype=float)
    predicted = np.asarray(predicted_values, dtype=float)

    mae = mean_absolute_error(actual, predicted)

    rmse = np.sqrt(
        mean_squared_error(actual, predicted)
    )

    nonzero_mask = actual != 0

    if np.any(nonzero_mask):
        mape = np.mean(
            np.abs(
                (
                    actual[nonzero_mask]
                    - predicted[nonzero_mask]
                )
                / actual[nonzero_mask]
            )
        ) * 100
    else:
        mape = None

    total_actual = np.sum(np.abs(actual))

    if total_actual > 0:
        wmape = (
            np.sum(np.abs(actual - predicted))
            / total_actual
        ) * 100
    else:
        wmape = None

    return {
        "mae": round(float(mae), 4),
        "rmse": round(float(rmse), 4),
        "mape": (
            round(float(mape), 4)
            if mape is not None
            else None
        ),
        "wmape": (
            round(float(wmape), 4)
            if wmape is not None
            else None
        ),
    }


def create_xgboost_model():
    """Create the XGBoost regression model."""

    return XGBRegressor(
        n_estimators=200,
        max_depth=6,
        learning_rate=0.05,
        subsample=0.8,
        colsample_bytree=0.8,
        objective="reg:squarederror",
        random_state=42,
        n_jobs=-1,
    )


def evaluate_model(prepared_data, original_data):
    """
    Perform an 80:20 chronological evaluation.

    The first 80% of the dates are used for training.
    The final 20% are used for testing.
    """

    original_sorted = original_data.sort_values(
        "record_date"
    ).reset_index(drop=True)

    split_position = int(len(original_sorted) * 0.80)

    if split_position <= 0 or split_position >= len(original_sorted):
        return None

    split_date = pd.to_datetime(
        original_sorted.iloc[split_position]["record_date"]
    )

    training_part = prepared_data[
        prepared_data["record_date"] < split_date
    ]

    testing_part = prepared_data[
        prepared_data["record_date"] >= split_date
    ]

    if len(training_part) < 10 or len(testing_part) < 1:
        return None

    x_train = training_part[FEATURE_COLUMNS].values
    y_train = training_part["quantity_used"].values

    x_test = testing_part[FEATURE_COLUMNS].values
    y_test = testing_part["quantity_used"].values

    evaluation_model = create_xgboost_model()
    evaluation_model.fit(x_train, y_train)

    test_predictions = evaluation_model.predict(x_test)
    test_predictions = np.maximum(test_predictions, 0)

    metrics = calculate_metrics(
        y_test,
        test_predictions
    )

    metrics["training_records"] = int(len(training_part))
    metrics["testing_records"] = int(len(testing_part))

    metrics["training_start_date"] = (
        training_part["record_date"].min().strftime("%Y-%m-%d")
    )

    metrics["training_end_date"] = (
        training_part["record_date"].max().strftime("%Y-%m-%d")
    )

    metrics["testing_start_date"] = (
        testing_part["record_date"].min().strftime("%Y-%m-%d")
    )

    metrics["testing_end_date"] = (
        testing_part["record_date"].max().strftime("%Y-%m-%d")
    )

    return metrics


# ============================================================
# SHORTAGE CALCULATION
# ============================================================

def calculate_shortage_risk(
    current_stock,
    predicted_consumption,
    minimum_stock
):
    """
    Calculate a shortage risk score between 0 and 1.

    This is a calculated risk score, not a probability generated
    directly by the XGBoost model.
    """

    current_stock = float(current_stock)
    predicted_consumption = float(predicted_consumption)
    minimum_stock = float(minimum_stock)

    projected_stock = current_stock - predicted_consumption

    if projected_stock <= 0 and predicted_consumption > 0:
        return 1.0

    if minimum_stock > 0:
        warning_level = minimum_stock * 2

        risk_score = (
            warning_level - projected_stock
        ) / warning_level

        return float(
            np.clip(risk_score, 0.0, 1.0)
        )

    if current_stock <= 0:
        return 1.0 if predicted_consumption > 0 else 0.0

    risk_score = predicted_consumption / current_stock

    return float(
        np.clip(risk_score, 0.0, 1.0)
    )


def get_risk_status(risk_score):
    """Return a readable risk classification."""

    if risk_score >= 0.75:
        return "High Risk"

    if risk_score >= 0.40:
        return "Moderate Risk"

    return "Low Risk"


# ============================================================
# FORECASTING ENGINE
# ============================================================

def forecast_inventory(dataframe, branch_id, forecast_days=30):
    """
    Train a separate XGBoost model for each predictable item
    and generate future inventory-consumption forecasts.
    """

    if dataframe.empty:
        return [], []

    all_forecasts = []
    all_evaluations = []

    for item_id, original_group in dataframe.groupby("item_id"):
        original_group = original_group.copy()

        original_group["record_date"] = pd.to_datetime(
            original_group["record_date"],
            errors="coerce"
        )

        original_group = original_group.dropna(
            subset=["record_date", "quantity_used"]
        )

        original_group = original_group.sort_values(
            "record_date"
        ).reset_index(drop=True)

        if len(original_group) < MINIMUM_REQUIRED_RECORDS:
            continue

        prepared_group = prepare_item_data(original_group)

        if len(prepared_group) < MINIMUM_REQUIRED_RECORDS:
            continue

        item_name = str(
            original_group.iloc[0]["item_name"]
        ).strip()

        # ----------------------------------------------------
        # MODEL EVALUATION
        # ----------------------------------------------------

        evaluation = evaluate_model(
            prepared_group,
            original_group
        )

        if evaluation is not None:
            evaluation["item_id"] = int(item_id)
            evaluation["item_name"] = item_name
            all_evaluations.append(evaluation)

        # ----------------------------------------------------
        # TRAIN FINAL MODEL USING ALL AVAILABLE DATA
        # ----------------------------------------------------

        x_all = prepared_group[FEATURE_COLUMNS].values
        y_all = prepared_group["quantity_used"].values

        model = create_xgboost_model()
        model.fit(x_all, y_all)

        # ----------------------------------------------------
        # DETERMINE STOCK AND MINIMUM STOCK
        # ----------------------------------------------------

        current_stock = get_current_stock(
            item_id,
            branch_id
        )

        current_minimum = original_group.iloc[-1][
            "current_minimum_stock"
        ]

        historical_minimum = original_group.iloc[-1][
            "minimum_stock_level"
        ]

        if (
            pd.notna(current_minimum)
            and float(current_minimum) > 0
        ):
            minimum_stock = float(current_minimum)
        else:
            minimum_stock = float(
                historical_minimum or 0
            )

        # ----------------------------------------------------
        # GENERATE FUTURE DATES
        # ----------------------------------------------------

        last_training_date = prepared_group[
            "record_date"
        ].max()

        today = pd.Timestamp.today().normalize()

        forecast_base_date = max(
            last_training_date,
            today
        )

        future_dates = [
            forecast_base_date + timedelta(days=day_number)
            for day_number in range(1, forecast_days + 1)
        ]

        # Use recent patient volume as the future estimate
        recent_patient_count = float(
            prepared_group["patient_count"]
            .tail(7)
            .mean()
        )

        usage_history = (
            prepared_group["quantity_used"]
            .astype(float)
            .tolist()
        )

        total_predicted_consumption = 0.0
        projected_stock = float(current_stock)

        daily_forecast = []
        expected_shortage_date = None

        # ----------------------------------------------------
        # RECURSIVE DAILY FORECASTING
        # ----------------------------------------------------

        for future_date in future_dates:
            lag_1 = (
                usage_history[-1]
                if len(usage_history) >= 1
                else 0.0
            )

            lag_7 = (
                usage_history[-7]
                if len(usage_history) >= 7
                else lag_1
            )

            rolling_mean_7 = float(
                np.mean(usage_history[-7:])
            )

            future_features = pd.DataFrame(
                [{
                    "day_of_week": future_date.dayofweek,
                    "month": future_date.month,
                    "day_of_year": future_date.dayofyear,
                    "is_weekend": int(
                        future_date.dayofweek >= 5
                    ),
                    "patient_count": recent_patient_count,
                    "lag_1": lag_1,
                    "lag_7": lag_7,
                    "rolling_mean_7": rolling_mean_7,
                }],
                columns=FEATURE_COLUMNS
            )

            predicted_usage = float(
                model.predict(future_features.values)[0]
            )

            predicted_usage = max(
                0.0,
                predicted_usage
            )

            usage_history.append(predicted_usage)

            total_predicted_consumption += predicted_usage
            projected_stock -= predicted_usage

            is_low_stock = (
                projected_stock <= minimum_stock
            )

            if is_low_stock and expected_shortage_date is None:
                expected_shortage_date = (
                    future_date.strftime("%Y-%m-%d")
                )

            daily_forecast.append({
                "date": future_date.strftime("%Y-%m-%d"),
                "predicted_usage": round(
                    predicted_usage,
                    2
                ),
                "projected_stock": round(
                    projected_stock,
                    2
                ),
                "is_low_stock": bool(is_low_stock),
            })

        # ----------------------------------------------------
        # CALCULATE FINAL SHORTAGE RISK
        # ----------------------------------------------------

        risk_score = calculate_shortage_risk(
            current_stock=current_stock,
            predicted_consumption=total_predicted_consumption,
            minimum_stock=minimum_stock,
        )

        risk_status = get_risk_status(risk_score)

        item_forecast = {
            "item_id": int(item_id),
            "item_name": item_name,

            "current_stock": round(
                float(current_stock),
                2
            ),

            "minimum_stock": round(
                float(minimum_stock),
                2
            ),

            "predicted_consumption": round(
                float(total_predicted_consumption),
                2
            ),

            "remaining_stock": round(
                float(projected_stock),
                2
            ),

            # Kept for compatibility with existing PHP code
            "probability_score": round(
                float(risk_score),
                4
            ),

            "shortage_risk_score": round(
                float(risk_score),
                4
            ),

            "risk_percentage": round(
                float(risk_score) * 100,
                2
            ),

            "risk_status": risk_status,

            "expected_shortage_date": expected_shortage_date,

            "last_training_date": (
                last_training_date.strftime("%Y-%m-%d")
            ),

            "forecast_start_date": (
                future_dates[0].strftime("%Y-%m-%d")
            ),

            "forecast_end_date": (
                future_dates[-1].strftime("%Y-%m-%d")
            ),

            "daily_forecast": daily_forecast,

            "evaluation": evaluation,
        }

        all_forecasts.append(item_forecast)

    all_forecasts.sort(
        key=lambda forecast: forecast["shortage_risk_score"],
        reverse=True
    )

    return all_forecasts, all_evaluations


# ============================================================
# OVERALL EVALUATION SUMMARY
# ============================================================

def summarize_evaluations(evaluations):
    """Calculate average model metrics across all forecasted items."""

    if not evaluations:
        return None

    mae_values = [
        result["mae"]
        for result in evaluations
        if result.get("mae") is not None
    ]

    rmse_values = [
        result["rmse"]
        for result in evaluations
        if result.get("rmse") is not None
    ]

    mape_values = [
        result["mape"]
        for result in evaluations
        if result.get("mape") is not None
    ]

    wmape_values = [
        result["wmape"]
        for result in evaluations
        if result.get("wmape") is not None
    ]

    return {
        "evaluated_items": len(evaluations),

        "average_mae": (
            round(float(np.mean(mae_values)), 4)
            if mae_values
            else None
        ),

        "average_rmse": (
            round(float(np.mean(rmse_values)), 4)
            if rmse_values
            else None
        ),

        "average_mape": (
            round(float(np.mean(mape_values)), 4)
            if mape_values
            else None
        ),

        "average_wmape": (
            round(float(np.mean(wmape_values)), 4)
            if wmape_values
            else None
        ),
    }


# ============================================================
# MAIN PROGRAM
# ============================================================

def main():
    if len(sys.argv) < 2:
        print(json.dumps({
            "success": False,
            "error": (
                "Missing branch_id. "
                "Example: python forecasting.py SBI-002 30"
            ),
        }))

        sys.exit(1)

    branch_id = str(sys.argv[1]).strip()

    try:
        forecast_days = (
            int(sys.argv[2])
            if len(sys.argv) > 2
            else 30
        )
    except ValueError:
        print(json.dumps({
            "success": False,
            "error": "forecast_days must be a whole number.",
        }))

        sys.exit(1)

    if forecast_days < 1 or forecast_days > 365:
        print(json.dumps({
            "success": False,
            "error": (
                "forecast_days must be between 1 and 365."
            ),
        }))

        sys.exit(1)

    try:
        training_data = load_training_data(branch_id)

        if training_data.empty:
            print(json.dumps({
                "success": False,
                "branch_id": branch_id,
                "error": (
                    "No forecasting records were found. "
                    "Check training_dataset, item mappings, "
                    "branch_id, and is_predictable."
                ),
            }))

            sys.exit(1)

        forecasts, evaluations = forecast_inventory(
            dataframe=training_data,
            branch_id=branch_id,
            forecast_days=forecast_days,
        )

        if not forecasts:
            print(json.dumps({
                "success": False,
                "branch_id": branch_id,
                "error": (
                    "No forecasts were generated. Each item "
                    f"needs at least {MINIMUM_REQUIRED_RECORDS} "
                    "valid historical records."
                ),
            }))

            sys.exit(1)

        evaluation_summary = summarize_evaluations(
            evaluations
        )

        high_risk_count = sum(
            1
            for forecast in forecasts
            if forecast["risk_status"] == "High Risk"
        )

        moderate_risk_count = sum(
            1
            for forecast in forecasts
            if forecast["risk_status"] == "Moderate Risk"
        )

        low_risk_count = sum(
            1
            for forecast in forecasts
            if forecast["risk_status"] == "Low Risk"
        )

        result = {
            "success": True,
            "algorithm": "XGBoost Regressor",
            "branch_id": branch_id,
            "forecast_days": forecast_days,
            "total_items": len(forecasts),

            "risk_summary": {
                "high_risk": high_risk_count,
                "moderate_risk": moderate_risk_count,
                "low_risk": low_risk_count,
            },

            "evaluation_summary": evaluation_summary,

            # Retains the key expected by the existing PHP page
            "predictions": forecasts,
        }

        print(json.dumps(
            result,
            indent=2,
            allow_nan=False
        ))

    except mysql.connector.Error as database_error:
        print(json.dumps({
            "success": False,
            "error": (
                "Database error: "
                + str(database_error)
            ),
        }))

        sys.exit(1)

    except Exception as error:
        print(json.dumps({
            "success": False,
            "error": str(error),
        }))

        sys.exit(1)


if __name__ == "__main__":
    main()