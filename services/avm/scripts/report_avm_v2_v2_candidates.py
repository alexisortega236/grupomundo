#!/usr/bin/env python3
from __future__ import annotations

import argparse
from collections import Counter
from pathlib import Path

import pandas as pd

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_INPUT = ROOT / "data" / "experiments" / "avm_v2_dataset_v2_candidates.csv"


def main() -> int:
    parser = argparse.ArgumentParser(description="Reporte de balance de candidatos AVM v2 v2.")
    parser.add_argument("--input", default=str(DEFAULT_INPUT))
    args = parser.parse_args()

    df = pd.read_csv(args.input)
    print(f"TOTAL candidatos deduplicados A/B/C: {len(df)}")
    for column in ["source", "property_type", "market_segment", "municipality", "price_band", "training_readiness", "coordinate_quality"]:
        print_counter(column, Counter(df[column].fillna("missing")))

    print_cross(df, "property_type", "municipality")
    print_cross(df, "property_type", "price_band")
    print_cross(df, "municipality", "price_band")
    return 0


def print_counter(title: str, counter: Counter) -> None:
    print("")
    print(title)
    for key, count in counter.most_common():
        print(f"  {key}: {count}")


def print_cross(df: pd.DataFrame, a: str, b: str) -> None:
    print("")
    print(f"{a} x {b}")
    table = pd.crosstab(df[a].fillna("missing"), df[b].fillna("missing"))
    print(table.to_string())


if __name__ == "__main__":
    raise SystemExit(main())
