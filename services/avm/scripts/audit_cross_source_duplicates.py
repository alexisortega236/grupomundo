#!/usr/bin/env python3
from __future__ import annotations

import argparse
import math
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Audita posibles duplicados entre fuentes de listings.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--min-score", type=float, default=0.62)
    parser.add_argument("--limit", type=int, default=50)
    args = parser.parse_args()

    storage = ListingStorage(args.db_path)
    rows = list(storage.connection.execute("SELECT * FROM listing_normalized ORDER BY source, id"))
    matches = []
    for left in rows:
        for right in rows:
            if left["source"] >= right["source"]:
                continue
            score = duplicate_score(left, right)
            if score >= args.min_score:
                matches.append((score, left, right))
    matches.sort(key=lambda item: item[0], reverse=True)

    print(f"Posibles duplicados cross-source: {len(matches)}")
    for score, left, right in matches[: args.limit]:
        print("")
        print(f"score={score:.2f}")
        print(f"{left['source']} | {left['source_id']} | {left['title']}")
        print(f"{right['source']} | {right['source_id']} | {right['title']}")
        print(f"precio: {left['price']} vs {right['price']}")
        print(f"superficies: terreno {left['land_area_m2']} vs {right['land_area_m2']} | construccion {left['construction_area_m2']} vs {right['construction_area_m2']}")
        print(f"ubicacion: {left['municipality']} / {left['neighborhood']} / {left['street']}  <>  {right['municipality']} / {right['neighborhood']} / {right['street']}")
        print(f"fingerprint: {left['dedupe_fingerprint']} <> {right['dedupe_fingerprint']}")
    storage.close()
    return 0


def duplicate_score(left, right) -> float:
    score = 0.0
    weight = 0.0
    for field, field_weight in [("municipality", 0.12), ("neighborhood", 0.12), ("street", 0.10), ("property_type", 0.10)]:
        weight += field_weight
        if _norm(left[field]) and _norm(left[field]) == _norm(right[field]):
            score += field_weight
    score += _similar_number(left["price"], right["price"], 0.20, 0.12)
    weight += 0.20
    score += _similar_number(left["land_area_m2"], right["land_area_m2"], 0.12, 0.10)
    weight += 0.12
    score += _similar_number(left["construction_area_m2"], right["construction_area_m2"], 0.12, 0.10)
    weight += 0.12
    score += _similar_number(left["bedrooms"], right["bedrooms"], 0.07, 0.01)
    weight += 0.07
    score += _similar_number(left["bathrooms"], right["bathrooms"], 0.07, 0.01)
    weight += 0.07
    distance = _distance_m(left["latitude"], left["longitude"], right["latitude"], right["longitude"])
    weight += 0.08
    if distance is not None and distance <= 75:
        score += 0.08
    elif left["dedupe_fingerprint"] and left["dedupe_fingerprint"] == right["dedupe_fingerprint"]:
        score += 0.08
    return score / weight if weight else 0.0


def _similar_number(left, right, weight: float, tolerance: float) -> float:
    if left is None or right is None:
        return 0.0
    left_value = float(left)
    right_value = float(right)
    if left_value == right_value:
        return weight
    denominator = max(abs(left_value), abs(right_value), 1)
    return weight if abs(left_value - right_value) / denominator <= tolerance else 0.0


def _distance_m(lat1, lon1, lat2, lon2) -> float | None:
    if None in (lat1, lon1, lat2, lon2):
        return None
    radius = 6371000
    phi1 = math.radians(float(lat1))
    phi2 = math.radians(float(lat2))
    dphi = math.radians(float(lat2) - float(lat1))
    dlambda = math.radians(float(lon2) - float(lon1))
    a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return radius * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))


def _norm(value) -> str:
    if not value:
        return ""
    replacements = str.maketrans("áéíóúüñ", "aeiouun")
    return re.sub(r"[^a-z0-9]+", " ", str(value).lower().translate(replacements)).strip()


if __name__ == "__main__":
    raise SystemExit(main())
