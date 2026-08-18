#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sqlite3
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402


FIELDS = [
    ("precio", lambda r: r["price"] is not None),
    ("terreno", lambda r: r["land_area_m2"] is not None),
    ("construcción", lambda r: r["construction_area_m2"] is not None),
    ("recámaras", lambda r: r["bedrooms"] is not None),
    ("baños", lambda r: r["bathrooms"] is not None),
    ("estacionamientos", lambda r: r["parking_spaces"] is not None),
    ("ubicación textual", lambda r: bool(r["address_text"] or r["location_raw"])),
    ("colonia", lambda r: bool(r["neighborhood"])),
    ("CP", lambda r: has_location_postal_code(r)),
    ("calle", lambda r: bool(r["street"])),
    ("lat/lng fuente", lambda r: r["latitude"] is not None and r["longitude"] is not None),
    ("lat/lng geocoding", lambda r: r["geocode_latitude"] is not None and r["geocode_longitude"] is not None),
    ("exact_address", lambda r: r["geocode_precision"] == "exact_address"),
    ("street", lambda r: r["geocode_precision"] == "street"),
    ("postal_code", lambda r: r["geocode_precision"] == "postal_code"),
    ("neighborhood", lambda r: r["geocode_precision"] == "neighborhood"),
    ("locality", lambda r: r["geocode_precision"] == "locality"),
    ("municipality", lambda r: r["geocode_precision"] == "municipality"),
    ("unknown", lambda r: not r["geocode_precision"] or r["geocode_precision"] == "unknown"),
]


def main() -> int:
    parser = argparse.ArgumentParser(description="Compara completitud y posibles duplicados entre fuentes.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    rows = storage.rows()
    by_source = defaultdict(list)
    for row in rows:
        by_source[row["source"]].append(row)
    sources = sorted(by_source.keys())

    print("Campo | " + " | ".join(sources))
    print("--- | " + " | ".join(["---"] * len(sources)))
    print("Total listings | " + " | ".join(str(len(by_source[source])) for source in sources))
    for label, predicate in FIELDS:
        print(label + " | " + " | ".join(format_metric(by_source[source], predicate) for source in sources))

    print("")
    print("Calidad de ubicación")
    for source in sources:
        counts = defaultdict(int)
        for row in by_source[source]:
            counts[location_quality(row)] += 1
        print(f"{source}: A={counts['A']} B={counts['B']} C={counts['C']} D={counts['D']} E={counts['E']}")

    print("")
    print("Posibles duplicados cross-source por fingerprint")
    found = False
    groups = defaultdict(list)
    for row in rows:
        if row["dedupe_fingerprint"]:
            groups[row["dedupe_fingerprint"]].append(row)
    for fingerprint, group in groups.items():
        if len({row["source"] for row in group}) < 2:
            continue
        found = True
        print(f"fingerprint: {fingerprint}")
        for row in group:
            print(json.dumps({
                "source": row["source"],
                "title": row["title"],
                "price": row["price"],
                "land_area_m2": row["land_area_m2"],
                "construction_area_m2": row["construction_area_m2"],
                "location": row["address_text"] or row["location_raw"],
            }, ensure_ascii=False))
    if not found:
        print("Sin coincidencias exactas por dedupe_fingerprint.")

    storage.close()
    return 0


def format_metric(rows: list[sqlite3.Row], predicate) -> str:
    total = len(rows)
    count = sum(1 for row in rows if predicate(row))
    percent = (count / total * 100) if total else 0
    return f"{count}/{total} ({percent:.1f}%)"


def location_quality(row: sqlite3.Row) -> str:
    if row["latitude"] is not None and row["longitude"] is not None:
        return "A"
    if row["street"] and row["municipality"]:
        return "B"
    if has_location_postal_code(row) or row["neighborhood"]:
        return "C"
    if row["locality"] or row["municipality"]:
        return "D"
    return "E"


def has_location_postal_code(row: sqlite3.Row) -> bool:
    if not row["postal_code"]:
        return False
    location = " ".join([str(row["address_text"] or ""), str(row["location_raw"] or "")])
    return str(row["postal_code"]) in location


if __name__ == "__main__":
    raise SystemExit(main())
