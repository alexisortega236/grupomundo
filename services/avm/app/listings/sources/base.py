from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass

from app.listings.models import NormalizedListing


@dataclass
class FetchedListing:
    source: str
    source_id: str
    url: str
    http_status: int | None
    raw_content: str


class ListingSource(ABC):
    name: str

    @abstractmethod
    def discover(self, state: str, municipality: str, operation: str, max_pages: int, start_urls: list[str] | None = None) -> list[str]:
        raise NotImplementedError

    @abstractmethod
    def fetch(self, url: str) -> FetchedListing:
        raise NotImplementedError

    @abstractmethod
    def parse(self, fetched: FetchedListing, state: str | None = None, municipality: str | None = None) -> NormalizedListing:
        raise NotImplementedError
