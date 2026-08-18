#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402
from app.listings.training import market_segment, price_band  # noqa: E402


FIELDS = [
    "source", "source_id", "url", "price", "currency", "property_type",
    "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", "age_years",
    "price_per_construction_m2", "price_per_land_m2",
    "latitude", "longitude", "coordinate_quality", "state", "municipality", "neighborhood", "postal_code", "street",
    "inegi_cve_ageb", "ageb_assignment_quality", "population", "occupied_housing", "population_density", "housing_density", "car_ownership_ratio",
    "internet_access_ratio", "average_schooling", "employment_ratio", "establishments_500m", "establishments_1km",
    "retail_500m", "retail_1km", "restaurants_hotels_500m", "restaurants_hotels_1km", "health_500m", "health_1km",
    "education_500m", "education_1km", "financial_500m", "financial_1km", "professional_services_500m",
    "professional_services_1km", "training_readiness", "market_segment", "price_band", "quality_flags",
]


def main() -> int:
    parser = argparse.ArgumentParser(description="Exporta dataset enriquecido AVM a CSV.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--output", default=str(ROOT / "data" / "avm_listings_enriched.csv"))
    parser.add_argument("--candidates-output", default=str(ROOT / "data" / "avm_training_candidates.csv"))
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    storage.refresh_training_metrics()
    rows = storage.rows()
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDS)
        writer.writeheader()
        for row in rows:
            data = row_data(row)
            writer.writerow(data)
    candidates_output = Path(args.candidates_output)
    candidates = [row for row in rows if row["training_readiness"] in ("A", "B", "C")]
    with candidates_output.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDS)
        writer.writeheader()
        for row in candidates:
            data = row_data(row)
            writer.writerow(data)
    print(f"CSV exportado: {output}")
    print(f"Filas: {len(rows)}")
    print(f"Candidatos exportados: {candidates_output}")
    print(f"Filas candidatas A/B/C: {len(candidates)}")
    storage.close()
    return 0


def row_data(row) -> dict:
    data = {field: row[field] if field in row.keys() else None for field in FIELDS}
    data["market_segment"] = market_segment(row)
    data["price_band"] = price_band(row)
    data["quality_flags"] = ",".join(json.loads(row["quality_flags_json"] or "[]"))
    return data


if __name__ == "__main__":
    raise SystemExit(main())
