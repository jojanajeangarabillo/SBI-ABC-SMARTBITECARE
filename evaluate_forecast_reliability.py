#!/usr/bin/env python3
"""Historical rolling evaluation. Never imports data or publishes operational forecasts.

Run from the project root: py evaluate_forecast_reliability.py --branch SBI-002
Default computes a read-only preview. Add --save to persist evaluation evidence.
The existing forecasting.py and its installed dependencies must be present.
"""
import argparse
import hashlib
import json
import math
import re
import sys
from pathlib import Path

import numpy as np
import pandas as pd
import forecasting as model

VERSION = 'sbc-rolling-v2'
HORIZONS = (7, 14, 30)
MIN_TRAIN_DATES = 30
METHODS = ('xgb', 'last', 'mean')


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False)


def text_date(value):
    return pd.Timestamp(value).strftime('%Y-%m-%d')


def read_snapshot(branch):
    conn = model.get_db_connection()
    cursor = conn.cursor(dictionary=True)
    try:
        # Explicit SQL also works with MariaDB's 5.5.5 compatibility version
        # prefix, which mysql-connector's readonly feature check misreads.
        cursor.execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
        cursor.execute('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')
        cursor.execute("SELECT branch_id FROM branches WHERE branch_id=%s AND status='Active'", (branch,))
        if cursor.fetchone() is None:
            raise ValueError('Choose an existing active branch.')
        # Exclude the current day: it may still contain an unfinished closing.
        cursor.execute('SELECT DATE_SUB(CURDATE(), INTERVAL 1 DAY) AS cutoff')
        cutoff = cursor.fetchone()['cutoff']
        cursor.execute("""SELECT i.item_id,i.item_name,i.minimum_stock,
            COALESCE(NULLIF(TRIM(i.base_unit_label),''),u.unit_name,'unit') AS base_unit
            FROM inventory_items i LEFT JOIN units u ON u.unit_id=i.unit_id
            WHERE i.is_forecastable=1 ORDER BY i.item_id""")
        catalog = cursor.fetchall()
        cursor.execute("""SELECT t.training_id,t.branch_id,t.item_id,t.record_date,t.patient_count,
            t.animal_bite_cases,t.quantity_used,t.beginning_stock,t.stock_received,t.ending_stock,
            i.item_name,i.minimum_stock,t.source_type,t.base_unit_label_snapshot
            FROM training_dataset t JOIN inventory_items i ON i.item_id=t.item_id
            WHERE t.branch_id=%s AND i.is_forecastable=1 AND t.record_date<=%s
            ORDER BY t.item_id,t.record_date,t.training_id""", (branch, cutoff))
        records = cursor.fetchall()
        conn.rollback()
        return pd.DataFrame(records), catalog, pd.Timestamp(cutoff)
    finally:
        cursor.close()
        conn.close()


def validate_history(raw, catalog):
    if raw.empty:
        raise ValueError('No completed historical records are available for this branch.')
    raw = raw.copy()
    raw['record_date'] = pd.to_datetime(raw['record_date'], errors='raise').dt.normalize()
    if raw.duplicated(['branch_id', 'item_id', 'record_date']).any():
        raise ValueError('Duplicate item/date observations must be corrected before evaluation.')
    for field in ('patient_count', 'quantity_used'):
        values = pd.to_numeric(raw[field], errors='coerce')
        if not np.isfinite(values).all() or (values < 0).any():
            raise ValueError(f'Invalid {field} values must be corrected before evaluation.')
    units = {int(i['item_id']): str(i['base_unit']).strip() for i in catalog}
    mismatched = set()
    for row in raw.to_dict('records'):
        old = str(row.get('base_unit_label_snapshot') or '').strip()
        if old and old.casefold() != units[int(row['item_id'])].casefold():
            mismatched.add(int(row['item_id']))
    if mismatched:
        raise ValueError('Historical unit snapshots differ from current base units for item IDs: '
                         + ', '.join(map(str, sorted(mismatched))) + '. Verify quantities and units; do not guess a conversion.')
    return raw


def evaluate(raw, catalog, as_of, windows=5, model_factory=None, progress=None):
    """Expanding training; disjoint 30-day test blocks, shared by every method.

    Each horizon uses the same item's same dates for all three methods. Smaller
    horizons within a block overlap each other, but blocks at a given horizon
    do not overlap. Missing calendar dates are never inferred as zero demand.
    """
    if not 2 <= windows <= 12:
        raise ValueError('Choose between 2 and 12 test windows.')
    raw = validate_history(raw, catalog)
    raw = raw[raw.record_date <= pd.Timestamp(as_of).normalize()].copy()
    if raw.empty:
        raise ValueError('No records fall on or before the evaluation cutoff.')
    history = model.prepare_training_data(raw)
    end = history.record_date.max()
    origins = sorted(end - pd.Timedelta(days=30 * k) for k in range(1, windows + 1))
    items = {int(i['item_id']): i for i in catalog}
    source_fields = ['branch_id', 'item_id', 'record_date', 'patient_count', 'animal_bite_cases', 'quantity_used',
                     'item_name', 'source_type', 'base_unit_label_snapshot']
    for field in source_fields:
        if field not in raw.columns:
            raw[field] = None
    snapshot = raw.sort_values(['item_id', 'record_date'])[source_fields].to_json(
        orient='records', date_format='iso', double_precision=15)
    catalog_snapshot = [{'item_id': int(i['item_id']), 'item_name': str(i['item_name']).strip(),
                         'base_unit': str(i['base_unit']).strip()} for i in catalog]
    source_hash = hashlib.sha256((snapshot + canonical(catalog_snapshot)).encode()).hexdigest()
    paths = [Path(model.__file__).resolve(), Path(__file__).resolve(),
             Path(model.__file__).resolve().parent / 'sources' / 'forecast_status_rules.json']
    code_hash = hashlib.sha256(b'\n'.join(p.read_bytes() for p in paths)).hexdigest()
    factory = model_factory or model.create_xgboost_model
    params = factory().get_params()
    params = {k: ('nan' if isinstance(v, float) and not math.isfinite(v) else v)
              for k, v in params.items() if v is None or isinstance(v, (str, int, float, bool))}
    details = dict(features=model.FEATURE_COLUMNS, parameters=params, requested_windows=windows,
                   origin_spacing_days=30, min_training_model_dates=MIN_TRAIN_DATES,
                   model=('Production forecast: direct 7/14/30-day XGBoost totals, or the recent 28-day mean '
                          'when XGBoost did not beat it on an inner holdout; sanity floor 50% of the recent mean.'),
                   baselines={'last': 'Last observed usage, fixed throughout the window',
                              'mean': 'Mean of the seven latest observed usage records, fixed throughout the window'},
                   sources=raw.source_type.fillna('unknown').value_counts().to_dict(),
                   missing_unit_snapshots=int(raw.base_unit_label_snapshot.fillna('').str.strip().eq('').sum()),
                   data_note='Source labels document origin; they do not certify authenticity.',
                   window_note='Expanding training, disjoint test blocks per horizon; horizons within each block overlap.',
                   scoring='Daily rounded 4-decimal forecasts; MAE, RMSE, SMAPE; totals sum those same daily forecasts.',
                   production_methods={})
    frame = model.build_feature_frame(history)
    rows = []
    for fold, origin in enumerate(origins, 1):
        if progress:
            progress(f'Window {fold}/{windows}: training through {text_date(origin)}')
        # Train from this prefix only; features at the origin use no later data.
        prefix_history = history[history.record_date <= origin]
        ready = prefix_history.record_date.nunique() >= MIN_TRAIN_DATES
        forecaster = model.fit_forecaster(prefix_history, model_factory) if ready else None
        for item_id in sorted(history.item_id.astype(int).unique()):
            item_id = int(item_id)
            item = items[item_id]
            item_history = history[history.item_id == item_id]
            prefix = item_history[item_history.record_date <= origin]
            paths_by_horizon = {}
            why = None
            if not ready:
                why = 'Fewer than 30 unique model training dates before this starting date.'
            elif len(prefix) < model.MINIMUM_HISTORY_RECORDS:
                why = 'Not enough pre-window history for this item.'
            else:
                for horizon in HORIZONS:
                    path = model.forecast_item_path(forecaster, frame, prefix_history, item_id, origin, horizon)
                    if path is None:
                        why = 'No recent usage was available on this starting date.'
                        break
                    paths_by_horizon[horizon] = path
                    details['production_methods'].setdefault(text_date(origin), {}).setdefault(
                        str(item_id), {})[str(horizon)] = path['method']
            recent = prefix.quantity_used.astype(float).tail(7).to_numpy()
            last = round(float(recent[-1]), 4) if len(recent) else None
            mean = round(float(np.mean(recent)), 4) if len(recent) else None
            for horizon in HORIZONS:
                start = origin + pd.Timedelta(days=1)
                finish = origin + pd.Timedelta(days=horizon)
                actual = item_history[(item_history.record_date >= start) & (item_history.record_date <= finish)]
                dates = pd.date_range(start, finish, freq='D')
                row = dict(fold_number=fold, item_id=item_id, item_name=str(item['item_name']).strip(),
                           base_unit=str(item['base_unit']).strip(), horizon_days=horizon, origin_date=text_date(origin),
                           training_records=len(prefix),
                           training_start=text_date(prefix.record_date.min()) if len(prefix) else None,
                           training_end=text_date(prefix.record_date.max()) if len(prefix) else None,
                           testing_start=text_date(start), testing_end=text_date(finish),
                           observed_records=len(actual), status='Unavailable', reason=why,
                           actual_total=None, daily_results=[])
                for name in METHODS:
                    for field in ('mae', 'rmse', 'smape_percent', 'forecast_total'):
                        row[f'{name}_{field}'] = None
                if why is None and (len(actual) != horizon or set(actual.record_date) != set(dates)):
                    row['reason'] = f'Only {len(actual)} of {horizon} actual daily records exist. Missing days are not zero.'
                elif why is None:
                    path = paths_by_horizon[horizon]
                    predicted = dict(zip(path['dates'], np.round(path['daily'], 4)))
                    if not all(d in predicted for d in dates):
                        raise ValueError('Production forecasting returned an incomplete daily path.')
                    by_date = actual.set_index('record_date').quantity_used.astype(float)
                    daily = [dict(date=text_date(d), actual=float(by_date.loc[d]), xgb=float(predicted[d]),
                                  last=last, mean=mean) for d in dates]
                    for name in METHODS:
                        scores = model.forecast_error_scores([d['actual'] for d in daily], [d[name] for d in daily])
                        for field in ('mae', 'rmse', 'smape_percent', 'forecast_total'):
                            row[f'{name}_{field}'] = scores[field]
                    row.update(status='Available', reason=None, actual_total=scores['actual_total'], daily_results=daily)
                rows.append(row)
    meta = dict(version=VERSION, source_hash=source_hash, code_hash=code_hash,
                model_version=str(model.xgboost.__version__), as_of_date=text_date(as_of),
                data_start=text_date(history.record_date.min()), data_end=text_date(end),
                requested_windows=windows, details=details)
    return {'metadata': meta, 'rows': rows}


def assert_schema(conn):
    cursor = conn.cursor(buffered=True)
    try:
        for table, fields, unique in [
            ('forecast_reliability_runs', ['reliability_id', 'run_key', 'branch_id', 'generated_at', 'evaluation_version',
             'source_hash', 'code_hash', 'model_version', 'as_of_date', 'data_start', 'data_end', 'requested_windows', 'details'], ['run_key']),
            ('forecast_reliability_windows', ['reliability_id', 'fold_number', 'item_id', 'item_name', 'base_unit',
             'horizon_days', 'origin_date', 'training_records', 'training_start', 'training_end', 'testing_start', 'testing_end',
             'observed_records', 'status', 'reason', 'actual_total', 'daily_results'] +
             [f'{m}_{f}' for m in METHODS for f in ('mae', 'rmse', 'smape_percent', 'forecast_total')],
             ['reliability_id', 'fold_number', 'item_id', 'horizon_days'])]:
            cursor.execute('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', (table,))
            engine = cursor.fetchone()
            if not engine or engine[0] != 'InnoDB':
                raise ValueError('Preview and apply sources/migrate_forecast_reliability.php first.')
            cursor.execute('SELECT ' + ','.join(fields) + ' FROM ' + table + ' LIMIT 0')
            # Refuse incompatible precision/nullability instead of relying on
            # a server's truncation or SQL-mode behavior during evidence saves.
            cursor.execute('SHOW COLUMNS FROM ' + table)
            columns = {r[0]: r for r in cursor.fetchall()}
            expected = {}
            if table.endswith('_runs'):
                expected = dict(reliability_id=('bigint unsigned', False), run_key=('char(64)', False),
                    branch_id=('varchar(10)', False), generated_at=('datetime', False),
                    evaluation_version=('varchar(40)', False), source_hash=('char(64)', False),
                    code_hash=('char(64)', False), model_version=('varchar(80)', False),
                    as_of_date=('date', False), data_start=('date', False), data_end=('date', False),
                    requested_windows=('int', False), details=('longtext', False))
                id_field = 'reliability_id'
            else:
                expected = dict(window_id=('bigint unsigned', False), reliability_id=('bigint unsigned', False),
                    item_name=('varchar(255)', False), base_unit=('varchar(100)', False),
                    status=('varchar(30)', False), reason=('varchar(1000)', True),
                    actual_total=('decimal(20,4)', True), daily_results=('longtext', False))
                for f in ('fold_number', 'item_id', 'horizon_days', 'training_records', 'observed_records'):
                    expected[f] = ('int', False)
                for f in ('origin_date', 'training_start', 'training_end', 'testing_start', 'testing_end'):
                    expected[f] = ('date', f.startswith('training_'))
                for name in METHODS:
                    for f in ('mae', 'rmse', 'smape_percent', 'forecast_total'):
                        expected[f'{name}_{f}'] = ('decimal(9,4)' if f == 'smape_percent' else
                                                  ('decimal(20,8)' if f in ('mae', 'rmse') else 'decimal(20,4)'), True)
                id_field = 'window_id'
            for field, (kind, nullable) in expected.items():
                actual = columns.get(field)
                normalized = re.sub(r'\b(bigint|int)\(\d+\)', r'\1', actual[1].lower()) if actual else ''
                if not actual or normalized != kind or actual[2] != ('YES' if nullable else 'NO'):
                    raise ValueError(f'{table}.{field} is incompatible; review the migration preview.')
            if 'auto_increment' not in columns[id_field][5]:
                raise ValueError(f'{table} requires its auto-increment ID; review the migration preview.')
            cursor.execute('SHOW INDEX FROM ' + table)
            indexes = {}
            for r in cursor.fetchall():
                if r[1] == 0:
                    indexes.setdefault(r[2], {})[r[3]] = r[4]
            if unique not in [[f[k] for k in sorted(f)] for f in indexes.values()]:
                raise ValueError('Reliability table unique keys are incompatible; review the migration preview.')
    finally:
        cursor.close()


def save_result(branch, result):
    meta = result['metadata']
    run_key = hashlib.sha256(canonical([branch, result]).encode()).hexdigest()
    conn = model.get_db_connection()
    cursor = conn.cursor()
    try:
        assert_schema(conn)
        conn.commit()
        conn.start_transaction()
        cursor.execute('''INSERT INTO forecast_reliability_runs
            (run_key,branch_id,evaluation_version,source_hash,code_hash,model_version,as_of_date,
             data_start,data_end,requested_windows,details) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE reliability_id=LAST_INSERT_ID(reliability_id)''',
            (run_key, branch, meta['version'], meta['source_hash'], meta['code_hash'], meta['model_version'],
             meta['as_of_date'], meta['data_start'], meta['data_end'], meta['requested_windows'], canonical(meta['details'])))
        rid = cursor.lastrowid
        # A retry must preserve original rows and timestamps, including concurrent retries.
        cursor.execute('SELECT COUNT(*) FROM forecast_reliability_windows WHERE reliability_id=%s', (rid,))
        count = cursor.fetchone()[0]
        if count:
            if count != len(result['rows']):
                raise ValueError('The existing evaluation has an unexpected row count; review it before retrying.')
        else:
            fields = ['fold_number', 'item_id', 'item_name', 'base_unit', 'horizon_days', 'origin_date',
                      'training_records', 'training_start', 'training_end', 'testing_start', 'testing_end',
                      'observed_records', 'status', 'reason', 'actual_total'] + [f'{m}_{f}' for m in METHODS
                      for f in ('mae', 'rmse', 'smape_percent', 'forecast_total')] + ['daily_results']
            query = 'INSERT INTO forecast_reliability_windows (reliability_id,' + ','.join(fields) + ') VALUES (' + ','.join(['%s'] * (len(fields) + 1)) + ')'
            for row in result['rows']:
                values = [canonical(row[f]) if f == 'daily_results' else row[f] for f in fields]
                cursor.execute(query, (rid, *values))
        conn.commit()
        return rid
    except Exception:
        conn.rollback()
        raise
    finally:
        cursor.close()
        conn.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--branch', required=True, help='Existing branch ID, e.g. SBI-002')
    parser.add_argument('--windows', type=int, default=5, choices=range(2, 13), metavar='2..12')
    parser.add_argument('--save', action='store_true', help='Save evidence in the reliability tables; default is read-only')
    args = parser.parse_args()
    try:
        if args.save:
            conn = model.get_db_connection()
            try:
                assert_schema(conn)
            finally:
                conn.close()
        raw, catalog, cutoff = read_snapshot(args.branch)
        result = evaluate(raw, catalog, cutoff, args.windows, progress=lambda s: print(s, file=sys.stderr, flush=True))
        available = sum(r['status'] == 'Available' for r in result['rows'])
        print(f'Computed {len(result["rows"])} item/window/horizon outcomes; {available} complete paired comparisons.')
        if args.save:
            rid = save_result(args.branch, result)
            print(f'Saved reliability evaluation #{rid}. Open ForecastAccuracy.php?view=rolling')
        else:
            print('Read-only preview complete. Add --save to retain scores and daily evidence.')
        print('Stock, training data, operational forecasts and prior accuracy results were not changed.')
    except Exception as exc:
        print('Evaluation stopped: ' + str(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
