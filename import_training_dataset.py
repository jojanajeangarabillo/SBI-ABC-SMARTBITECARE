#!/usr/bin/env python3
"""Validate and insert the forecasting CSV into training_dataset.

This is a command-line importer, not a web endpoint. It maps branch_name and
item_name from the CSV to their database IDs, then performs an idempotent
upsert using (branch_id, item_id, record_date).

Requires the priority-1 forecasting synchronization migration. All five CSV
stock quantity columns must use the selected unit convention. For the supplied
CSV, use display units: Speeda is in vials and its database base unit is site.
Non-forecastable items and operational daily-closing rows are skipped/reported.
Live inventory stock is never changed by this importer.

Usage:
    python import_training_dataset.py database/data/inventory_clean_forecasting_revised.csv --csv-units display --dry-run
    python import_training_dataset.py database/data/inventory_clean_forecasting_revised.csv --csv-units display
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import re
from collections import Counter
from datetime import datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any

import mysql.connector


def database_setting(name: str, default: str) -> str:
    """Share forecasting settings while retaining the importer's legacy names."""
    return os.getenv(f"SMARTBITECARE_DB_{name}", os.getenv(f"SBC_DB_{name}", default))


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
    if not value.is_finite() or value < 0:
        raise ValueError(f"Line {line_number}: {column} must be finite and nonnegative.")
    return value


def integer_value(row: dict[str, str], column: str, line_number: int) -> int:
    value = decimal_value(row, column, line_number)
    if value != value.to_integral_value() or value > 4294967295:
        raise ValueError(f"Line {line_number}: {column} must be a valid unsigned whole number.")
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
    if not needle:
        raise ValueError(f"Line {line_number}: {kind} name cannot be blank.")
    exact = [identifier for identifier, label in choices if normalize(label) == needle]
    if len(exact) == 1:
        return exact[0]
    if len(exact) > 1:
        raise ValueError(f"Line {line_number}: {kind} {name!r} matches more than one master record.")

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
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("csv_path", type=Path)
    parser.add_argument("--csv-units", choices=("base", "display"), required=True,
                        help="Unit convention of all five stock quantity columns in the CSV.")
    parser.add_argument("--dry-run", action="store_true", help="Validate without writing records.")
    args = parser.parse_args()
    csv_path = args.csv_path.expanduser().resolve()
    connection = None
    try:
        if not csv_path.is_file():
            raise ValueError(f"CSV not found: {csv_path}")
        connection = mysql.connector.connect(
            host=database_setting("HOST", "localhost"),
            port=int(database_setting("PORT", "3306")),
            user=database_setting("USER", "root"),
            password=database_setting("PASSWORD", ""),
            database=database_setting("NAME", "smartbitecare"),
            autocommit=False,
        )
        cursor = connection.cursor()
        cursor.execute("SELECT branch_id, branch_name FROM branches WHERE status = 'Active'")
        branches = [(row[0], row[1]) for row in cursor.fetchall()]
        cursor.execute(
            "SELECT item_id, item_name, base_unit_label, display_unit_label, conversion_to_base "
            "FROM inventory_items WHERE is_forecastable = 1")
        metadata = {int(row[0]): row for row in cursor.fetchall()}
        items = [(item_id, row[1]) for item_id, row in metadata.items()]
        cursor.execute("SELECT item_id, item_name FROM inventory_items "
                       "WHERE is_forecastable = 0 OR is_forecastable IS NULL")
        items.extend((int(row[0]), row[1]) for row in cursor.fetchall())
        # Check the required priority-1 migration before any writes.
        cursor.execute("SELECT source_type, base_unit_label_snapshot FROM training_dataset LIMIT 0")
        cursor.fetchall()
        cursor.execute("SELECT revision FROM forecast_training_branch_state LIMIT 0")
        cursor.fetchall()

        upsert = """
            INSERT INTO training_dataset (
                branch_id, item_id, record_date, patient_count,
                beginning_stock, quantity_used, stock_received, ending_stock,
                animal_bite_cases, vaccinations_administered,
                minimum_stock_level, low_stock_target, source_type, base_unit_label_snapshot
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s,
                      'historical_import', %s)
            ON DUPLICATE KEY UPDATE
                patient_count = VALUES(patient_count),
                beginning_stock = VALUES(beginning_stock),
                quantity_used = VALUES(quantity_used),
                stock_received = VALUES(stock_received),
                ending_stock = VALUES(ending_stock),
                animal_bite_cases = VALUES(animal_bite_cases),
                vaccinations_administered = VALUES(vaccinations_administered),
                minimum_stock_level = VALUES(minimum_stock_level),
                low_stock_target = VALUES(low_stock_target),
                source_type = 'historical_import',
                source_closing_id = NULL,
                base_unit_label_snapshot = VALUES(base_unit_label_snapshot)
        """

        records = []
        keys = set()
        excluded = Counter()
        conversions = {}
        rows_read = 0
        with csv_path.open("r", encoding="utf-8-sig", newline="") as handle:
            reader = csv.DictReader(handle)
            missing = REQUIRED_COLUMNS.difference(reader.fieldnames or [])
            if missing:
                raise ValueError("CSV is missing columns: " + ", ".join(sorted(missing)))

            for line_number, row in enumerate(reader, start=2):
                rows_read += 1
                branch_id = find_id(row["branch_name"] or "", branches, "branch", line_number)
                item_id = find_id(row["item_name"] or "", items, "item", line_number)
                record_date = parse_date(row["record_date"] or "", line_number)
                key = (branch_id, item_id, record_date)
                if key in keys:
                    raise ValueError(f"Line {line_number}: duplicate branch/item/date in CSV.")
                keys.add(key)
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

                if item_id not in metadata:
                    excluded[row["item_name"]] += 1
                    continue
                _, item_name, base_unit, display_unit, factor = metadata[item_id]
                if not base_unit:
                    raise ValueError(f"Item {item_name!r}: configure its base unit first.")
                multiplier = Decimal("1")
                if args.csv_units == "display":
                    if not display_unit or factor is None:
                        raise ValueError(f"Item {item_name!r}: configure its display unit and conversion first.")
                    multiplier = Decimal(str(factor))
                    if not multiplier.is_finite() or multiplier <= 0:
                        raise ValueError(f"Item {item_name!r}: conversion_to_base must be positive.")
                values = [value * multiplier for value in (beginning, used, received, ending, minimum)]
                for column, value in zip(("beginning_stock", "quantity_used", "stock_received",
                                          "ending_stock", "minimum_stock_level"), values):
                    if value > Decimal("99999999.9999") or value != value.quantize(Decimal("0.0001")):
                        raise ValueError(f"Line {line_number}: converted {column} exceeds DECIMAL(12,4).")
                beginning, used, received, ending, minimum = values
                if multiplier != 1:
                    conversions[item_name.strip()] = {
                        "from": display_unit, "to": base_unit, "multiplier": str(multiplier),
                    }
                records.append((
                    branch_id, item_id, record_date, patient_count,
                    beginning, used, received, ending,
                    bite_cases, vaccinations, minimum, int(ending <= minimum), base_unit,
                ))

        branch_ids = sorted({row[0] for row in records})
        # Share daily-closing/forecast branch locks; always take them in sorted order.
        if not args.dry_run:
            for branch_id in branch_ids:
                cursor.execute("INSERT IGNORE INTO forecast_training_branch_state (branch_id) VALUES (%s)",
                               (branch_id,))
                cursor.execute("SELECT revision FROM forecast_training_branch_state "
                               "WHERE branch_id = %s FOR UPDATE", (branch_id,))
                cursor.fetchall()
        protected = set()
        for branch_id in branch_ids:
            cursor.execute(
                "SELECT branch_id, item_id, record_date FROM training_dataset "
                "WHERE branch_id = %s AND source_type = 'daily_closing'"
                + (" FOR UPDATE" if not args.dry_run else ""), (branch_id,))
            protected.update((row[0], int(row[1]), row[2].isoformat()) for row in cursor.fetchall())
        imported = inserted = updated = unchanged = skipped_closings = 0
        changed_branches = set()
        for row in records:
            if row[:3] in protected:
                skipped_closings += 1
                continue
            imported += 1
            if args.dry_run:
                continue
            cursor.execute(upsert, row)
            if cursor.rowcount == 1:
                inserted += 1
            elif cursor.rowcount == 2:
                updated += 1
            else:
                unchanged += 1
            if cursor.rowcount:
                changed_branches.add(row[0])
        for branch_id in sorted(changed_branches):
            cursor.execute("UPDATE forecast_results SET is_stale = 1, stale_at = NOW(), "
                           "stale_reason = 'Historical forecasting CSV imported.' WHERE branch_id = %s",
                           (branch_id,))
            cursor.execute("UPDATE forecast_training_branch_state SET revision = revision + 1, "
                           "updated_at = NOW() WHERE branch_id = %s", (branch_id,))
        if args.dry_run:
            connection.rollback()
        else:
            connection.commit()
        print(json.dumps({
            "success": True, "dry_run": args.dry_run,
            "database": database_setting("NAME", "smartbitecare"),
            "rows_read": rows_read, "rows_processed": imported,
            "rows_inserted": inserted, "rows_updated": updated, "rows_unchanged": unchanged,
            "rows_skipped_non_forecastable": sum(excluded.values()),
            "excluded_items": dict(sorted(excluded.items())),
            "rows_skipped_daily_closing": skipped_closings,
            "file": str(csv_path), "csv_units": args.csv_units,
            "unit_conversions": conversions,
            "forecast_branches_invalidated": sorted(changed_branches),
        }))
    except Exception as exc:
        if connection is not None and connection.is_connected():
            connection.rollback()
        print(json.dumps({"success": False, "error": str(exc)}))
        raise SystemExit(1)
    finally:
        if connection is not None and connection.is_connected():
            connection.close()


if __name__ == "__main__":
    main()
