from __future__ import annotations

import re
import sqlite3

from app.listings.spatial.coordinates import valid_morelos_coordinate


def validate_coordinate(row: sqlite3.Row, reverse_payload: dict | None, shared_count: int, nearby_count: int) -> tuple[str, str, str]:
    lat = row["latitude"]
    lng = row["longitude"]
    if not valid_morelos_coordinate(lat, lng):
        return "inconsistent", "coordenada fuera del rango esperado de Morelos", "unusable"
    if not reverse_payload or reverse_payload.get("error"):
        if shared_count > 1 or nearby_count > 1:
            return "unknown", "sin reverse geocoding; coordenada compartida o muy cercana a otros listings", "low"
        return "unknown", "sin reverse geocoding; coordenada única no verificable", "medium"

    address = reverse_payload.get("address") if isinstance(reverse_payload.get("address"), dict) else {}
    municipality_ok = _contains_any(address, ["city", "town", "village", "municipality", "county"], row["municipality"])
    state_ok = _contains_any(address, ["state"], row["state"])
    neighborhood_ok = _contains_any(address, ["neighbourhood", "suburb", "quarter", "city_district", "residential"], row["neighborhood"])
    postcode_ok = bool(row["postal_code"] and str(row["postal_code"]) == str(address.get("postcode")))
    street_ok = _street_matches(address.get("road"), row["street"])

    if not state_ok:
        return "inconsistent", "reverse geocoding no confirma Morelos", "unusable"
    if not municipality_ok:
        return "inconsistent", "reverse geocoding no confirma municipio", "unusable"

    evidence = []
    if street_ok:
        evidence.append("calle coincide")
    if postcode_ok:
        evidence.append("CP coincide")
    if neighborhood_ok:
        evidence.append("colonia/zona coincide")
    if municipality_ok:
        evidence.append("municipio coincide")

    if (street_ok or postcode_ok or neighborhood_ok) and shared_count == 1 and nearby_count == 1:
        return "consistent", ", ".join(evidence), "high"
    if municipality_ok and shared_count == 1:
        return "partially_consistent", ", ".join(evidence) or "municipio coincide; colonia/calle no verificable", "medium"
    if municipality_ok and shared_count > 1:
        return "partially_consistent", "municipio coincide; coordenada compartida por varias propiedades", "low"
    return "unknown", "sin evidencia suficiente", "unusable"


def _contains_any(address: dict, keys: list[str], expected: object) -> bool:
    if not expected:
        return False
    expected_norm = _norm(str(expected))
    for key in keys:
        value = address.get(key)
        if value and (_norm(str(value)) in expected_norm or expected_norm in _norm(str(value))):
            return True
    return False


def _street_matches(road: object, street: object) -> bool:
    if not road or not street:
        return False
    road_norm = _norm(str(road))
    street_norm = _norm(str(street))
    tokens = [token for token in re.split(r"\s+", street_norm) if len(token) > 3 and not token.isdigit()]
    return bool(tokens) and any(token in road_norm for token in tokens)


def _norm(value: str) -> str:
    replacements = str.maketrans("áéíóúüñ", "aeiouun")
    return re.sub(r"[^a-z0-9 ]+", " ", value.lower().translate(replacements)).strip()

