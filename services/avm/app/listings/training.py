from __future__ import annotations

import json
from typing import Any, Mapping


def price_per_construction_m2(row: Mapping[str, Any]) -> float | None:
    return _price_per_m2(_get(row, "price"), _get(row, "construction_area_m2"))


def price_per_land_m2(row: Mapping[str, Any]) -> float | None:
    if _get(row, "property_type") != "terreno":
        return None
    return _price_per_m2(_get(row, "price"), _get(row, "land_area_m2"))


def market_segment(row: Mapping[str, Any]) -> str | None:
    property_type = _get(row, "property_type")
    if property_type in {"casa", "departamento"}:
        return "residential"
    if property_type == "terreno":
        return "land"
    return None


def price_band(row: Mapping[str, Any]) -> str | None:
    price = _float(_get(row, "price"))
    if price is None or price <= 0:
        return None
    if price < 1_000_000:
        return "<1M"
    if price < 2_000_000:
        return "1M-2M"
    if price < 3_000_000:
        return "2M-3M"
    if price < 5_000_000:
        return "3M-5M"
    if price < 8_000_000:
        return "5M-8M"
    if price < 12_000_000:
        return "8M-12M"
    if price < 20_000_000:
        return "12M-20M"
    return ">20M"


def training_readiness(row: Mapping[str, Any]) -> str:
    has_price = _positive(_get(row, "price"))
    has_type = _get(row, "property_type") in {"casa", "departamento", "terreno"}
    has_ageb = bool(_get(row, "inegi_cve_ageb"))
    has_censo = _get(row, "population_density") is not None and _get(row, "housing_density") is not None
    has_denue = _get(row, "establishments_500m") is not None and _get(row, "establishments_1km") is not None
    has_land = _positive(_get(row, "land_area_m2"))
    has_construction = _positive(_get(row, "construction_area_m2"))
    has_rooms = _get(row, "bedrooms") is not None
    has_baths = _get(row, "bathrooms") is not None
    quality = _get(row, "coordinate_quality")

    complete_features = has_price and has_type and has_ageb and has_censo and has_denue
    if _get(row, "property_type") == "terreno":
        complete_property = has_land
        sufficient_property = has_land
    elif _get(row, "property_type") == "departamento":
        complete_property = has_construction and has_rooms and has_baths
        sufficient_property = has_construction and (has_rooms or has_baths)
    else:
        complete_property = has_land and has_construction and has_rooms and has_baths
        sufficient_property = (has_land or has_construction) and (has_rooms or has_baths)

    if complete_features and complete_property and quality == "high":
        return "A"
    if complete_features and complete_property and quality == "medium":
        return "B"
    if complete_features and sufficient_property and _usable_location(row):
        return "C"
    if has_price and has_type and _usable_location(row):
        return "D"
    return "E"


def enriched_quality_flags(row: Mapping[str, Any]) -> list[str]:
    flags = _json_flags(_get(row, "quality_flags_json"))
    price = _float(_get(row, "price"))
    land = _float(_get(row, "land_area_m2"))
    construction = _float(_get(row, "construction_area_m2"))
    bedrooms = _float(_get(row, "bedrooms"))
    bathrooms = _float(_get(row, "bathrooms"))

    if price is not None and price <= 0:
        flags.append("invalid_price")
    if land is not None and land <= 0:
        flags.append("invalid_land_area")
    if construction is not None and construction <= 0:
        flags.append("invalid_construction_area")
    if land is not None and land > 100000:
        flags.append("suspicious_land_area")
    if construction is not None and construction > 5000:
        flags.append("suspicious_construction_area")
    if bedrooms is not None and bedrooms > 20:
        flags.append("suspicious_bedrooms")
    if bathrooms is not None and bathrooms > 20:
        flags.append("suspicious_bathrooms")

    construction_m2 = price_per_construction_m2(row)
    land_m2 = price_per_land_m2(row)
    if construction_m2 is not None and (construction_m2 < 1000 or construction_m2 > 200000):
        flags.append("suspicious_price_per_construction_m2")
    if land_m2 is not None and (land_m2 < 100 or land_m2 > 100000):
        flags.append("suspicious_price_per_land_m2")

    return sorted(set(flags))


def _usable_location(row: Mapping[str, Any]) -> bool:
    if _get(row, "coordinate_quality") in {"high", "medium"}:
        return True
    return bool(_get(row, "latitude") and _get(row, "longitude"))


def _price_per_m2(price: Any, area: Any) -> float | None:
    price_value = _float(price)
    area_value = _float(area)
    if price_value is None or area_value is None or price_value <= 0 or area_value <= 0:
        return None
    return round(price_value / area_value, 2)


def _positive(value: Any) -> bool:
    parsed = _float(value)
    return parsed is not None and parsed > 0


def _float(value: Any) -> float | None:
    if value in (None, ""):
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _json_flags(value: Any) -> list[str]:
    if not value:
        return []
    if isinstance(value, list):
        return [str(item) for item in value]
    try:
        decoded = json.loads(str(value))
    except json.JSONDecodeError:
        return []
    return [str(item) for item in decoded] if isinstance(decoded, list) else []


def _get(row: Mapping[str, Any], key: str) -> Any:
    if hasattr(row, "keys") and key not in row.keys():
        return None
    try:
        return row[key]
    except (KeyError, TypeError):
        return None
