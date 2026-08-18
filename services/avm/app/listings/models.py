from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from typing import Any


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


@dataclass
class RawListing:
    source: str
    source_id: str
    url: str
    http_status: int | None
    raw_content: str
    captured_at: str = field(default_factory=utc_now_iso)


@dataclass
class NormalizedListing:
    source: str
    source_id: str
    url: str
    title: str | None = None
    property_type: str | None = None
    operation: str = "desconocido"
    price: float | None = None
    currency: str | None = None
    latitude: float | None = None
    longitude: float | None = None
    state: str | None = None
    municipality: str | None = None
    locality: str | None = None
    neighborhood: str | None = None
    postal_code: str | None = None
    street: str | None = None
    address_text: str | None = None
    location_raw: str | None = None
    land_area_m2: float | None = None
    construction_area_m2: float | None = None
    generic_area_m2: float | None = None
    land_area_source: str | None = None
    construction_area_source: str | None = None
    generic_area_source: str | None = None
    bedrooms: int | None = None
    bathrooms: float | None = None
    parking_spaces: int | None = None
    age_years: int | None = None
    description: str | None = None
    published_at: str | None = None
    captured_at: str = field(default_factory=utc_now_iso)
    last_seen_at: str = field(default_factory=utc_now_iso)
    raw_data: dict[str, Any] = field(default_factory=dict)
    dedupe_fingerprint: str | None = None
    quality_flags: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)
