#!/usr/bin/env python3
from __future__ import annotations

import argparse
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.geocoding.providers.nominatim import NominatimGeocodingProvider  # noqa: E402
from app.listings.geocoding.service import GeocodingService  # noqa: E402
from app.listings.storage import ListingStorage  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Geocodifica listings normalizados sin descargar listings nuevos.")
    parser.add_argument("--source", default=None)
    parser.add_argument("--municipality", default=None)
    parser.add_argument("--limit", type=int, default=None)
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--force", action="store_true")
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    service = GeocodingService(storage, NominatimGeocodingProvider())

    rows = storage.eligible_geocoding_rows(source=args.source, municipality=args.municipality, limit=args.limit)
    already = sum(1 for row in rows if row["geocoded_at"] and not args.force)
    consulted = 0
    found = 0
    precision_counts = Counter()

    try:
        for row in rows:
            result, did_consult = service.geocode_listing(row, force=args.force)
            if did_consult:
                consulted += 1
            if result and result.found:
                found += 1
            precision_counts[(result.precision if result else "unknown")] += 1

        print(f"Total elegibles: {len(rows)}")
        print(f"Ya geocodificados: {already}")
        print(f"Consultados: {consulted}")
        print(f"Encontrados: {found}")
        print(f"No encontrados: {len(rows) - found}")
        print("")
        print("Por precisión:")
        for precision in ("exact_address", "street", "postal_code", "neighborhood", "locality", "municipality", "state", "unknown"):
            print(f"{precision}: {precision_counts[precision]}")
    finally:
        storage.close()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

