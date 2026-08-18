from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


PRECISIONS = {
    "exact_address",
    "street",
    "postal_code",
    "neighborhood",
    "locality",
    "municipality",
    "state",
    "unknown",
}


@dataclass
class GeocodingQuery:
    query: str
    normalized_query: str
    query_hash: str


@dataclass
class GeocodingResult:
    latitude: float | None
    longitude: float | None
    formatted_address: str | None
    precision: str = "unknown"
    confidence: float = 0.0
    provider: str | None = None
    provider_place_id: str | None = None
    raw_response: dict[str, Any] = field(default_factory=dict)

    @property
    def found(self) -> bool:
        return self.latitude is not None and self.longitude is not None and self.precision != "unknown"


def geocode_usability(precision: str | None) -> str:
    if precision in ("exact_address", "street"):
        return "high"
    if precision in ("postal_code", "neighborhood"):
        return "medium"
    if precision == "locality":
        return "low"
    return "unusable"

