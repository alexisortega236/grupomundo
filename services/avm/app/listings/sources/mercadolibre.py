from __future__ import annotations

import json
import logging
import os
import re
import time
from html import unescape
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit

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


PROPERTY_TYPE_PATHS = {
    "casa": "casas",
    "departamento": "departamentos",
    "terreno": "terrenos",
}


class _MercadoLibreHtmlParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.links: list[str] = []
        self.meta: dict[str, str] = {}
        self.scripts: list[tuple[str, str]] = []
        self._script_type = ""
        self._script_data = ""
        self._in_script = False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = {key.lower(): value for key, value in attrs if value is not None}
        if tag == "a" and attributes.get("href"):
            self.links.append(attributes["href"])
        if tag == "meta":
            key = attributes.get("property") or attributes.get("name")
            content = attributes.get("content")
            if key and content:
                self.meta[key.lower()] = clean_text(unescape(content)) or ""
        if tag == "script":
            self._in_script = True
            self._script_type = attributes.get("type", "")
            self._script_data = ""

    def handle_data(self, data: str) -> None:
        if self._in_script:
            self._script_data += data

    def handle_endtag(self, tag: str) -> None:
        if tag == "script" and self._in_script:
            self.scripts.append((self._script_type, self._script_data))
            self._in_script = False


class MercadoLibrePublicSource(ListingSource):
    name = "mercadolibre"
    base_url = "https://inmuebles.mercadolibre.com.mx"

    def __init__(
        self,
        property_types: list[str] | None = None,
        delay_seconds: float | None = None,
        timeout: float | None = None,
        user_agent: str | None = None,
    ) -> None:
        self.property_types = property_types or ["casa", "departamento", "terreno"]
        self.delay_seconds = delay_seconds if delay_seconds is not None else float(os.getenv("LISTING_REQUEST_DELAY", "2"))
        self.timeout = timeout if timeout is not None else float(os.getenv("LISTING_REQUEST_TIMEOUT", "20"))
        self.session = requests.Session()
        self.last_discovery_audit: list[dict] = []
        self.session.headers.update({
            "User-Agent": user_agent or os.getenv("LISTING_USER_AGENT", "GrupoMundoAVMResearchBot/0.1"),
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "es-MX,es;q=0.9",
        })

    def discover(
        self,
        state: str,
        municipality: str,
        operation: str,
        max_pages: int,
        start_urls: list[str] | None = None,
        neighborhood: str | None = None,
        audit_sink: list[dict] | None = None,
    ) -> list[str]:
        urls: list[str] = []
        if audit_sink is None:
            audit_sink = []
        self.last_discovery_audit = audit_sink
        targets = (
            [(url, None, None) for url in start_urls]
            if start_urls
            else self._search_targets(state, municipality, operation, max_pages, neighborhood)
        )
        seen_ids: set[str] = set()
        for search_url, property_type, page in targets:
            offset = ((page * 48) + 1) if page is not None else None
            audit = {
                "state": state,
                "municipality": municipality,
                "neighborhood": neighborhood,
                "property_type": property_type,
                "page": page,
                "offset": offset,
                "url": search_url,
                "status_http": None,
                "response_url": None,
                "links_found": 0,
                "ids_found": 0,
                "ids_unique": 0,
                "duplicates_discarded": 0,
            }
            try:
                response = self.session.get(search_url, timeout=self.timeout)
            except requests.RequestException as exc:
                logger.warning("No se pudo inspeccionar Mercado Libre %s: %s", search_url, exc)
                audit["error"] = str(exc)
                if audit_sink is not None:
                    audit_sink.append(audit)
                continue
            audit["status_http"] = response.status_code
            audit["response_url"] = response.url
            parser = _MercadoLibreHtmlParser()
            parser.feed(response.text)
            page_ids: set[str] = set()
            page_duplicates = 0
            for link in parser.links:
                absolute = canonical_url(urljoin(response.url, link))
                if not self._is_detail_url(absolute):
                    continue
                audit["links_found"] += 1
                source_id = self._source_id(absolute)
                if source_id in page_ids or source_id in seen_ids:
                    page_duplicates += 1
                    continue
                page_ids.add(source_id)
                seen_ids.add(source_id)
                urls.append(absolute)
            audit["ids_found"] = audit["links_found"]
            audit["ids_unique"] = len(page_ids)
            audit["duplicates_discarded"] = page_duplicates
            if audit_sink is not None:
                audit_sink.append(audit)
            logger.info(
                "Mercado Libre discovery municipality=%s neighborhood=%s type=%s page=%s status=%s links=%s unique=%s duplicates=%s",
                municipality,
                neighborhood or "",
                property_type or "",
                page,
                response.status_code,
                audit["links_found"],
                audit["ids_unique"],
                page_duplicates,
            )
            time.sleep(self.delay_seconds)
        return urls

    def fetch(self, url: str) -> FetchedListing:
        canonical = canonical_url(url)
        try:
            response = self.session.get(canonical, timeout=self.timeout)
            status = response.status_code
            content = response.text
        except requests.RequestException as exc:
            logger.error("Descarga fallida de Mercado Libre %s: %s", canonical, exc)
            status = None
            content = ""
        time.sleep(self.delay_seconds)
        return FetchedListing(self.name, self._source_id(canonical), canonical, status, content)

    def parse(self, fetched: FetchedListing, state: str | None = None, municipality: str | None = None) -> NormalizedListing:
        parser = _MercadoLibreHtmlParser()
        parser.feed(fetched.raw_content or "")
        text = clean_text(unescape(re.sub(r"<[^>]+>", " ", fetched.raw_content or ""))) or ""
        jsonld = self._jsonld(parser)
        product = self._first_jsonld(jsonld, {"Product", "House", "Apartment", "Residence"}) or {}
        breadcrumbs = self._breadcrumbs(jsonld)
        title = self._first(product.get("name"), parser.meta.get("og:title"), self._title(fetched.raw_content))
        price, currency = self._price(product, text)
        areas = self._areas(text, title or "", fetched.url)
        property_type = self._property_type(title or "", breadcrumbs, fetched.url)
        operation = normalize_operation(" ".join([title or "", fetched.url, *breadcrumbs, text[:500]]))
        location = self._location(breadcrumbs, text, state, municipality)
        bedrooms = self._feature_int(text, ["Recámaras", "Recamaras", "rec."])
        bathrooms = self._feature_float(text, ["Baños", "banos"])
        parking_spaces = self._feature_int(text, ["Estacionamientos", "estac."])
        latitude, longitude = self._coordinates(fetched.raw_content)
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
            state=state or location["state"],
            municipality=municipality or location["municipality"],
            locality=location["municipality"],
            neighborhood=location["neighborhood"],
            postal_code=location["postal_code"],
            street=location["street"],
            address_text=location["address_text"],
            location_raw=location["address_text"],
            land_area_m2=areas["land_area_m2"],
            construction_area_m2=areas["construction_area_m2"],
            generic_area_m2=areas["generic_area_m2"],
            land_area_source=areas["land_area_source"],
            construction_area_source=areas["construction_area_source"],
            generic_area_source=areas["generic_area_source"],
            bedrooms=bedrooms,
            bathrooms=bathrooms,
            parking_spaces=parking_spaces,
            description=self._first(product.get("description"), parser.meta.get("og:description")),
            raw_data={
                "parser": "mercadolibre_html_v1",
                "jsonld_types": [item.get("@type") for item in jsonld if isinstance(item, dict)],
                "breadcrumbs": breadcrumbs,
                "area_evidence": areas["evidence"],
            },
        )
        if listing.property_type == "terreno" and listing.land_area_m2 is None and listing.generic_area_m2 is not None:
            listing.land_area_m2 = listing.generic_area_m2
            listing.land_area_source = listing.generic_area_source
            listing.generic_area_m2 = None
            listing.generic_area_source = None
        listing.dedupe_fingerprint = build_dedupe_fingerprint(listing)
        listing.quality_flags = quality_flags(listing)
        return listing

    def _search_urls(
        self,
        state: str,
        municipality: str,
        operation: str,
        max_pages: int,
        neighborhood: str | None = None,
    ) -> list[str]:
        return [url for url, _, _ in self._search_targets(state, municipality, operation, max_pages, neighborhood)]

    def _search_targets(
        self,
        state: str,
        municipality: str,
        operation: str,
        max_pages: int,
        neighborhood: str | None = None,
    ) -> list[tuple[str, str, int]]:
        if operation != "venta":
            return []
        state_slug = self._slug(state)
        municipality_slug = self._slug(municipality)
        neighborhood_slug = self._slug(neighborhood) if neighborhood else None
        urls: list[tuple[str, str, int]] = []
        for property_type in self.property_types:
            path = PROPERTY_TYPE_PATHS.get(property_type)
            if not path:
                continue
            location = f"{state_slug}/{municipality_slug}"
            if neighborhood_slug:
                location = f"{location}/{neighborhood_slug}"
            base = f"{self.base_url}/{path}/venta/{location}/"
            for page in range(max(1, max_pages)):
                if page == 0:
                    urls.append((base, property_type, page))
                else:
                    urls.append((f"{base}_Desde_{(page * 48) + 1}", property_type, page))
        return urls

    def _is_detail_url(self, url: str) -> bool:
        parsed = urlsplit(url)
        return parsed.netloc.endswith("mercadolibre.com.mx") and bool(re.search(r"/MLM-\d+", parsed.path))

    def _source_id(self, url: str) -> str:
        match = re.search(r"(MLM-\d+)", url)
        return match.group(1) if match else source_id_from_url(url)

    def _jsonld(self, parser: _MercadoLibreHtmlParser) -> list[dict]:
        objects: list[dict] = []
        for script_type, content in parser.scripts:
            if "ld+json" not in script_type:
                continue
            try:
                decoded = json.loads(content)
            except json.JSONDecodeError:
                continue
            if isinstance(decoded, list):
                objects.extend([item for item in decoded if isinstance(item, dict)])
            elif isinstance(decoded, dict):
                objects.append(decoded)
        return objects

    def _first_jsonld(self, jsonld: list[dict], types: set[str]) -> dict | None:
        for item in jsonld:
            item_type = item.get("@type")
            values = set(item_type) if isinstance(item_type, list) else {item_type}
            if values & types:
                return item
        return None

    def _breadcrumbs(self, jsonld: list[dict]) -> list[str]:
        for item in jsonld:
            if item.get("@type") != "BreadcrumbList":
                continue
            names = []
            for element in item.get("itemListElement", []):
                value = element.get("item", {}) if isinstance(element, dict) else {}
                name = value.get("name") if isinstance(value, dict) else None
                if name:
                    names.append(clean_text(name) or "")
            return [name for name in names if name]
        return []

    def _price(self, product: dict, text: str) -> tuple[float | None, str | None]:
        offers = product.get("offers") if isinstance(product.get("offers"), dict) else {}
        if offers.get("price") is not None:
            currency = clean_text(offers.get("priceCurrency")) or "MXN"
            return normalize_float(offers.get("price")), currency.upper()
        return normalize_price(text)

    def _areas(self, text: str, title: str, url: str) -> dict:
        land = self._area_by_label(text, [
            r"Superficie total\s*([0-9][0-9,.]*)\s*m(?:²|2)",
            r"Superficie de terreno\s*([0-9][0-9,.]*)\s*m(?:²|2)",
            r"Terreno\s*([0-9][0-9,.]*)\s*m(?:²|2)",
            r"([0-9][0-9,.]*)\s*m(?:²|2)\s*(?:de terreno|terreno|lote)",
        ])
        construction = self._area_by_label(text, [
            r"Superficie construida\s*([0-9][0-9,.]*)\s*m(?:²|2)",
            r"Superficie cubierta\s*([0-9][0-9,.]*)\s*m(?:²|2)",
            r"Construcci[oó]n\s*([0-9][0-9,.]*)\s*m(?:²|2)",
            r"([0-9][0-9,.]*)\s*m(?:²|2)\s*(?:construidos?|de construcci[oó]n)",
        ])
        generic = None
        if land is None and construction is None:
            generic = self._area_by_label(" ".join([title, text[:1000]]), [r"([0-9][0-9,.]*)\s*m(?:²|2)\s*(?:totales?)?"])
        return {
            "land_area_m2": land,
            "construction_area_m2": construction,
            "generic_area_m2": generic,
            "land_area_source": "visible_label" if land is not None else None,
            "construction_area_source": "visible_label" if construction is not None else None,
            "generic_area_source": "visible_label" if generic is not None else None,
            "evidence": {"title": title, "url": url, "land": land, "construction": construction, "generic": generic},
        }

    def _area_by_label(self, text: str, patterns: list[str]) -> float | None:
        for pattern in patterns:
            match = re.search(pattern, text, flags=re.I)
            if match:
                return normalize_area_m2(match.group(1))
        return None

    def _property_type(self, title: str, breadcrumbs: list[str], url: str) -> str | None:
        for value in [url, *breadcrumbs, title]:
            result = normalize_property_type(value)
            if result and result != "otro":
                return result
        return normalize_property_type(title)

    def _location(self, breadcrumbs: list[str], text: str, state: str | None, municipality: str | None) -> dict:
        state_value = state or self._breadcrumb_after(breadcrumbs, "Venta", 1)
        municipality_value = municipality or self._breadcrumb_after(breadcrumbs, state_value, 1)
        neighborhood = self._breadcrumb_after(breadcrumbs, municipality_value, 1)
        postal_code = None
        street = None
        address_parts = [part for part in [street, neighborhood, municipality_value, state_value] if part]
        return {
            "state": state_value,
            "municipality": municipality_value,
            "neighborhood": neighborhood,
            "postal_code": postal_code,
            "street": street,
            "address_text": ", ".join(address_parts) if address_parts else None,
        }

    def _breadcrumb_after(self, breadcrumbs: list[str], value: str | None, offset: int) -> str | None:
        if not value:
            return None
        normalized = value.lower()
        for index, item in enumerate(breadcrumbs):
            if item.lower() == normalized and index + offset < len(breadcrumbs):
                return breadcrumbs[index + offset]
        return None

    def _feature_int(self, text: str, labels: list[str]) -> int | None:
        for label in labels:
            patterns = [
                rf"([0-9]+)\s*{re.escape(label)}",
                rf"{re.escape(label)}\s*([0-9]+)",
            ]
            for pattern in patterns:
                match = re.search(pattern, text, flags=re.I)
                if match:
                    return normalize_int(match.group(1))
        return None

    def _feature_float(self, text: str, labels: list[str]) -> float | None:
        for label in labels:
            patterns = [
                rf"([0-9]+(?:[,.][0-9]+)?)\s*{re.escape(label)}",
                rf"{re.escape(label)}\s*([0-9]+(?:[,.][0-9]+)?)",
            ]
            for pattern in patterns:
                match = re.search(pattern, text, flags=re.I)
                if match:
                    return normalize_float(match.group(1))
        return None

    def _coordinates(self, html: str) -> tuple[float | None, float | None]:
        lat_match = re.search(r'"latitude"\s*:\s*(-?\d+(?:\.\d+)?)', html, flags=re.I)
        lon_match = re.search(r'"longitude"\s*:\s*(-?\d+(?:\.\d+)?)', html, flags=re.I)
        if lat_match and lon_match:
            return normalize_float(lat_match.group(1)), normalize_float(lon_match.group(1))
        return None, None

    def _title(self, html: str) -> str | None:
        match = re.search(r"<title[^>]*>(.*?)</title>", html or "", flags=re.I | re.S)
        return clean_text(unescape(re.sub(r"<[^>]+>", " ", match.group(1)))) if match else None

    def _first(self, *values: object) -> str | None:
        for value in values:
            cleaned = clean_text(value)
            if cleaned:
                return cleaned
        return None

    def _slug(self, value: str) -> str:
        replacements = str.maketrans("áéíóúüñ", "aeiouun")
        return re.sub(r"[^a-z0-9]+", "-", value.lower().translate(replacements)).strip("-")
