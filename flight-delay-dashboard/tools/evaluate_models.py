#!/usr/bin/env python3
"""Compatibility wrapper for the existing FlightSense evaluation command.

This now saves real fitted models as well as live validation metrics.
Use `python tools/model_pipeline.py train --dataset path/to/csv` for full options.
"""
import sys
from pathlib import Path
from model_pipeline import train, DATASET_PATH

if __name__ == '__main__':
    try:
        report = train(Path(sys.argv[1]) if len(sys.argv) > 1 else DATASET_PATH, 50000)
        print(f"Saved {report['sampled_rows']:,} sampled flights, {report['validation_rows']:,} test records, and 3 live classifiers.")
        for model in report['models'].values():
            m = model['metrics']
            print(f"{model['name']}: accuracy={m['accuracy']:.1%}, recall={m['recall']:.1%}, F1={m['f1']:.1%}")
    except Exception as exc:
        sys.exit(str(exc))
