#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import random
import re
import sys
from html import unescape
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402


FIELDS = [
    "price",
    "land_area_m2",
    "construction_area_m2",
    "bedrooms",
    "bathrooms",
    "parking_spaces",
    "location",
]


def main() -> int:
    parser = argparse.ArgumentParser(description="Audita listings Mercado Libre normalizados.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--manual-sample", type=int, default=10)
    parser.add_argument("--seed", type=int, default=17)
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    rows = list(storage.connection.execute("SELECT * FROM listing_normalized WHERE source = 'mercadolibre' ORDER BY id"))
    print_table(rows)
    print_summary(rows)
    print_surface_pairs(rows)
    print_manual_accuracy(storage, rows, args.manual_sample, args.seed)
    storage.close()
    return 0


def print_table(rows) -> None:
    print("source_id | title | price | type | land | construction | generic | beds | baths | parking | municipality | neighborhood | cp | street | lat | lng | flags")
    print("--- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | ---")
    for row in rows:
        flags = ",".join(json.loads(row["quality_flags_json"] or "[]"))
        print(" | ".join([
            str(row["source_id"]),
            compact(row["title"], 48),
            str(row["price"] or ""),
            str(row["property_type"] or ""),
            str(row["land_area_m2"] or ""),
            str(row["construction_area_m2"] or ""),
            str(row["generic_area_m2"] or ""),
            str(row["bedrooms"] or ""),
            str(row["bathrooms"] or ""),
            str(row["parking_spaces"] or ""),
            str(row["municipality"] or ""),
            str(row["neighborhood"] or ""),
            str(row["postal_code"] or ""),
            str(row["street"] or ""),
            str(row["latitude"] or ""),
            str(row["longitude"] or ""),
            flags,
        ]))


def print_summary(rows) -> None:
    total = len(rows)
    print("")
    print(f"total: {total}")
    for label, predicate in [
        ("con precio", lambda r: r["price"] is not None),
        ("con terreno", lambda r: r["land_area_m2"] is not None),
        ("con construcción", lambda r: r["construction_area_m2"] is not None),
        ("con ambas", lambda r: r["land_area_m2"] is not None and r["construction_area_m2"] is not None),
        ("sólo superficie genérica", lambda r: r["generic_area_m2"] is not None and r["land_area_m2"] is None and r["construction_area_m2"] is None),
        ("con recámaras", lambda r: r["bedrooms"] is not None),
        ("con baños", lambda r: r["bathrooms"] is not None),
        ("con estacionamiento", lambda r: r["parking_spaces"] is not None),
        ("con colonia", lambda r: r["neighborhood"] is not None),
        ("con CP", lambda r: r["postal_code"] is not None),
        ("con calle", lambda r: r["street"] is not None),
        ("con coordenadas", lambda r: r["latitude"] is not None and r["longitude"] is not None),
    ]:
        count = sum(1 for row in rows if predicate(row))
        percent = (count / total * 100) if total else 0
        print(f"{label}: {count}/{total} ({percent:.1f}%)")
    print(f"ambiguous_area: {count_flag(rows, 'ambiguous_area')}")
    print(f"suspicious_surface_pair: {count_flag(rows, 'suspicious_surface_pair')}")
    print(f"land == construction: {sum(1 for row in rows if row['land_area_m2'] is not None and row['land_area_m2'] == row['construction_area_m2'])}")


def print_surface_pairs(rows) -> None:
    print("")
    print("Pares de superficies")
    print("title | land | construction | ratio | flag")
    print("--- | --- | --- | --- | ---")
    for row in rows:
        if row["land_area_m2"] is None or row["construction_area_m2"] is None:
            continue
        ratio = float(row["construction_area_m2"]) / float(row["land_area_m2"]) if row["land_area_m2"] else None
        flags = set(json.loads(row["quality_flags_json"] or "[]"))
        print(" | ".join([
            compact(row["title"], 70),
            str(row["land_area_m2"]),
            str(row["construction_area_m2"]),
            f"{ratio:.2f}" if ratio is not None else "",
            "suspicious_surface_pair" if "suspicious_surface_pair" in flags else "",
        ]))


def print_manual_accuracy(storage: ListingStorage, rows, sample_size: int, seed: int) -> None:
    if not rows or sample_size <= 0:
        return
    rng = random.Random(seed)
    sample = rng.sample(rows, min(sample_size, len(rows)))
    counters = {field: {"checked": 0, "correct": 0, "incorrect": 0, "missing": 0} for field in FIELDS}
    print("")
    print("Validación manual automática contra RAW")
    print("source_id | field | parsed | evidence | status")
    print("--- | --- | --- | --- | ---")
    for row in sample:
        raw = storage.connection.execute("SELECT raw_content FROM listing_raw WHERE id = ?", (row["raw_id"],)).fetchone()
        text = normalized_text(raw["raw_content"] if raw else "")
        for field in FIELDS:
            status, evidence = evaluate_field(row, text, field)
            counters[field]["checked"] += 1
            counters[field][status] += 1
            print(" | ".join([
                row["source_id"],
                field,
                compact(parsed_value(row, field), 32),
                compact(evidence, 70),
                status,
            ]))
    print("")
    print("field | checked | correct | incorrect | missing | accuracy")
    print("--- | --- | --- | --- | --- | ---")
    for field, values in counters.items():
        checked = values["checked"]
        accuracy = (values["correct"] / checked * 100) if checked else 0
        print(f"{field} | {checked} | {values['correct']} | {values['incorrect']} | {values['missing']} | {accuracy:.1f}%")


def evaluate_field(row, text: str, field: str) -> tuple[str, str]:
    value = parsed_value(row, field)
    if value in ("", None):
        return "missing", ""
    if field == "location":
        needles = [row["municipality"], row["neighborhood"], row["postal_code"], row["street"]]
        hits = [str(item) for item in needles if item and str(item).lower() in text.lower()]
        return ("correct", ", ".join(hits)) if hits else ("incorrect", "")
    if str(int(float(value))) if is_number(value) else str(value):
        needle = str(int(float(value))) if is_number(value) else str(value)
        match = re.search(rf".{{0,35}}{re.escape(needle)}.{{0,35}}", text, flags=re.I)
        if match:
            return "correct", match.group(0)
    return "incorrect", ""


def parsed_value(row, field: str):
    if field == "location":
        return ", ".join([str(row[key]) for key in ("street", "neighborhood", "municipality", "postal_code") if row[key]])
    return row[field]


def normalized_text(html: str) -> str:
    return re.sub(r"\s+", " ", unescape(re.sub(r"<[^>]+>", " ", html or ""))).strip()


def count_flag(rows, flag: str) -> int:
    return sum(1 for row in rows if flag in set(json.loads(row["quality_flags_json"] or "[]")))


def compact(value: object, limit: int) -> str:
    text = "" if value is None else str(value).replace("|", "/")
    return text if len(text) <= limit else text[: limit - 1] + "…"


def is_number(value: object) -> bool:
    try:
        float(value)
        return True
    except (TypeError, ValueError):
        return False


if __name__ == "__main__":
    raise SystemExit(main())
