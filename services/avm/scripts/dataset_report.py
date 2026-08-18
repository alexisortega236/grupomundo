#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import statistics
import sys
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Reporte de calidad del dataset de listings AVM.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    storage.refresh_training_metrics()
    rows = list(storage.connection.execute("SELECT * FROM listing_normalized ORDER BY source, municipality, id"))
    print(f"Total listings: {len(rows)}")
    print_counter("Total listings por fuente", Counter(row["source"] or "sin_fuente" for row in rows))
    print_counter("Total listings por municipio", Counter(row["municipality"] or "sin_municipio" for row in rows))
    print_counter("Total por tipo", Counter(row["property_type"] or "sin_tipo" for row in rows))
    print("")
    print_presence("Con precio", rows, lambda r: r["price"] is not None)
    print_presence("Con lat/lng fuente", rows, lambda r: r["latitude"] is not None and r["longitude"] is not None)
    print_presence("Con AGEB", rows, lambda r: bool(r["inegi_cve_ageb"]))
    print_presence("Con Censo", rows, lambda r: r["population_density"] is not None and r["housing_density"] is not None)
    print_presence("Con DENUE", rows, lambda r: r["establishments_500m"] is not None and r["establishments_1km"] is not None)
    print_presence("Con terreno", rows, lambda r: r["land_area_m2"] is not None)
    print_presence("Con construcción", rows, lambda r: r["construction_area_m2"] is not None)
    print_presence("Con ambas superficies", rows, lambda r: r["land_area_m2"] is not None and r["construction_area_m2"] is not None)
    print_counter("Coordinate quality", Counter(row["coordinate_quality"] or "sin_clasificar" for row in rows))
    print_counter("Training readiness", Counter(row["training_readiness"] or "E" for row in rows))
    print_group_medians("Precio mediano por municipio", rows, "municipality", "price")
    print_group_medians("Precio/m2 mediano por municipio", rows, "municipality", "price_per_construction_m2")
    print_group_medians("Precio mediano por tipo", rows, "property_type", "price")
    print_outliers(rows)
    print("")
    print("Candidatos reales de entrenamiento A/B/C:", sum(1 for row in rows if row["training_readiness"] in ("A", "B", "C")))
    storage.close()
    return 0


def print_counter(title: str, counter: Counter) -> None:
    print("")
    print(f"{title}:")
    for key, value in counter.most_common():
        print(f"  {key}: {value}")


def print_presence(label: str, rows: list, predicate) -> None:
    total = len(rows)
    present = sum(1 for row in rows if predicate(row))
    percent = (present / total * 100) if total else 0
    print(f"{label}: {present}/{total} ({percent:.1f}%)")


def print_group_medians(title: str, rows: list, group_field: str, value_field: str) -> None:
    groups: dict[str, list[float]] = defaultdict(list)
    for row in rows:
        if row[value_field] is None:
            continue
        groups[row[group_field] or "sin_dato"].append(float(row[value_field]))
    print("")
    print(f"{title}:")
    for group, values in sorted(groups.items()):
        print(f"  {group}: {statistics.median(values):,.2f} ({len(values)} registros)")


def print_outliers(rows: list) -> None:
    outliers = []
    keys = {
        "invalid_price",
        "invalid_land_area",
        "invalid_construction_area",
        "suspicious_land_area",
        "suspicious_construction_area",
        "suspicious_bedrooms",
        "suspicious_bathrooms",
        "suspicious_price_m2",
        "suspicious_price_per_construction_m2",
        "suspicious_price_per_land_m2",
    }
    for row in rows:
        flags = set(json.loads(row["quality_flags_json"] or "[]"))
        selected = sorted(flags & keys)
        if selected:
            outliers.append((row, selected))
    print("")
    print(f"Principales outliers: {len(outliers)}")
    for row, flags in outliers[:20]:
        print(f"  {row['source']} {row['source_id']} | {row['municipality']} | {row['title']} | {', '.join(flags)}")


if __name__ == "__main__":
    raise SystemExit(main())
