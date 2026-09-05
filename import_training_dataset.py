#!/usr/bin/env python3
"""Validate and insert the forecasting CSV into training_dataset.

This is a command-line importer, not a web endpoint. It maps branch_name and
item_name from the CSV to their database IDs, then performs an idempotent
upsert using (branch_id, item_id, record_date).

Usage:
    python3 import_training_dataset.py database/data/inventory_clean_forecasting_revised.csv
"""

from __future__ import annotations

import csv
import json
import os
import re
import sys
from datetime import datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any

import mysql.connector


DB_CONFIG = {
    "host": os.getenv("SBC_DB_HOST", "localhost"),
    "port": int(os.getenv("SBC_DB_PORT", "3306")),
    "user": os.getenv("SBC_DB_USER", "root"),
    "password": os.getenv("SBC_DB_PASSWORD", ""),
    "database": os.getenv("SBC_DB_NAME", "smartbitecare"),
}

REQUIRED_COLUMNS = {
    "record_date",
    "branch_name",
    "total_patient_tally",
    "item_name",
    "beginning_stock",
    "quantity_used",
    "stock_received",
    "ending_stock",
    "animal_bite_cases",
    "vaccinations_administered",
    "minimum_stock_level",
}


def normalize(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.lower().replace("branch", ""))


def decimal_value(row: dict[str, str], column: str, line_number: int) -> Decimal:
    try:
        value = Decimal((row.get(column) or "").strip())
    except InvalidOperation as exc:
        raise ValueError(f"Line {line_number}: {column} must be numeric.") from exc
    if value < 0:
        raise ValueError(f"Line {line_number}: {column} cannot be negative.")
    return value


def integer_value(row: dict[str, str], column: str, line_number: int) -> int:
    value = decimal_value(row, column, line_number)
    if value != value.to_integral_value():
        raise ValueError(f"Line {line_number}: {column} must be a whole number.")
    return int(value)


def parse_date(value: str, line_number: int) -> str:
    value = value.strip()
    for pattern in ("%d/%m/%Y", "%Y-%m-%d", "%m/%d/%Y"):
        try:
            return datetime.strptime(value, pattern).date().isoformat()
        except ValueError:
            continue
    raise ValueError(f"Line {line_number}: unsupported record_date {value!r}.")


def find_id(name: str, choices: list[tuple[Any, str]], kind: str, line_number: int):
    needle = normalize(name)
    exact = [identifier for identifier, label in choices if normalize(label) == needle]
    if len(exact) == 1:
        return exact[0]

    prefix = [
        identifier
        for identifier, label in choices
        if normalize(label).startswith(needle) or needle.startswith(normalize(label))
    ]
    if len(prefix) == 1:
        return prefix[0]
    if not exact and not prefix:
        raise ValueError(
            f"Line {line_number}: {kind} {name!r} does not exist in the database. "
            f"Create the matching master record first."
        )
    raise ValueError(f"Line {line_number}: {kind} {name!r} matches more than one master record.")


def main() -> None:
    if len(sys.argv) != 2:
        print(json.dumps({"success": False, "error": "Provide exactly one CSV path."}))
        raise SystemExit(1)

    csv_path = Path(sys.argv[1]).expanduser().resolve()
    if not csv_path.is_file():
        print(json.dumps({"success": False, "error": f"CSV not found: {csv_path}"}))
        raise SystemExit(1)

    connection = None
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor()
        cursor.execute("SELECT branch_id, branch_name FROM branches WHERE status = 'Active'")
        branches = [(row[0], row[1]) for row in cursor.fetchall()]
        cursor.execute("SELECT item_id, item_name FROM inventory_items WHERE is_predictable = 1")
        items = [(int(row[0]), row[1]) for row in cursor.fetchall()]

        upsert = """
            INSERT INTO training_dataset (
                branch_id, item_id, record_date, patient_count,
                beginning_stock, quantity_used, stock_received, ending_stock,
                animal_bite_cases, vaccinations_administered,
                minimum_stock_level, low_stock_target
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                patient_count = VALUES(patient_count),
                beginning_stock = VALUES(beginning_stock),
                quantity_used = VALUES(quantity_used),
                stock_received = VALUES(stock_received),
                ending_stock = VALUES(ending_stock),
                animal_bite_cases = VALUES(animal_bite_cases),
                vaccinations_administered = VALUES(vaccinations_administered),
                minimum_stock_level = VALUES(minimum_stock_level),
                low_stock_target = VALUES(low_stock_target)
        """

        inserted = 0
        with csv_path.open("r", encoding="utf-8-sig", newline="") as handle:
            reader = csv.DictReader(handle)
            missing = REQUIRED_COLUMNS.difference(reader.fieldnames or [])
            if missing:
                raise ValueError("CSV is missing columns: " + ", ".join(sorted(missing)))

            for line_number, row in enumerate(reader, start=2):
                branch_id = find_id(row["branch_name"], branches, "branch", line_number)
                item_id = find_id(row["item_name"], items, "item", line_number)
                record_date = parse_date(row["record_date"], line_number)
                patient_count = integer_value(row, "total_patient_tally", line_number)
                beginning = decimal_value(row, "beginning_stock", line_number)
                used = decimal_value(row, "quantity_used", line_number)
                received = decimal_value(row, "stock_received", line_number)
                ending = decimal_value(row, "ending_stock", line_number)
                bite_cases = integer_value(row, "animal_bite_cases", line_number)
                vaccinations = integer_value(row, "vaccinations_administered", line_number)
                minimum = decimal_value(row, "minimum_stock_level", line_number)

                expected_ending = beginning + received - used
                if abs(expected_ending - ending) > Decimal("0.11"):
                    raise ValueError(
                        f"Line {line_number}: ending_stock does not equal "
                        "beginning_stock + stock_received - quantity_used."
                    )

                cursor.execute(upsert, (
                    branch_id, item_id, record_date, patient_count,
                    beginning, used, received, ending,
                    bite_cases, vaccinations, minimum, int(ending <= minimum),
                ))
                inserted += 1

        connection.commit()
        print(json.dumps({"success": True, "rows_processed": inserted, "file": str(csv_path)}))
    except Exception as exc:
        if connection is not None:
            connection.rollback()
        print(json.dumps({"success": False, "error": str(exc)}))
        raise SystemExit(1)
    finally:
        if connection is not None and connection.is_connected():
            connection.close()


if __name__ == "__main__":
    main()
