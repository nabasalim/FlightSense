#!/usr/bin/env python3
"""FlightSense: train reproducible flight-delay classifiers and predict a new flight.

Only information available at the selected prediction stage is used. Dataset format: Hrishit Patil's
Flight Data 2024 (BTS, Kaggle), sample or full CSV. A model is NEVER trained on an
individual flight entered for prediction.
"""
from __future__ import annotations
import argparse
import datetime as dt
import json
import os
import sys
import tempfile
import uuid
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import RandomForestClassifier
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    accuracy_score, balanced_accuracy_score, brier_score_loss, cohen_kappa_score,
    confusion_matrix, f1_score, matthews_corrcoef, precision_score,
    recall_score, roc_auc_score, roc_curve,
)
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler
from sklearn.tree import DecisionTreeClassifier

ROOT = Path(__file__).resolve().parents[1]
MODEL_PATH = ROOT / 'storage' / 'models.joblib'
REPORT_PATH = ROOT / 'data' / 'validation_results.json'
DATASET_PATH = ROOT / 'data' / 'flight_data_2024_sample.csv'
NUMERIC = ['month', 'day_of_month', 'day_of_week', 'scheduled_departure_minutes', 'crs_elapsed_time', 'distance']
CATEGORICAL = ['op_unique_carrier', 'origin', 'dest']
INPUTS = NUMERIC + CATEGORICAL
# True outcome variables are only used as the target. dep_delay is not available
# until the aircraft departs, so it is NEVER used in predeparture predictions.
REQUIRED_CSV = set(INPUTS) - {'scheduled_departure_minutes'}
REQUIRED_CSV |= {'crs_dep_time', 'arr_delay', 'cancelled', 'diverted'}
OPTIONAL_CSV = {'dep_delay'}
ENGINEERED_NUMERIC = NUMERIC + ['scheduled_arrival_minutes', 'is_weekend']
ENGINEERED_CATEGORICAL = CATEGORICAL + ['route', 'departure_slot']
MODES = {'predeparture': 'Before departure', 'after_departure': 'After departure (actual departure delay known)'}
NAMES = {'decision_tree': 'Decision Tree', 'random_forest': 'Random Forest', 'logistic_regression': 'Logistic Regression'}
THRESHOLD = 0.5
TARGET_DESCRIPTION = 'Arrival delay >= 15 minutes; cancelled, diverted and missing outcomes excluded'


def atomic_write(path: Path, writer):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_path = tempfile.mkstemp(prefix='.flightsense-', suffix='.tmp', dir=str(path.parent))
    os.close(fd)
    try:
        writer(Path(tmp_path))
        os.replace(tmp_path, path)
    finally:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)


def safe_float(v):
    v = float(v)
    return round(v, 6) if np.isfinite(v) else None


def load_sample(path: Path, max_rows: int):
    if not path.is_file():
        raise ValueError('CSV dataset was not found: ' + str(path))
    # Chunked uniform random-key reservoir sampling bounds memory on a ~7M-row CSV.
    rng = np.random.default_rng(2024)
    reservoir = None
    keys = np.empty(0, dtype=np.float64)
    raw_rows = eligible_rows = 0
    reader = pd.read_csv(path, usecols=lambda c: c.strip().lower() in (REQUIRED_CSV | OPTIONAL_CSV),
                         chunksize=50000, low_memory=False, on_bad_lines='error')
    for chunk in reader:
        chunk.columns = [c.strip().lower() for c in chunk.columns]
        missing = REQUIRED_CSV.difference(chunk.columns)
        if missing:
            raise ValueError('Dataset missing columns: ' + ', '.join(sorted(missing)) + '. Use Kaggle Flight Data 2024 CSV.')
        raw_rows += len(chunk)
        for c in NUMERIC:
            if c != 'scheduled_departure_minutes':
                chunk[c] = pd.to_numeric(chunk[c], errors='coerce')
        if 'dep_delay' not in chunk.columns:
            chunk['dep_delay'] = np.nan
        for c in ('arr_delay', 'cancelled', 'diverted', 'crs_dep_time', 'dep_delay'):
            chunk[c] = pd.to_numeric(chunk[c], errors='coerce')
        crs = chunk['crs_dep_time']
        # Midnight may be written as 2400 in BTS data.
        hours = np.floor(crs / 100)
        minutes = crs % 100
        valid_time = crs.notna() & (((hours >= 0) & (hours < 24) & (minutes >= 0) & (minutes < 60)) | (crs == 2400))
        chunk['scheduled_departure_minutes'] = np.where(valid_time, ((hours % 24) * 60 + minutes), np.nan)
        eligible = chunk.loc[chunk['arr_delay'].notna() & chunk['cancelled'].eq(0) & chunk['diverted'].eq(0)].copy()
        eligible_rows += len(eligible)
        if eligible.empty:
            continue
        for col in CATEGORICAL:
            eligible[col] = eligible[col].fillna('Unknown').astype(str).str.strip().str.upper().replace({'': 'UNKNOWN'})
        eligible = eligible[INPUTS + ['arr_delay', 'dep_delay']]
        rand = rng.random(len(eligible))
        if reservoir is None:
            reservoir, keys = eligible.reset_index(drop=True), rand
        else:
            # Ignore all keys above the running cutoff, to avoid storing the full dataset.
            threshold = np.max(keys) if len(keys) >= max_rows else 1.0
            mask = rand < threshold
            if mask.any():
                reservoir = pd.concat([reservoir, eligible.loc[mask]], ignore_index=True)
                keys = np.concatenate([keys, rand[mask]])
        if len(keys) > max_rows:
            chosen = np.argpartition(keys, max_rows - 1)[:max_rows]
            reservoir = reservoir.iloc[chosen].reset_index(drop=True)
            keys = keys[chosen]
    if reservoir is None or len(reservoir) < 100:
        raise ValueError('At least 100 eligible flight records are required for training.')
    target = (reservoir['arr_delay'] >= 15).astype('int8')
    if target.nunique() != 2 or int(target.sum()) < 5 or int((target == 0).sum()) < 5:
        raise ValueError('Dataset must contain at least 5 delayed and 5 on-time eligible flights.')
    return reservoir[INPUTS + ['dep_delay']], target, raw_rows, eligible_rows


def build_features(frame: pd.DataFrame, mode: str):
    """Only features knowable at the selected prediction time. No arrival data."""
    if mode not in MODES:
        raise ValueError('Unknown prediction mode')
    frame = frame.copy()
    frame['scheduled_arrival_minutes'] = (frame['scheduled_departure_minutes'] + frame['crs_elapsed_time']) % 1440
    frame['is_weekend'] = frame['day_of_week'].isin([6, 7]).astype(int)
    frame['route'] = frame['origin'].astype(str) + '_' + frame['dest'].astype(str)
    frame['departure_slot'] = (frame['scheduled_departure_minutes'].fillna(0) // 240).astype(int).astype(str)
    if mode == 'after_departure':
        frame['dep_delay'] = pd.to_numeric(frame['dep_delay'], errors='coerce').clip(-120, 1440)
    numeric = ENGINEERED_NUMERIC + (['dep_delay'] if mode == 'after_departure' else [])
    return frame[numeric + ENGINEERED_CATEGORICAL], numeric


def model_candidates(mode: str):
    """Unweighted models avoid the excessive false positives of class_weight=balanced.
    The same feature processing and fitted estimators are used by online prediction.
    """
    after = mode == 'after_departure'
    return {
        'decision_tree': DecisionTreeClassifier(
            max_depth=8 if after else 7, min_samples_leaf=18 if after else 35,
            random_state=42),
        'random_forest': RandomForestClassifier(
            n_estimators=150, max_depth=18 if after else 15,
            min_samples_leaf=5 if after else 9, max_features=0.8,
            n_jobs=-1, random_state=42),
        'logistic_regression': LogisticRegression(
            C=0.7 if after else 0.5, max_iter=900, random_state=42),
    }


def evaluate_mode(frame: pd.DataFrame, labels: pd.Series, mode: str):
    """Once-only untouched 20% holdout. No threshold tuning on test labels."""
    if len(frame) < 100 or labels.nunique() < 2 or min(labels.value_counts()) < 5:
        raise ValueError('Not enough labeled flights for ' + mode)
    inputs, numeric = build_features(frame, mode)
    indices = np.arange(len(inputs))
    train_idx, valid_idx = train_test_split(indices, test_size=.2, stratify=labels, random_state=42)
    x_train, x_valid = inputs.iloc[train_idx], inputs.iloc[valid_idx]
    y_train, y_valid = labels.iloc[train_idx], labels.iloc[valid_idx]
    preprocess = ColumnTransformer(transformers=[
        ('numbers', Pipeline([('impute', SimpleImputer(strategy='median')),
                              ('scale', StandardScaler())]), numeric),
        ('categories', Pipeline([('one_hot', OneHotEncoder(
            handle_unknown='ignore', min_frequency=5))]), ENGINEERED_CATEGORICAL),
    ])
    pipelines, results = {}, {}
    for slug, estimator in model_candidates(mode).items():
        pipeline = Pipeline([('preprocessing', preprocess), ('classifier', estimator)])
        pipeline.fit(x_train, y_train)
        probabilities = pipeline.predict_proba(x_valid)[:, 1]
        # The 0.50 threshold is pre-declared, NOT chosen using test labels.
        predictions = (probabilities >= THRESHOLD).astype(int)
        tn, fp, fn, tp = map(int, confusion_matrix(y_valid, predictions, labels=[0, 1]).ravel())
        fpr, tpr, _ = roc_curve(y_valid, probabilities)
        picks = np.unique(np.linspace(0, len(fpr)-1, min(120, len(fpr)), dtype=int))
        metrics = {
            'accuracy': safe_float(accuracy_score(y_valid, predictions)),
            'balanced_accuracy': safe_float(balanced_accuracy_score(y_valid, predictions)),
            'precision': safe_float(precision_score(y_valid, predictions, zero_division=0)),
            'recall': safe_float(recall_score(y_valid, predictions, zero_division=0)),
            'specificity': safe_float(tn/(tn + fp) if tn + fp else 0),
            'f1': safe_float(f1_score(y_valid, predictions, zero_division=0)),
            'roc_auc': safe_float(roc_auc_score(y_valid, probabilities)),
            'npv': safe_float(tn/(tn + fn) if tn + fn else 0),
            'fpr': safe_float(fp/(fp + tn) if fp + tn else 0),
            'fnr': safe_float(fn/(fn + tp) if fn + tp else 0),
            'mcc': safe_float(matthews_corrcoef(y_valid, predictions)),
            'cohen_kappa': safe_float(cohen_kappa_score(y_valid, predictions)),
            'brier_score': safe_float(brier_score_loss(y_valid, probabilities)),
        }
        pipelines[slug] = pipeline
        results[slug] = {'name': NAMES[slug], 'metrics': metrics,
                         'confusion': {'tn': tn, 'fp': fp, 'fn': fn, 'tp': tp},
                         'roc': [{'x': safe_float(fpr[i]), 'y': safe_float(tpr[i])} for i in picks]}
    baseline = float((y_valid == 0).mean())
    # Default selection prefers ability to detect delayed flights instead of only accuracy.
    best = max(results, key=lambda slug: (results[slug]['metrics']['f1'],
                                          results[slug]['metrics']['roc_auc']))
    metadata = {'name': MODES[mode], 'rows': len(frame), 'train_rows': len(train_idx),
                'validation_rows': len(valid_idx), 'validation_positive': int(y_valid.sum()),
                'validation_negative': int((y_valid == 0).sum()),
                'majority_baseline_accuracy': safe_float(baseline),
                'best_model': best, 'threshold': THRESHOLD, 'models': results}
    return metadata, pipelines


def train(dataset: Path, max_rows: int, model_path: Path = MODEL_PATH, report_path: Path = REPORT_PATH, label: str | None = None):
    X, y, raw_rows, eligible_rows = load_sample(dataset, max_rows)
    pre_report, pre_models = evaluate_mode(X, y, 'predeparture')
    mode_reports = {'predeparture': pre_report}
    saved_modes = {'predeparture': pre_models}
    # After-departure models are fitted only on flights whose REAL departure delay
    # was observed. This is not an input to the pre-departure models.
    observed = X['dep_delay'].notna()
    if int(observed.sum()) >= 100 and y.loc[observed].nunique() == 2 and min(y.loc[observed].value_counts()) >= 5:
        post_report, post_models = evaluate_mode(X.loc[observed].reset_index(drop=True),
                                                  y.loc[observed].reset_index(drop=True), 'after_departure')
        mode_reports['after_departure'] = post_report
        saved_modes['after_departure'] = post_models
    run_id = uuid.uuid4().hex
    trained = dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds')
    metadata = {
        'type': 'trained_models_with_holdout_validation', 'created_utc': trained,
        'run_id': run_id, 'dataset': label or dataset.name,
        'dataset_rows': int(raw_rows), 'eligible_rows': int(eligible_rows),
        'sampled_rows': int(len(X)), 'train_rows': pre_report['train_rows'],
        'validation_rows': pre_report['validation_rows'],
        'validation_positive': pre_report['validation_positive'],
        'validation_negative': pre_report['validation_negative'],
        'majority_baseline_accuracy': pre_report['majority_baseline_accuracy'],
        'target': TARGET_DESCRIPTION,
        'split': '80% training / 20% untouched stratified holdout, random_state=42, per mode',
        'sampling': 'Deterministic random-key uniform sample from eligible CSV records' if eligible_rows > len(X) else 'All eligible CSV records',
        'features': ENGINEERED_NUMERIC + ENGINEERED_CATEGORICAL,
        'threshold': THRESHOLD, 'best_model': pre_report['best_model'],
        'note': 'Before-departure accuracy can be close to the always-on-time majority baseline. After-departure uses actual departure delay, which is ONLY known after departure. Validation is on historical 2024 flights; future performance may differ.',
        'models': pre_report['models'], 'mode_reports': mode_reports,
    }
    artifact = {'run_id': run_id, 'metadata': {k: v for k, v in metadata.items() if k not in ('models','mode_reports')},
                'models': pre_models, 'modes': saved_modes,
                'known_categories': {c: sorted(X[c].dropna().astype(str).unique().tolist()) for c in CATEGORICAL}}
    # Save server-generated models only: never deserialize uploaded pickle files.
    atomic_write(model_path, lambda p: joblib.dump(artifact, p, compress=3))
    atomic_write(report_path, lambda p: p.write_text(json.dumps(metadata, indent=2, allow_nan=False) + '\n', encoding='utf-8'))
    return metadata


def predict(data: dict, model_path: Path = MODEL_PATH):
    if not model_path.is_file():
        raise ValueError('Models have not been trained. Open Upload CSV first.')
    artifact = joblib.load(model_path)
    try:
        date = dt.date.fromisoformat(str(data['flight_date']))
        hour, minute = map(int, str(data['departure_time']).split(':'))
        if not (0 <= hour < 24 and 0 <= minute < 60):
            raise ValueError('Invalid scheduled departure time')
        duration = float(data['crs_elapsed_time'])
        distance = float(data['distance'])
        if not (20 <= duration <= 1500 and 1 <= distance <= 20000):
            raise ValueError('Expected duration/distance are out of range')
        mode = str(data.get('mode') or 'predeparture')
        if mode not in MODES:
            raise ValueError('Invalid prediction mode')
        delay_value = None
        if mode == 'after_departure':
            if 'after_departure' not in artifact.get('modes', {}):
                raise ValueError('Uploaded CSV lacks observed departure delays; after-departure models are unavailable')
            delay_value = float(data['dep_delay'])
            if not np.isfinite(delay_value) or not (-120 <= delay_value <= 1440):
                raise ValueError('Actual departure delay must be between -120 and 1440 minutes')
        values = {
            'month': date.month, 'day_of_month': date.day, 'day_of_week': date.isoweekday(),
            'scheduled_departure_minutes': hour * 60 + minute,
            'crs_elapsed_time': duration, 'distance': distance,
            'op_unique_carrier': str(data['op_unique_carrier']).upper().strip(),
            'origin': str(data['origin']).upper().strip(), 'dest': str(data['dest']).upper().strip(),
            'dep_delay': delay_value,
        }
        for col in CATEGORICAL:
            if not values[col] or len(values[col]) > 8 or not values[col].isalnum():
                raise ValueError('Invalid airline/airport code: ' + col)
    except (ValueError, TypeError, KeyError) as e:
        raise ValueError('Invalid flight data: ' + str(e)) from e
    frame, _ = build_features(pd.DataFrame([values]), mode)
    models = artifact.get('modes', {'predeparture': artifact['models']})[mode]
    predictions = {}
    for slug, model in models.items():
        probability = float(model.predict_proba(frame)[0][1])
        predictions[slug] = {
            'name': NAMES[slug], 'probability': round(probability, 6),
            'delay_prediction': probability >= THRESHOLD,
        }
    warnings = []
    for col, title in [('op_unique_carrier', 'airline'), ('origin', 'origin airport'), ('dest', 'destination airport')]:
        if values[col] not in artifact['known_categories'].get(col, []):
            warnings.append('The ' + title + ' ' + values[col] + ' was unseen in training; prediction may be unreliable.')
    warnings.append('Predictions use historical US domestic flights in 2024; patterns may have changed.')
    if mode == 'predeparture':
        warnings.append('Scheduled-only predictions have limited ability to detect individual delays. Accuracy should be compared with the always-on-time baseline and recall.')
    else:
        warnings.append('After-departure predictions require the actual departure delay measured at departure; never use it before the flight departs.')
    metadata = artifact['metadata']
    mode_report = (json.loads(REPORT_PATH.read_text(encoding='utf-8'))
                   if model_path == MODEL_PATH and REPORT_PATH.is_file() else None)
    details = (mode_report or {}).get('mode_reports', {}).get(mode, {})
    if mode == 'after_departure' and not details:
        details = {'best_model': 'decision_tree'}
    return {
        'ok': True, 'run_id': artifact['run_id'], 'mode': mode,
        'trained_utc': metadata['created_utc'], 'dataset': metadata['dataset'],
        'best_model': details.get('best_model', metadata['best_model']),
        'threshold': THRESHOLD, 'flight': values,
        'flight_number': str(data.get('flight_number', '')).upper().strip()[:20],
        'models': predictions, 'warnings': warnings,
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    tr = sub.add_parser('train', help='Train and validate, then save all three live model pipelines')
    tr.add_argument('--dataset', type=Path, default=DATASET_PATH)
    tr.add_argument('--max-rows', type=int, default=50000, help='Maximum sampled eligible records (>=100 and <=150000)')
    tr.add_argument('--label', type=str, default=None, help='Display-only original dataset filename')
    tr.add_argument('--models-file', type=Path, default=MODEL_PATH)
    tr.add_argument('--report-file', type=Path, default=REPORT_PATH)
    pr = sub.add_parser('predict', help='Predict from a JSON input (no retraining)')
    pr.add_argument('--input-json', required=True)
    pr.add_argument('--models-file', type=Path, default=MODEL_PATH)
    args = parser.parse_args(argv)
    try:
        if args.command == 'train':
            if not 100 <= args.max_rows <= 150000:
                raise ValueError('--max-rows must be between 100 and 150000')
            report = train(args.dataset, args.max_rows, args.models_file, args.report_file, args.label)
            print(json.dumps({'ok': True, 'dataset': report['dataset'], 'eligible_rows': report['eligible_rows'],
                              'sampled_rows': report['sampled_rows'], 'validation_rows': report['validation_rows'],
                              'best_model': report['best_model'], 'run_id': report['run_id']}))
        else:
            result = predict(json.loads(args.input_json), args.models_file)
            print(json.dumps(result, allow_nan=False))
    except Exception as e:
        print(json.dumps({'ok': False, 'error': str(e)}))
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
