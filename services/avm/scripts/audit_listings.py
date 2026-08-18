#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sqlite3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.sources.base import FetchedListing  # noqa: E402
from app.listings.sources.easybroker import EasyBrokerPublicSource  # noqa: E402
from app.listings.storage import ListingStorage  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Audita calidad de listings normalizados.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--reparse", action="store_true", help="Reprocesa listing_normalized desde listing_raw sin descargar HTML.")
    parser.add_argument("--state", default="Morelos")
    parser.add_argument("--municipality", default="Cuautla")
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    if args.reparse:
        reparse_from_raw(storage, state=args.state, municipality=args.municipality)

    rows = storage.rows()
    print_table(rows)
    print("")
    print_reliability(rows)
    storage.close()
    return 0


def reparse_from_raw(storage: ListingStorage, state: str, municipality: str) -> None:
    source = EasyBrokerPublicSource(delay_seconds=0)
    rows = storage.connection.execute(
        "SELECT id, source, source_id, url, http_status, raw_content FROM listing_raw ORDER BY id"
    ).fetchall()
    for row in rows:
        fetched = FetchedListing(
            source=row["source"],
            source_id=row["source_id"],
            url=row["url"],
            http_status=row["http_status"],
            raw_content=row["raw_content"],
        )
        listing = source.parse(fetched, state=state, municipality=municipality)
        storage.save_normalized(listing, raw_id=row["id"])


def print_table(rows: list[sqlite3.Row]) -> None:
    headers = [
        "ID", "Título", "Precio", "Tipo", "Terreno", "Construcción", "Área genérica",
        "Recámaras", "Baños", "Est.", "Colonia", "Dirección", "Lat/lng", "Flags",
    ]
    print(" | ".join(headers))
    print(" | ".join(["---"] * len(headers)))
    for row in rows:
        flags = json.loads(row["quality_flags_json"] or "[]")
        print(" | ".join([
            str(row["source_id"]),
            compact(row["title"], 42),
            money(row["price"], row["currency"]),
            str(row["property_type"] or ""),
            area(row["land_area_m2"], row["land_area_source"]),
            area(row["construction_area_m2"], row["construction_area_source"]),
            area(row["generic_area_m2"], row["generic_area_source"]),
            value(row["bedrooms"]),
            value(row["bathrooms"]),
            value(row["parking_spaces"]),
            compact(row["neighborhood"], 24),
            compact(row["address_text"] or row["location_raw"], 40),
            latlng(row),
            ", ".join(flags),
        ]))


def print_reliability(rows: list[sqlite3.Row]) -> None:
    total = len(rows)

    def count(predicate) -> int:
        return sum(1 for row in rows if predicate(row))

    metrics = [
        ("Total listings", total),
        ("Precio confiable", count(lambda r: r["price"] is not None and float(r["price"]) > 0)),
        ("Terreno confiable", count(lambda r: r["land_area_m2"] is not None and r["land_area_source"] in ("visible_label", "metadata", "description"))),
        ("Construcción confiable", count(lambda r: r["construction_area_m2"] is not None and r["construction_area_source"] in ("visible_label", "metadata", "description"))),
        ("Ambas superficies confiables", count(lambda r: r["land_area_m2"] is not None and r["construction_area_m2"] is not None)),
        ("Superficie ambigua", count(lambda r: r["generic_area_m2"] is not None)),
        ("Tipo confiable", count(lambda r: r["property_type"] not in (None, "otro"))),
        ("Ubicación textual disponible", count(lambda r: bool(r["location_raw"] or r["address_text"] or r["neighborhood"]))),
        ("Colonia disponible", count(lambda r: bool(r["neighborhood"]))),
        ("Dirección disponible", count(lambda r: bool(r["address_text"]))),
        ("Coordenadas exactas disponibles", count(lambda r: r["latitude"] is not None and r["longitude"] is not None)),
        ("A) precio + tipo + terreno", count(lambda r: r["price"] is not None and r["property_type"] not in (None, "otro") and r["land_area_m2"] is not None)),
        ("B) precio + tipo + construcción", count(lambda r: r["price"] is not None and r["property_type"] not in (None, "otro") and r["construction_area_m2"] is not None)),
        ("C) precio + tipo + terreno + construcción", count(lambda r: r["price"] is not None and r["property_type"] not in (None, "otro") and r["land_area_m2"] is not None and r["construction_area_m2"] is not None)),
        ("D) precio + tipo + construcción + ubicación suficientemente precisa", count(lambda r: r["price"] is not None and r["property_type"] not in (None, "otro") and r["construction_area_m2"] is not None and bool(r["neighborhood"] or (r["latitude"] is not None and r["longitude"] is not None)))),
    ]
    print("Estadísticas de confiabilidad")
    for label, number in metrics:
        if label == "Total listings":
            print(f"{label}: {number}")
        else:
            percent = (number / total * 100) if total else 0
            print(f"{label}: {number}/{total} ({percent:.1f}%)")


def compact(value: object, limit: int) -> str:
    text = "" if value is None else str(value).replace("|", "/")
    return text if len(text) <= limit else text[: limit - 1] + "…"


def money(price: object, currency: object) -> str:
    if price is None:
        return ""
    return f"{float(price):.0f} {currency or ''}".strip()


def area(value_: object, source: object) -> str:
    if value_ is None:
        return ""
    suffix = f" ({source})" if source else ""
    return f"{float(value_):g}{suffix}"


def value(value_: object) -> str:
    return "" if value_ is None else str(value_)


def latlng(row: sqlite3.Row) -> str:
    if row["latitude"] is None or row["longitude"] is None:
        return ""
    return f"{row['latitude']},{row['longitude']}"


if __name__ == "__main__":
    raise SystemExit(main())

