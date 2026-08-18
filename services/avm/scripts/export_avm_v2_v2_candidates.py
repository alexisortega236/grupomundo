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
    "training_readiness", "duplicate_group_id", "dedupe_fingerprint", "quality_flags",
]


def main() -> int:
    parser = argparse.ArgumentParser(description="Exporta candidatos deduplicados A/B/C para AVM v2 v2.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--output", default=str(ROOT / "data" / "experiments" / "avm_v2_dataset_v2_candidates.csv"))
    parser.add_argument("--metadata-output", default=str(ROOT / "data" / "experiments" / "avm_v2_dataset_v2_candidates_metadata.json"))
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    storage.refresh_training_metrics()
    rows = [dict(row) for row in storage.rows()]
    candidate_rows = [decorate(row) for row in rows if row["training_readiness"] in ("A", "B", "C")]
    deduped = dedupe_candidates(candidate_rows)

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDS)
        writer.writeheader()
        for row in deduped:
            writer.writerow({field: row.get(field) for field in FIELDS})

    metadata = {
        "created_at": now(),
        "source_db": str(Path(args.db_path).resolve()),
        "raw_total": len(rows),
        "candidate_total_before_deduplication": len(candidate_rows),
        "deduplicated_candidate_total": len(deduped),
        "duplicates_removed": len(candidate_rows) - len(deduped),
        "by_source": counts(deduped, "source"),
        "by_property_type": counts(deduped, "property_type"),
        "by_market_segment": counts(deduped, "market_segment"),
        "by_municipality": counts(deduped, "municipality"),
        "by_price_band": counts(deduped, "price_band"),
        "by_training_readiness": counts(deduped, "training_readiness"),
        "by_coordinate_quality": counts(deduped, "coordinate_quality"),
        "readiness_rules": {
            "residential_A": "price + type + physical features + coordinate_quality high + AGEB + Censo + DENUE",
            "residential_B": "same as A with coordinate_quality medium",
            "residential_C": "usable location + AGEB + Censo + DENUE + sufficient physical features",
            "land_A": "price + land_area_m2 + coordinate_quality high + AGEB + Censo + DENUE",
            "land_B": "same as A with coordinate_quality medium",
            "land_C": "usable location + AGEB + Censo + DENUE + land_area_m2",
        },
    }
    Path(args.metadata_output).write_text(json.dumps(metadata, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(metadata, ensure_ascii=False, indent=2))
    storage.close()
    return 0


def decorate(row: dict[str, Any]) -> dict[str, Any]:
    row = dict(row)
    row["market_segment"] = market_segment(row)
    row["price_band"] = price_band(row)
    row["quality_flags"] = ",".join(json.loads(row.get("quality_flags_json") or "[]"))
    row["duplicate_group_id"] = duplicate_group_id(row)
    return row


def duplicate_group_id(row: dict[str, Any]) -> str:
    parts = [
        row.get("source"),
        row.get("price"),
        row.get("municipality"),
        row.get("neighborhood"),
        row.get("land_area_m2"),
        row.get("construction_area_m2"),
        row.get("bedrooms"),
        row.get("bathrooms"),
    ]
    return "|".join(normalize(part) for part in parts)


def dedupe_candidates(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    seen: set[str] = set()
    result: list[dict[str, Any]] = []
    for row in sorted(rows, key=lambda item: (item.get("duplicate_group_id") or "", item.get("source_id") or "")):
        group = row["duplicate_group_id"]
        if group in seen:
            continue
        seen.add(group)
        result.append(row)
    return result


def counts(rows: list[dict[str, Any]], field: str) -> dict[str, int]:
    return dict(Counter(str(row.get(field) or "missing") for row in rows).most_common())


def normalize(value: Any) -> str:
    if value is None or value == "":
        return ""
    try:
        return str(round(float(value), 2)).lower()
    except (TypeError, ValueError):
        return str(value).strip().lower()


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


if __name__ == "__main__":
    raise SystemExit(main())
