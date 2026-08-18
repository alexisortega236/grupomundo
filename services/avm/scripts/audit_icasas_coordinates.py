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

from app.listings.spatial.coordinates import coordinate_hash, haversine_m  # noqa: E402
from app.listings.spatial.quality import validate_coordinate  # noqa: E402
from app.listings.spatial.reverse_geocoder import NominatimReverseGeocoder  # noqa: E402
from app.listings.storage import ListingStorage  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Audita coordenadas directas de iCasas.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--reverse", action="store_true", help="Valida coordenadas con reverse geocoding Nominatim y cache.")
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    rows = list(storage.connection.execute("SELECT * FROM listing_normalized WHERE source = 'icasas' ORDER BY id"))
    groups = group_coordinates(rows)
    nearby = nearby_counts(rows)

    print("source_id | title | street | neighborhood | postal_code | latitude | longitude")
    print("--- | --- | --- | --- | --- | --- | ---")
    for row in rows:
        print(" | ".join([
            row["source_id"],
            compact(row["title"], 42),
            row["street"] or "",
            row["neighborhood"] or "",
            row["postal_code"] or "",
            str(row["latitude"] or ""),
            str(row["longitude"] or ""),
        ]))

    print("")
    print("Grupos de coordenadas")
    for key, group in groups.items():
        if len(group) < 2:
            continue
        print(key)
        for row in group:
            print(f"  - {row['source_id']} / {row['neighborhood'] or ''} / {compact(row['title'], 70)}")

    if args.reverse:
        reverse = NominatimReverseGeocoder()
        for row in rows:
            if row["latitude"] is None or row["longitude"] is None:
                storage.apply_coordinate_audit(row["id"], "unknown", "sin coordenadas", "unusable")
                continue
            key = coordinate_hash(row["latitude"], row["longitude"])
            cached = storage.get_reverse_geocode_cache(key)
            if cached:
                payload = json.loads(cached["raw_response_json"] or "{}")
            else:
                payload = reverse.reverse(row["latitude"], row["longitude"])
                storage.save_reverse_geocode_cache(key, row["latitude"], row["longitude"], reverse.name, payload)
            status, notes, quality = validate_coordinate(
                row,
                payload,
                shared_count=len(groups[key]),
                nearby_count=nearby[row["id"]],
            )
            storage.apply_coordinate_audit(row["id"], status, notes, quality, reverse.name, payload)

    unique_count = len(groups)
    shared_listing_count = sum(len(group) for group in groups.values() if len(group) > 1)
    print("")
    print(f"Total iCasas listings: {len(rows)}")
    print(f"unique_coordinate_count: {unique_count}")
    print(f"repeated_coordinate_groups: {sum(1 for group in groups.values() if len(group) > 1)}")
    print(f"listings_with_unique_coordinates: {sum(1 for group in groups.values() if len(group) == 1)}")
    print(f"listings_with_shared_coordinates: {shared_listing_count}")
    print("Distancias < 25 m por listing:")
    for row in rows:
        if nearby[row["id"]] > 1:
            print(f"{row['source_id']}: {nearby[row['id']] - 1} cercanos")
    storage.close()
    return 0


def group_coordinates(rows: list[sqlite3.Row]) -> dict[str, list[sqlite3.Row]]:
    groups: dict[str, list[sqlite3.Row]] = defaultdict(list)
    for row in rows:
        if row["latitude"] is not None and row["longitude"] is not None:
            groups[coordinate_hash(row["latitude"], row["longitude"])].append(row)
    return groups


def nearby_counts(rows: list[sqlite3.Row], threshold_m: float = 25) -> dict[int, int]:
    counts = {row["id"]: 1 for row in rows}
    for i, a in enumerate(rows):
        if a["latitude"] is None or a["longitude"] is None:
            continue
        for b in rows[i + 1:]:
            if b["latitude"] is None or b["longitude"] is None:
                continue
            if haversine_m(a["latitude"], a["longitude"], b["latitude"], b["longitude"]) < threshold_m:
                counts[a["id"]] += 1
                counts[b["id"]] += 1
    return counts


def compact(value: object, limit: int) -> str:
    text = "" if value is None else str(value).replace("|", "/")
    return text if len(text) <= limit else text[: limit - 1] + "…"


if __name__ == "__main__":
    raise SystemExit(main())

