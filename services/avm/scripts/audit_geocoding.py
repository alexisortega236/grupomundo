#!/usr/bin/env python3
from __future__ import annotations

import argparse
from collections import Counter
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Audita resultados de geocodificación de listings.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    rows = storage.rows()
    counts = Counter()

    print("Título | Ubicación original | Ubicación encontrada | Lat | Lng | Precisión | Confianza | Uso")
    print("--- | --- | --- | --- | --- | --- | --- | ---")
    for row in rows:
        precision = row["geocode_precision"] or "failed"
        if not row["geocode_latitude"] or not row["geocode_longitude"] or precision == "unknown":
            precision = "failed"
        counts[precision] += 1
        print(" | ".join([
            compact(row["title"], 44),
            compact(row["address_text"] or row["location_raw"] or row["neighborhood"], 42),
            compact(row["geocode_formatted_address"], 50),
            value(row["geocode_latitude"]),
            value(row["geocode_longitude"]),
            precision,
            confidence(row["geocode_confidence"]),
            row["geocode_usability"] or "unusable",
        ]))

    print("")
    print(f"Total listings: {len(rows)}")
    for precision in ("exact_address", "street", "postal_code", "neighborhood", "locality", "municipality", "state", "failed"):
        print(f"{precision}: {counts[precision]}")

    storage.close()
    return 0


def compact(value_: object, limit: int) -> str:
    text = "" if value_ is None else str(value_).replace("|", "/")
    return text if len(text) <= limit else text[: limit - 1] + "…"


def value(value_: object) -> str:
    if value_ is None:
        return ""
    return f"{float(value_):.6f}"


def confidence(value_: object) -> str:
    if value_ is None:
        return ""
    return f"{float(value_):.2f}"


if __name__ == "__main__":
    raise SystemExit(main())

