from __future__ import annotations

import hashlib
import json
import re
import sqlite3
from pathlib import Path
from typing import Any

from app.listings.geocoding.base import GeocodingProvider
from app.listings.geocoding.models import GeocodingQuery, GeocodingResult, geocode_usability
from app.listings.models import utc_now_iso
from app.listings.storage import ListingStorage


class GeocodingService:
    def __init__(self, storage: ListingStorage, provider: GeocodingProvider):
        self.storage = storage
        self.provider = provider
        self.storage.initialize_geocoding()

    def build_query_from_row(self, row: sqlite3.Row) -> GeocodingQuery | None:
        parts = []
        original_location = " ".join([str(row[field] or "") for field in ("address_text", "location_raw", "neighborhood") if field in row.keys()])
        for field in ("address_text", "neighborhood", "locality", "municipality", "state"):
            value = row[field] if field in row.keys() else None
            self._append_unique_part(parts, value)
        postal_code = row["postal_code"] if "postal_code" in row.keys() else None
        if postal_code and str(postal_code) in original_location:
            self._append_unique_part(parts, postal_code)
        if "México" not in parts and "Mexico" not in parts:
            parts.append("México")
        query = ", ".join(parts)
        normalized = normalize_geocoding_query(query)
        if not normalized:
            return None
        return GeocodingQuery(
            query=query,
            normalized_query=normalized,
            query_hash=hashlib.sha256(normalized.encode("utf-8")).hexdigest(),
        )

    def _append_unique_part(self, parts: list[str], value: object) -> None:
        if not value or not str(value).strip():
            return
        text = str(value).strip()
        normalized = normalize_geocoding_query(text)
        existing = [normalize_geocoding_query(part) for part in parts]
        if normalized in existing:
            return
        if any(normalized in item or item in normalized for item in existing):
            return
        parts.append(text)

    def geocode_listing(self, row: sqlite3.Row, force: bool = False) -> tuple[GeocodingResult | None, bool]:
        query = self.build_query_from_row(row)
        if query is None:
            return None, False
        cached = self.storage.get_geocode_cache(query.query_hash)
        if cached and not force:
            result = result_from_cache(cached)
            result = self._cap_result_precision(row, result)
            self.storage.apply_geocode_result(row["id"], query, result)
            return result, False
        result = self.provider.geocode(query)
        result = self._cap_result_precision(row, result)
        self.storage.save_geocode_cache(query, result)
        self.storage.apply_geocode_result(row["id"], query, result)
        return result, True

    def _cap_result_precision(self, row: sqlite3.Row, result: GeocodingResult) -> GeocodingResult:
        if result.precision in ("unknown", "state", "municipality", "locality"):
            return result
        has_street_input = bool(row["street"] if "street" in row.keys() else None)
        original_location = " ".join([str(row[field] or "") for field in ("address_text", "location_raw", "neighborhood") if field in row.keys()])
        postal_code = row["postal_code"] if "postal_code" in row.keys() else None
        has_postal_input = bool(postal_code and str(postal_code) in original_location)
        has_neighborhood_input = bool(row["neighborhood"] if "neighborhood" in row.keys() else None)
        if result.precision in ("exact_address", "street") and not has_street_input:
            result.precision = "postal_code" if has_postal_input else ("neighborhood" if has_neighborhood_input else "locality")
            result.confidence = min(result.confidence, 0.65 if result.precision == "neighborhood" else 0.55)
        if result.precision == "postal_code" and not has_postal_input:
            result.precision = "neighborhood" if has_neighborhood_input else "locality"
            result.confidence = min(result.confidence, 0.65 if result.precision == "neighborhood" else 0.55)
        return result


def normalize_geocoding_query(query: str | None) -> str:
    if not query:
        return ""
    text = query.lower().strip()
    replacements = str.maketrans("áéíóúüñ", "aeiouun")
    text = text.translate(replacements)
    text = re.sub(r"\s+", " ", text)
    text = re.sub(r"\s*,\s*", ", ", text)
    text = re.sub(r"[^a-z0-9, .-]", "", text)
    return text.strip(" ,")


def result_from_cache(row: sqlite3.Row) -> GeocodingResult:
    return GeocodingResult(
        latitude=row["latitude"],
        longitude=row["longitude"],
        formatted_address=row["formatted_address"],
        precision=row["precision"] or "unknown",
        confidence=float(row["confidence"] or 0),
        provider=row["provider"],
        provider_place_id=row["provider_place_id"],
        raw_response=json.loads(row["raw_response_json"] or "{}"),
    )
