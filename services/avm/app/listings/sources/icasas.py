from __future__ import annotations

import logging
import os
import re
import time
from html import unescape
from html.parser import HTMLParser
from urllib.parse import urljoin

import requests

from app.listings.deduplication import build_dedupe_fingerprint, quality_flags
from app.listings.models import NormalizedListing
from app.listings.normalizer import (
    canonical_url,
    clean_text,
    normalize_area_m2,
    normalize_float,
    normalize_int,
    normalize_operation,
    normalize_price,
    normalize_property_type,
    source_id_from_url,
)
from app.listings.sources.base import FetchedListing, ListingSource


logger = logging.getLogger(__name__)


CUAUTLA_SEEDS = [
    "https://www.icasas.mx/propiedad/ee0a-b979-23626059-5d1530ab947-324e",
    "https://www.icasas.mx/propiedad/5c0d-8193-19949a2-483e8e884c65-75aa",
    "https://www.icasas.mx/propiedad/7cd2-ad25-19660c6-e3bd4fedc667-7aaf",
    "https://www.icasas.mx/propiedad/fcd0-92ba-1970582-8c4c72646dde-75b4",
    "https://www.icasas.mx/propiedad/6369-bbfa-1919ada-ce0f037640e0-7095",
    "https://www.icasas.mx/propiedad/33cb-8482-96ccc302-d22afe11714e-4860",
    "https://www.icasas.mx/propiedad/f8f4-be34-1983583-c1bb10c157dd-7571",
    "https://www.icasas.mx/propiedad/d3f5-8478-196ae7e-e197764ef471-7f6f",
    "https://www.icasas.mx/propiedad/1253-9d2c-25ab4992-2d38a0ff2dc2-5050",
    "https://www.icasas.mx/propiedad/4766-9794-19a7936-5609f318d2c8-784b",
    "https://www.icasas.mx/propiedad/f86c-9ebd-195fdf5-81ed78370793-7a25",
    "https://www.icasas.mx/propiedad/e7a8-8930-ad15d8f8-bf5290d5adfc-34b5",
    "https://www.icasas.mx/propiedad/d331-972b-18fbd30-b0e08e01c396-73f4",
    "https://www.icasas.mx/propiedad/4b92-95f3-18ff51a-92368d6bcb49-7faa",
    "https://www.icasas.mx/propiedad/1b8b-b8c5-3ffc467c-cf934e169c33-325d",
    "https://www.icasas.mx/propiedad/250c-a77f-193abf4-b92bca358c0c-76fd",
    "https://www.icasas.mx/propiedad/2953-b932-19757e2-907d6d785a83-7bb3",
    "https://www.icasas.mx/propiedad/24e3-bb32-190e36e-e5230de5f36c-77f6",
    "https://www.icasas.mx/propiedad/d382-b5ad-19dc118-83a1c2ed860b-7738",
    "https://www.icasas.mx/propiedad/1a66-9fc1-1917371-e6d80237f422-75ff",
    "https://www.icasas.mx/propiedad/dc7c-9c2a-1983f15-6c506bf1be1-75a4",
    "https://www.icasas.mx/propiedad/769-a67e-e5a56a7b-df6721b17dda-4300",
    "https://www.icasas.mx/propiedad/2bcd-9ee2-1982ec8-82824cabbbf-784f",
    "https://www.icasas.mx/propiedad/ffb6-8ead-192dfa4-6e5038263a1-74ca",
    "https://www.icasas.mx/propiedad/ed25-9551-199a184-c67103fdb5e5-7311",
    "https://www.icasas.mx/propiedad/1c9e-ac23-19bcaf9-5c4977dcc4e4-7193",
    "https://www.icasas.mx/propiedad/b8a-90db-19d4a4e-e5338d13410f-79b8",
    "https://www.icasas.mx/propiedad/1700-a3f5-19245be-b02d35b96587-7911",
    "https://www.icasas.mx/propiedad/a5a6-9f48-fece3579-49ede523c371-3c16",
]


class _IcasasHtmlParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.links: list[str] = []
        self.meta: dict[str, str] = {}
        self.button_attrs: list[dict[str, str]] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = {key.lower(): value for key, value in attrs if value is not None}
        if tag == "a" and attributes.get("href"):
            self.links.append(attributes["href"])
        if tag == "meta":
            key = attributes.get("property") or attributes.get("name")
            content = attributes.get("content")
            if key and content:
                self.meta[key.lower()] = clean_text(unescape(content)) or ""
        if tag == "button":
            self.button_attrs.append(attributes)


class IcasasPublicSource(ListingSource):
    name = "icasas"
    base_url = "https://www.icasas.mx"

    def __init__(self, delay_seconds: float | None = None, timeout: float | None = None, user_agent: str | None = None) -> None:
        self.delay_seconds = delay_seconds if delay_seconds is not None else float(os.getenv("LISTING_REQUEST_DELAY", "2"))
        self.timeout = timeout if timeout is not None else float(os.getenv("LISTING_REQUEST_TIMEOUT", "20"))
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": user_agent or os.getenv("LISTING_USER_AGENT", "GrupoMundoAVMResearchBot/0.1"),
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "es-MX,es;q=0.9",
        })

    def discover(self, state: str, municipality: str, operation: str, max_pages: int, start_urls: list[str] | None = None) -> list[str]:
        urls = [canonical_url(url) for url in (start_urls or self._default_seeds(state, municipality, operation))]
        seen = dict.fromkeys(urls)
        for url in urls[:]:
            try:
                response = self.session.get(url, timeout=self.timeout)
            except requests.RequestException as exc:
                logger.warning("No se pudo inspeccionar seed iCasas %s: %s", url, exc)
                continue
            parser = _IcasasHtmlParser()
            parser.feed(response.text)
            for link in parser.links:
                absolute = canonical_url(urljoin(response.url, link))
                if "/propiedad/" in absolute:
                    seen.setdefault(absolute, None)
            time.sleep(self.delay_seconds)
            if len(seen) >= 30:
                break
        return list(seen.keys())

    def fetch(self, url: str) -> FetchedListing:
        canonical = canonical_url(url)
        try:
            response = self.session.get(canonical, timeout=self.timeout)
            status = response.status_code
            content = response.text
        except requests.RequestException as exc:
            logger.error("Descarga fallida de iCasas %s: %s", canonical, exc)
            status = None
            content = ""
        time.sleep(self.delay_seconds)
        return FetchedListing(self.name, source_id_from_url(canonical), canonical, status, content)

    def parse(self, fetched: FetchedListing, state: str | None = None, municipality: str | None = None) -> NormalizedListing:
        parser = _IcasasHtmlParser()
        parser.feed(fetched.raw_content or "")
        text = clean_text(unescape(re.sub(r"<[^>]+>", " ", fetched.raw_content or ""))) or ""
        title = self._first(parser.meta.get("og:title"), self._title(fetched.raw_content))
        description = self._first(parser.meta.get("og:description"), parser.meta.get("description"))
        price, currency = self._price(fetched.raw_content, text)
        location_raw = self._location_raw(fetched.raw_content, text)
        street, neighborhood, locality, state_value, postal_code = self._split_location(location_raw)
        latitude, longitude = self._coordinates(parser, fetched.raw_content)
        areas = self._areas(text, title or "", description or "")
        property_type = normalize_property_type(" ".join([title or "", fetched.url]))
        operation = "venta" if title and "venta" in title.lower() else normalize_operation(" ".join([title or "", text[:500], fetched.url]))
        if title and "anuncio no encontrado" in title.lower():
            operation = "desconocido"
        listing = NormalizedListing(
            source=fetched.source,
            source_id=fetched.source_id,
            url=fetched.url,
            title=title,
            property_type=property_type,
            operation=operation,
            price=price,
            currency=currency,
            latitude=latitude,
            longitude=longitude,
            state=state or state_value,
            municipality=municipality or locality,
            locality=locality,
            neighborhood=neighborhood,
            postal_code=postal_code,
            street=street,
            address_text=location_raw,
            location_raw=location_raw,
            land_area_m2=areas["land_area_m2"],
            construction_area_m2=areas["construction_area_m2"],
            generic_area_m2=areas["generic_area_m2"],
            land_area_source=areas["land_area_source"],
            construction_area_source=areas["construction_area_source"],
            generic_area_source=areas["generic_area_source"],
            bedrooms=self._labeled_int(text, ["recámaras", "recamaras"]),
            bathrooms=self._labeled_float(text, ["baños", "banos"]),
            parking_spaces=self._parking(" ".join([text, description or ""])),
            description=description,
            raw_data={"meta": parser.meta, "parser": "icasas_html_v1", "coordinate_source": "button_data_xy" if latitude and longitude else None},
        )
        if listing.property_type == "terreno" and listing.land_area_m2 is None and listing.generic_area_m2 is not None:
            listing.land_area_m2 = listing.generic_area_m2
            listing.land_area_source = listing.generic_area_source
            listing.generic_area_m2 = None
            listing.generic_area_source = None
        listing.dedupe_fingerprint = build_dedupe_fingerprint(listing)
        listing.quality_flags = quality_flags(listing)
        return listing

    def _default_seeds(self, state: str, municipality: str, operation: str) -> list[str]:
        if state.lower() == "morelos" and municipality.lower() == "cuautla" and operation == "venta":
            return CUAUTLA_SEEDS
        return []

    def _price(self, html: str, text: str) -> tuple[float | None, str | None]:
        match = re.search(r'<p class="price">([^<]+)</p>', html, flags=re.I)
        if match:
            return normalize_price(match.group(1).replace("MX$", "MXN"))
        return normalize_price(text)

    def _coordinates(self, parser: _IcasasHtmlParser, html: str) -> tuple[float | None, float | None]:
        for attrs in parser.button_attrs:
            if attrs.get("data-x") and attrs.get("data-y"):
                return normalize_float(attrs["data-x"]), normalize_float(attrs["data-y"])
        match = re.search(r'data-x="(-?\d+(?:\.\d+)?)"\s+data-y="(-?\d+(?:\.\d+)?)"', html)
        if match:
            return normalize_float(match.group(1)), normalize_float(match.group(2))
        return None, None

    def _location_raw(self, html: str, text: str) -> str | None:
        match = re.search(r'<span[^>]*itemprop="address"[^>]*>\s*(?:Localizaci[oó]n:\s*)?([^<]+)</span>|Localizaci[oó]n:\s*([^<\\n]+)', html, flags=re.I)
        if match:
            return clean_text(unescape(match.group(1) or match.group(2)))
        match = re.search(r"Localizaci[oó]n:\s*([^<]+)", html, flags=re.I)
        if match:
            return clean_text(unescape(match.group(1)))
        match = re.search(r"Localizaci[oó]n:\s*([^¿]+?)(?:Ver mapa|Contactar|$)", text, flags=re.I)
        return clean_text(match.group(1)) if match else None

    def _split_location(self, location: str | None) -> tuple[str | None, str | None, str | None, str | None, str | None]:
        if not location:
            return None, None, None, None, None
        parts = [clean_text(part) for part in location.split(",") if clean_text(part)]
        postal_code = None
        for part in parts:
            match = re.search(r"\b\d{5}\b", part)
            if match:
                postal_code = match.group(0)
        state = next((part for part in parts if part and part.lower() in ("morelos", "mor.")), None)
        locality = next((part for part in parts if part and "cuautla" in part.lower()), None)
        street = parts[0] if parts else None
        has_street_signal = bool(street and re.search(r"\d|calle|avenida|av\.|calzada|camino|cerrada|privada|boulevard", street, flags=re.I))
        neighborhood = None
        for index, part in enumerate(parts):
            lower = part.lower()
            if lower.startswith("col."):
                neighborhood = clean_text(re.sub(r"^col\.\s*", "", part, flags=re.I))
            elif neighborhood is None and (index != 0 or not has_street_signal) and part not in (state, locality) and not re.search(r"\b\d{5}\b|mex", lower):
                neighborhood = part
        if street == neighborhood or street == locality or (street and street.lower().startswith("col.")):
            street = None
        return street, neighborhood, locality, state, postal_code

    def _areas(self, text: str, title: str, description: str) -> dict:
        evidence_text = " ".join([description, text])
        land = self._area(evidence_text, [r"terreno\D{0,25}([0-9][0-9,.]*)\s*m2", r"superficie total\D{0,25}([0-9][0-9,.]*)\s*m2"])
        construction = self._area(evidence_text, [r"construcci[oó]n\D{0,25}([0-9][0-9,.]*)\s*m2", r"superficie construida\D{0,25}([0-9][0-9,.]*)\s*m2"])
        generic = None if land or construction else self._area(text, [r"([0-9][0-9,.]*)\s*m2"])
        return {
            "land_area_m2": land,
            "construction_area_m2": construction,
            "generic_area_m2": generic,
            "land_area_source": "description" if land else None,
            "construction_area_source": "description" if construction else None,
            "generic_area_source": "visible_label" if generic else None,
        }

    def _area(self, text: str, patterns: list[str]) -> float | None:
        for pattern in patterns:
            match = re.search(pattern, text, flags=re.I)
            if match:
                return normalize_area_m2(match.group(1))
        return None

    def _labeled_int(self, text: str, labels: list[str]) -> int | None:
        for label in labels:
            match = re.search(rf"([0-9]+)\s+{label}|{label}\D{{0,20}}([0-9]+)", text, flags=re.I)
            if match:
                return normalize_int(match.group(1) or match.group(2))
        return None

    def _labeled_float(self, text: str, labels: list[str]) -> float | None:
        for label in labels:
            match = re.search(rf"([0-9]+(?:[,.][0-9]+)?)\s+{label}|{label}\D{{0,20}}([0-9]+(?:[,.][0-9]+)?)", text, flags=re.I)
            if match:
                return normalize_float(match.group(1) or match.group(2))
        return None

    def _parking(self, text: str) -> int | None:
        match = re.search(r"([0-9]+)\s+(?:estacionamientos|lugares de estacionamiento|autos)", text, flags=re.I)
        return normalize_int(match.group(1)) if match else None

    def _title(self, html: str) -> str | None:
        match = re.search(r"<title[^>]*>(.*?)</title>", html or "", flags=re.I | re.S)
        return clean_text(unescape(re.sub(r"<[^>]+>", " ", match.group(1)))) if match else None

    def _first(self, *values: str | None) -> str | None:
        for value in values:
            cleaned = clean_text(value)
            if cleaned:
                return cleaned
        return None
