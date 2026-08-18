from __future__ import annotations

from abc import ABC, abstractmethod

from app.listings.geocoding.models import GeocodingQuery, GeocodingResult


class GeocodingProvider(ABC):
    name: str

    @abstractmethod
    def geocode(self, query: GeocodingQuery) -> GeocodingResult:
        raise NotImplementedError

