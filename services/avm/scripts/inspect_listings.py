#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Inspecciona el dataset local de listings AVM.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--examples", type=int, default=0)
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    try:
        summary = storage.summary()
        print(f"Total listings: {summary['total']}")
        print("")
        print_presence("Precio", summary["fields"]["price"])
        print_presence("Coordenadas", merge_presence(summary["fields"]["latitude"], summary["fields"]["longitude"]))
        print_presence("Terreno", summary["fields"]["land_area_m2"])
        print_presence("Construcción", summary["fields"]["construction_area_m2"])
        print_presence("Recámaras", summary["fields"]["bedrooms"])
        print_presence("Baños", summary["fields"]["bathrooms"])
        print_presence("Estacionamiento", summary["fields"]["parking_spaces"])
        print("")
        print_stats("Precio", summary["price"])
        print_stats("m² construcción", summary["construction_area_m2"])
        print_stats("precio/m² construcción", summary["price_m2_construction"])

        if args.examples:
            print("")
            print("Ejemplos:")
            for row in storage.rows(limit=args.examples):
                print(json.dumps({
                    "source": row["source"],
                    "source_id": row["source_id"],
                    "url": row["url"],
                    "title": row["title"],
                    "property_type": row["property_type"],
                    "operation": row["operation"],
                    "price": row["price"],
                    "currency": row["currency"],
                    "latitude": row["latitude"],
                    "longitude": row["longitude"],
                    "state": row["state"],
                    "municipality": row["municipality"],
                    "neighborhood": row["neighborhood"],
                    "land_area_m2": row["land_area_m2"],
                    "construction_area_m2": row["construction_area_m2"],
                    "bedrooms": row["bedrooms"],
                    "bathrooms": row["bathrooms"],
                    "parking_spaces": row["parking_spaces"],
                    "quality_flags": json.loads(row["quality_flags_json"] or "[]"),
                }, ensure_ascii=False, indent=2))
    finally:
        storage.close()
    return 0


def print_presence(label: str, data: dict) -> None:
    print(f"{label}:")
    print(f"  presentes: {data['present']} ({data['percent']:.1f}%)")


def print_stats(label: str, data: dict) -> None:
    if data["min"] is None:
        print(f"{label}: sin datos")
        return
    print(f"{label}:")
    print(f"  mínimo: {data['min']:.2f}")
    print(f"  mediana: {data['median']:.2f}")
    print(f"  promedio: {data['average']:.2f}")
    print(f"  máximo: {data['max']:.2f}")


def merge_presence(a: dict, b: dict) -> dict:
    present = min(a["present"], b["present"])
    total = round(a["present"] / (a["percent"] / 100)) if a["percent"] else 0
    percent = (present / total * 100) if total else 0
    return {"present": present, "percent": percent}


if __name__ == "__main__":
    raise SystemExit(main())

