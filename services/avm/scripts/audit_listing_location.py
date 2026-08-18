#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import sqlite3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.sources.base import FetchedListing  # noqa: E402
from app.listings.sources.easybroker import EasyBrokerPublicSource  # noqa: E402
from app.listings.storage import ListingStorage  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Audita coordenadas disponibles en RAW listings.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    source = EasyBrokerPublicSource(delay_seconds=0)
    rows = storage.connection.execute(
        "SELECT source, source_id, url, http_status, raw_content FROM listing_raw ORDER BY id"
    ).fetchall()

    print("Listing | coordenada encontrada | fuente | lat | lng | patrones")
    print("--- | --- | --- | --- | --- | ---")
    for row in rows:
        fetched = FetchedListing(
            source=row["source"],
            source_id=row["source_id"],
            url=row["url"],
            http_status=row["http_status"],
            raw_content=row["raw_content"],
        )
        listing = source.parse(fetched)
        patterns = coordinate_pattern_hits(row["raw_content"])
        raw_data = listing.raw_data or {}
        coordinate_source = raw_data.get("coordinate_source")
        found = "sí" if listing.latitude is not None and listing.longitude is not None else "no"
        print(
            f"{row['source_id']} | {found} | {coordinate_source or ''} | "
            f"{listing.latitude if listing.latitude is not None else ''} | "
            f"{listing.longitude if listing.longitude is not None else ''} | "
            f"{', '.join(patterns)}"
        )

    storage.close()
    return 0


def coordinate_pattern_hits(html: str) -> list[str]:
    checks = {
        "latitude": r"latitude",
        "longitude": r"longitude",
        "lat": r"\blat\b",
        "lng": r"\blng\b",
        "location": r"location",
        "coordinates": r"coordinates",
        "map": r"\bmap\b|mapa",
        "maps.google": r"maps\.google|google\.com/maps",
        "google_maps": r"google_maps|googleMaps",
        "center": r"\bcenter\b",
        "marker": r"\bmarker\b",
        "iframe": r"<iframe",
        "data-*": r"data-[a-z0-9_-]+",
    }
    found = []
    for label, pattern in checks.items():
        if re.search(pattern, html or "", flags=re.I):
            found.append(label)
    return found


if __name__ == "__main__":
    raise SystemExit(main())

