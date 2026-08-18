from __future__ import annotations

import os
import time

import requests

from app.listings.geocoding.base import GeocodingProvider
from app.listings.geocoding.models import GeocodingQuery, GeocodingResult


class NominatimGeocodingProvider(GeocodingProvider):
    name = "nominatim"

    def __init__(self, endpoint: str | None = None, timeout: float | None = None, delay_seconds: float | None = None, user_agent: str | None = None):
        self.endpoint = endpoint or os.getenv("GEOCODING_NOMINATIM_URL", "https://nominatim.openstreetmap.org/search")
        self.timeout = timeout if timeout is not None else float(os.getenv("GEOCODING_TIMEOUT", "20"))
        self.delay_seconds = delay_seconds if delay_seconds is not None else float(os.getenv("GEOCODING_REQUEST_DELAY", "1"))
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": user_agent or os.getenv(
                "GEOCODING_USER_AGENT",
                "GrupoMundoAVMResearchBot/0.1 (+https://grupomundopatrimonial.com)",
            )
        })

    def geocode(self, query: GeocodingQuery) -> GeocodingResult:
        try:
            response = self.session.get(
                self.endpoint,
                params={
                    "q": query.query,
                    "format": "jsonv2",
                    "addressdetails": 1,
                    "limit": 1,
                    "countrycodes": "mx",
                },
                timeout=self.timeout,
            )
            if response.status_code in (403, 429) or response.status_code >= 500:
                return GeocodingResult(None, None, None, "unknown", 0.0, self.name, raw_response={
                    "status_code": response.status_code,
                    "message": response.text[:300],
                })
            payload = response.json()
        except Exception as exc:  # noqa: BLE001
            return GeocodingResult(None, None, None, "unknown", 0.0, self.name, raw_response={"error": str(exc)})
        finally:
            time.sleep(self.delay_seconds)

        if not payload:
            return GeocodingResult(None, None, None, "unknown", 0.0, self.name, raw_response={"results": []})

        item = payload[0]
        precision = infer_precision(item)
        confidence = infer_confidence(item, precision)
        return GeocodingResult(
            latitude=_float(item.get("lat")),
            longitude=_float(item.get("lon")),
            formatted_address=item.get("display_name"),
            precision=precision,
            confidence=confidence,
            provider=self.name,
            provider_place_id=str(item.get("place_id")) if item.get("place_id") is not None else None,
            raw_response={"result": item},
        )


def infer_precision(item: dict) -> str:
    osm_type = str(item.get("type") or "").lower()
    osm_class = str(item.get("class") or "").lower()
    addresstype = str(item.get("addresstype") or "").lower()
    address = item.get("address") if isinstance(item.get("address"), dict) else {}
    if address.get("house_number") and address.get("road"):
        return "exact_address"
    if addresstype in ("road", "street") or osm_type in ("residential", "road") or address.get("road"):
        return "street"
    if address.get("postcode") or addresstype == "postcode":
        return "postal_code"
    if addresstype in ("neighbourhood", "suburb", "quarter", "city_district") or any(address.get(k) for k in ("neighbourhood", "suburb", "quarter", "city_district")):
        return "neighborhood"
    if addresstype in ("town", "village", "city") or any(address.get(k) for k in ("town", "village", "city")):
        return "locality"
    if addresstype in ("municipality", "county") or any(address.get(k) for k in ("municipality", "county")):
        return "municipality"
    if addresstype == "state" or address.get("state"):
        return "state"
    return "unknown"


def infer_confidence(item: dict, precision: str) -> float:
    importance = item.get("importance")
    try:
        base = float(importance)
    except (TypeError, ValueError):
        base = 0.4
    precision_boost = {
        "exact_address": 0.35,
        "street": 0.25,
        "postal_code": 0.2,
        "neighborhood": 0.15,
        "locality": 0.05,
        "municipality": 0.0,
        "state": -0.1,
        "unknown": -0.2,
    }[precision]
    return max(0.0, min(1.0, base + precision_boost))


def _float(value: object) -> float | None:
    try:
        return float(value)
    except (TypeError, ValueError):
        return None

