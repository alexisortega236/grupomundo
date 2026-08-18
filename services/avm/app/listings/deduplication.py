from __future__ import annotations

import hashlib
import json

from app.listings.models import NormalizedListing


def build_dedupe_fingerprint(listing: NormalizedListing) -> str | None:
    payload = {
        "latitude": _round(listing.latitude, 5),
        "longitude": _round(listing.longitude, 5),
        "price": _round(listing.price, 0),
        "land_area_m2": _round(listing.land_area_m2, 1),
        "construction_area_m2": _round(listing.construction_area_m2, 1),
        "bedrooms": listing.bedrooms,
        "bathrooms": listing.bathrooms,
        "parking_spaces": listing.parking_spaces,
    }
    compact = {key: value for key, value in payload.items() if value is not None}
    if len(compact) < 3:
        return None
    encoded = json.dumps(compact, sort_keys=True, separators=(",", ":"))
    return hashlib.sha1(encoded.encode("utf-8")).hexdigest()


def quality_flags(listing: NormalizedListing) -> list[str]:
    flags: list[str] = []
    if listing.latitude is None or listing.longitude is None:
        flags.append("missing_coordinates")
    if listing.price is None:
        flags.append("missing_price")
    elif listing.price <= 0:
        flags.append("invalid_price")
    if listing.construction_area_m2 is None:
        flags.append("missing_construction_area")
    elif listing.construction_area_m2 <= 0:
        flags.append("invalid_construction_area")
    elif listing.construction_area_m2 > 5000:
        flags.append("suspicious_construction_area")
    if listing.land_area_m2 is None:
        flags.append("missing_land_area")
    elif listing.land_area_m2 <= 0:
        flags.append("invalid_land_area")
    elif listing.land_area_m2 > 100000:
        flags.append("suspicious_land_area")
    if listing.bedrooms is not None and listing.bedrooms > 20:
        flags.append("suspicious_bedrooms")
    if listing.bathrooms is not None and listing.bathrooms > 20:
        flags.append("suspicious_bathrooms")
    if listing.land_area_m2 is not None and listing.construction_area_m2 is not None and listing.land_area_m2 == listing.construction_area_m2:
        flags.append("same_land_and_construction_area")
    if listing.generic_area_m2 is not None and listing.land_area_m2 is None and listing.construction_area_m2 is None:
        flags.append("generic_area_only")
    if (
        listing.generic_area_m2 is not None
        and (listing.land_area_m2 is not None or listing.construction_area_m2 is not None)
    ):
        flags.append("suspicious_area_assignment")
    if listing.generic_area_m2 is not None:
        flags.append("ambiguous_area")
    if listing.land_area_m2 is not None and listing.construction_area_m2 is not None:
        ratio = listing.construction_area_m2 / listing.land_area_m2 if listing.land_area_m2 else None
        if ratio is not None and (ratio < 0.15 or ratio > 4):
            flags.append("suspicious_surface_pair")
    if not listing.location_raw and not listing.address_text and not listing.neighborhood:
        flags.append("missing_location")
    if listing.price and listing.construction_area_m2 and listing.construction_area_m2 > 0:
        price_m2 = listing.price / listing.construction_area_m2
        if price_m2 < 1000 or price_m2 > 200000:
            flags.append("suspicious_price_m2")
    return flags


def _round(value: float | None, places: int) -> float | None:
    if value is None:
        return None
    return round(float(value), places)
