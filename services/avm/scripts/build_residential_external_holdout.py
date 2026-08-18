#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import json
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402
from app.listings.training import market_segment, price_band  # noqa: E402


TRAINING_DATASET = ROOT / "data" / "experiments" / "avm_v2_dataset_v2.csv"
IDENTITY_OUT = ROOT / "data" / "experiments" / "avm_v2_residential_training_identity.csv"
HOLDOUT_OUT = ROOT / "data" / "experiments" / "avm_v2_residential_external_holdout.csv"
META_OUT = ROOT / "data" / "experiments" / "avm_v2_residential_external_holdout_metadata.json"
EXCLUDED_OUT = ROOT / "data" / "experiments" / "avm_v2_residential_external_holdout_excluded.csv"

FIELDS = [
    "source", "source_id", "url", "price", "currency", "property_type", "market_segment",
    "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", "age_years",
    "price_per_construction_m2", "price_per_land_m2", "price_band",
    "latitude", "longitude", "geocode_latitude", "geocode_longitude", "coordinate_quality",
    "state", "municipality", "neighborhood", "postal_code", "street",
    "inegi_cve_ageb", "ageb_assignment_quality", "population", "occupied_housing",
    "population_density", "housing_density", "car_ownership_ratio", "internet_access_ratio",
    "average_schooling", "employment_ratio", "establishments_500m", "establishments_1km",
    "retail_500m", "retail_1km", "restaurants_hotels_500m", "restaurants_hotels_1km",
    "health_500m", "health_1km", "education_500m", "education_1km",
    "financial_500m", "financial_1km", "professional_services_500m", "professional_services_1km",
    "training_readiness", "dedupe_fingerprint", "quality_flags",
]


def main() -> int:
    parser = argparse.ArgumentParser(description="Construye holdout externo residential nunca visto por AVM v2.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--limit", type=int, default=100)
    args = parser.parse_args()

    identity = freeze_training_identity()
    storage = ListingStorage(args.db_path)
    storage.refresh_training_metrics()
    rows = [decorate(dict(row)) for row in storage.rows()]
    storage.close()

    candidates = [row for row in rows if is_holdout_candidate(row)]
    selected, excluded = select_holdout(candidates, identity, args.limit)
    write_csv(HOLDOUT_OUT, FIELDS, selected)
    write_csv(EXCLUDED_OUT, ["source", "source_id", "url", "dedupe_fingerprint", "property_type", "municipality", "price", "reason"], excluded)
    metadata = {
        "created_at": now(),
        "training_identity": str(IDENTITY_OUT),
        "db_path": str(Path(args.db_path).resolve()),
        "eligible_before_historical_exclusion": len(candidates),
        "excluded": dict(Counter(row["reason"] for row in excluded)),
        "holdout_final": len(selected),
        "by_source": counts(selected, "source"),
        "by_property_type": counts(selected, "property_type"),
        "by_municipality": counts(selected, "municipality"),
        "by_price_band": counts(selected, "price_band"),
        "by_coordinate_quality": counts(selected, "coordinate_quality"),
    }
    META_OUT.write_text(json.dumps(metadata, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(metadata, ensure_ascii=False, indent=2))
    return 0


def freeze_training_identity() -> dict[str, set[str]]:
    import pandas as pd

    df = pd.read_csv(TRAINING_DATASET)
    residential = df[df["market_segment"] == "residential"].copy()
    fields = ["source", "source_id", "url", "dedupe_fingerprint"]
    IDENTITY_OUT.parent.mkdir(parents=True, exist_ok=True)
    residential[fields].to_csv(IDENTITY_OUT, index=False)
    return {
        "source_source_id": {f"{row.source}|{row.source_id}" for row in residential.itertuples(index=False)},
        "url": {str(value) for value in residential["url"].dropna()},
        "dedupe_fingerprint": {str(value) for value in residential["dedupe_fingerprint"].dropna()},
    }


def decorate(row: dict[str, Any]) -> dict[str, Any]:
    row = dict(row)
    row["market_segment"] = market_segment(row)
    row["price_band"] = price_band(row)
    row["quality_flags"] = ",".join(json.loads(row.get("quality_flags_json") or "[]"))
    return row


def is_holdout_candidate(row: dict[str, Any]) -> bool:
    return (
        row.get("market_segment") == "residential"
        and row.get("property_type") in ("casa", "departamento")
        and row.get("training_readiness") in ("A", "B", "C")
        and row.get("currency") == "MXN"
        and row.get("price") is not None
        and float(row.get("price") or 0) > 0
        and (row.get("coordinate_quality") in ("high", "medium"))
        and row.get("inegi_cve_ageb")
        and row.get("population_density") is not None
        and row.get("establishments_1km") is not None
    )


def select_holdout(rows: list[dict[str, Any]], identity: dict[str, set[str]], limit: int) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    selected: list[dict[str, Any]] = []
    excluded: list[dict[str, Any]] = []
    seen_keys: set[str] = set()
    for row in sorted(rows, key=priority_key):
        reason = exclusion_reason(row, identity, seen_keys)
        if reason:
            excluded.append(excluded_row(row, reason))
            continue
        selected.append(row)
        seen_keys.add(row.get("dedupe_fingerprint") or f"{row.get('source')}|{row.get('source_id')}")
        if len(selected) >= limit:
            break
    return selected, excluded


def priority_key(row: dict[str, Any]) -> tuple:
    municipality_priority = {
        "Cuernavaca": 0, "Yautepec": 0, "Temixco": 0, "Emiliano Zapata": 0,
        "Jiutepec": 1, "Xochitepec": 1, "Cuautla": 1, "Atlatlahucan": 1,
    }
    band_priority = {"<1M": 0, "1M-2M": 0, "2M-3M": 0, "12M-20M": 0, ">20M": 0}
    return (
        municipality_priority.get(row.get("municipality"), 2),
        band_priority.get(row.get("price_band"), 1),
        row.get("property_type") != "departamento",
        row.get("source") == "mercadolibre",
        row.get("source_id") or "",
    )


def exclusion_reason(row: dict[str, Any], identity: dict[str, set[str]], seen_keys: set[str]) -> str | None:
    if f"{row.get('source')}|{row.get('source_id')}" in identity["source_source_id"]:
        return "historical_source_id"
    if row.get("url") in identity["url"]:
        return "historical_url"
    if row.get("dedupe_fingerprint") in identity["dedupe_fingerprint"]:
        return "historical_fingerprint"
    key = row.get("dedupe_fingerprint") or f"{row.get('source')}|{row.get('source_id')}"
    if key in seen_keys:
        return "holdout_internal_duplicate"
    return None


def excluded_row(row: dict[str, Any], reason: str) -> dict[str, Any]:
    return {key: row.get(key) for key in ["source", "source_id", "url", "dedupe_fingerprint", "property_type", "municipality", "price"]} | {"reason": reason}


def write_csv(path: Path, fields: list[str], rows: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for row in rows:
            writer.writerow({field: row.get(field) for field in fields})


def counts(rows: list[dict[str, Any]], field: str) -> dict[str, int]:
    return dict(Counter(str(row.get(field) or "missing") for row in rows).most_common())


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


if __name__ == "__main__":
    raise SystemExit(main())
