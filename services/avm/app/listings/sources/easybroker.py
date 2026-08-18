from __future__ import annotations

import json
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


class _StructuredHtmlParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.links: list[str] = []
        self.json_ld: list[str] = []
        self.meta: dict[str, str] = {}
        self.h1: list[str] = []
        self._script_type: str | None = None
        self._in_script = False
        self._script_buffer: list[str] = []
        self._in_h1 = False
        self._h1_buffer: list[str] = []

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
            self._script_type = attributes.get("type", "").lower()
            self._in_script = True
            self._script_buffer = []
        if tag == "h1":
            self._in_h1 = True
            self._h1_buffer = []

    def handle_endtag(self, tag: str) -> None:
        if tag == "script" and self._in_script:
            content = "".join(self._script_buffer).strip()
            if "ld+json" in (self._script_type or "") and content:
                self.json_ld.append(content)
            self._in_script = False
            self._script_type = None
        if tag == "h1" and self._in_h1:
            text = clean_text(unescape("".join(self._h1_buffer)))
            if text:
                self.h1.append(text)
            self._in_h1 = False

    def handle_data(self, data: str) -> None:
        if self._in_script:
            self._script_buffer.append(data)
        if self._in_h1:
            self._h1_buffer.append(data)


class EasyBrokerPublicSource(ListingSource):
    name = "easybroker"
    base_url = "https://www.easybroker.com"

    def __init__(self, delay_seconds: float | None = None, timeout: float | None = None, user_agent: str | None = None) -> None:
        self.delay_seconds = delay_seconds if delay_seconds is not None else float(os.getenv("LISTING_REQUEST_DELAY", "2"))
        self.timeout = timeout if timeout is not None else float(os.getenv("LISTING_REQUEST_TIMEOUT", "20"))
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": user_agent or os.getenv(
                "LISTING_USER_AGENT",
                "GrupoMundoAVMResearchBot/0.1 (+https://grupomundopatrimonial.com)",
            ),
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        })

    def discover(self, state: str, municipality: str, operation: str, max_pages: int, start_urls: list[str] | None = None) -> list[str]:
        urls: list[str] = []
        seen: set[str] = set()
        for search_url in (start_urls or self._search_urls(state, municipality, operation)):
            for page in range(1, max_pages + 1):
                url = self._page_url(search_url, page)
                logger.info("Buscando listings EasyBroker: %s", url)
                try:
                    response = self.session.get(url, timeout=self.timeout)
                except requests.RequestException as exc:
                    logger.error("Descarga fallida de busqueda EasyBroker %s: %s", url, exc)
                    continue
                if response.status_code in (403, 429) or response.status_code >= 500:
                    logger.warning("EasyBroker respondio %s para %s", response.status_code, url)
                    continue
                parser = _StructuredHtmlParser()
                parser.feed(response.text)
                discovered = self._extract_listing_links(parser.links, response.url)
                if not discovered:
                    logger.warning("No se encontraron links de listings en %s", url)
                for listing_url in discovered:
                    if listing_url not in seen:
                        logger.info("Listing descubierto: %s", listing_url)
                        seen.add(listing_url)
                        urls.append(listing_url)
                time.sleep(self.delay_seconds)
        return urls

    def fetch(self, url: str) -> FetchedListing:
        canonical = canonical_url(url)
        logger.info("Descargando listing: %s", canonical)
        try:
            response = self.session.get(canonical, timeout=self.timeout)
            content = response.text
            status = response.status_code
        except requests.RequestException as exc:
            logger.error("Descarga fallida de listing %s: %s", canonical, exc)
            content = ""
            status = None
        time.sleep(self.delay_seconds)
        return FetchedListing(
            source=self.name,
            source_id=source_id_from_url(canonical),
            url=canonical,
            http_status=status,
            raw_content=content,
        )

    def parse(self, fetched: FetchedListing, state: str | None = None, municipality: str | None = None) -> NormalizedListing:
        parser = _StructuredHtmlParser()
        parser.feed(fetched.raw_content or "")
        json_ld = self._parse_json_ld(parser.json_ld)
        title = self._first(
            self._json_path(json_ld, "name"),
            parser.meta.get("og:title"),
            parser.h1[0] if parser.h1 else None,
            self._title_from_html(fetched.raw_content),
        )
        description = self._first(
            self._json_path(json_ld, "description"),
            parser.meta.get("og:description"),
            parser.meta.get("description"),
        )
        price, currency = self._price(json_ld, fetched.raw_content)
        latitude, longitude, coordinate_source = self._coordinates(json_ld, fetched.raw_content)
        main_html = self._main_listing_html(fetched.raw_content or "")
        raw_text = clean_text(re.sub(r"<[^>]+>", " ", main_html)) or ""
        property_type = normalize_property_type(" ".join([fetched.url, title or "", raw_text[:500]]))
        operation = normalize_operation(" ".join([fetched.url, title or "", raw_text[:500]]))
        location = self._location(json_ld, raw_text)
        areas = self._areas(raw_text, property_type)
        bedrooms = self._labeled_int(raw_text, ["recamaras", "recámaras", "dormitorios", "habitaciones"])
        bathrooms = self._labeled_float(raw_text, ["banos", "baños"])
        parking_spaces = self._labeled_int(raw_text, ["estacionamientos", "cocheras", "garages"])
        if property_type == "terreno":
            bedrooms = None
            bathrooms = None
            parking_spaces = None

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
            state=state,
            municipality=municipality,
            locality=location["locality"],
            neighborhood=location["neighborhood"],
            postal_code=location["postal_code"],
            street=location["street"],
            address_text=location["address_text"],
            location_raw=location["location_raw"],
            land_area_m2=areas["land_area_m2"],
            construction_area_m2=areas["construction_area_m2"],
            generic_area_m2=areas["generic_area_m2"],
            land_area_source=areas["land_area_source"],
            construction_area_source=areas["construction_area_source"],
            generic_area_source=areas["generic_area_source"],
            bedrooms=bedrooms,
            bathrooms=bathrooms,
            parking_spaces=parking_spaces,
            age_years=self._labeled_int(raw_text, ["antiguedad", "antigüedad"]),
            description=description,
            raw_data={
                "json_ld": json_ld,
                "meta": parser.meta,
                "coordinate_source": coordinate_source,
                "area_evidence": areas["evidence"],
                "parser": "html_jsonld_regex_v2",
            },
        )
        listing.dedupe_fingerprint = build_dedupe_fingerprint(listing)
        listing.quality_flags = quality_flags(listing)
        return listing

    def _search_urls(self, state: str, municipality: str, operation: str) -> list[str]:
        state_slug = self._slug(state)
        municipality_slug = self._slug(municipality)
        operation_slug = "venta" if operation == "venta" else "renta"
        urls = [
            f"{self.base_url}/mx/listings/casas-en-{operation_slug}-en-{municipality_slug}-{state_slug}",
            f"{self.base_url}/mx/listings/departamentos-en-{operation_slug}-en-{municipality_slug}-{state_slug}",
            f"{self.base_url}/mx/listings/terrenos-en-{operation_slug}-en-{municipality_slug}-{state_slug}",
            f"{self.base_url}/mx/listings/inmuebles-en-{operation_slug}-en-{municipality_slug}-{state_slug}",
        ]
        known_public_indexes = {
            ("morelos", "cuautla", "venta"): [
                "https://quality-paraiso.easybroker.com/properties/mexico/morelos/cuautla",
            ],
        }
        urls.extend(known_public_indexes.get((state_slug, municipality_slug, operation_slug), []))
        return urls

    def _page_url(self, url: str, page: int) -> str:
        if page <= 1:
            return url
        separator = "&" if "?" in url else "?"
        return f"{url}{separator}page={page}"

    def _extract_listing_links(self, links: list[str], base_url: str) -> list[str]:
        found: list[str] = []
        for link in links:
            absolute = canonical_url(urljoin(base_url, link))
            if "/property/" not in absolute and "/properties/" not in absolute:
                continue
            if re.search(r"/property/[^/?#]+$", absolute):
                found.append(absolute)
        return list(dict.fromkeys(found))

    def _parse_json_ld(self, scripts: list[str]) -> list[dict]:
        parsed: list[dict] = []
        for script in scripts:
            try:
                payload = json.loads(script)
            except json.JSONDecodeError:
                continue
            if isinstance(payload, list):
                parsed.extend([item for item in payload if isinstance(item, dict)])
            elif isinstance(payload, dict):
                graph = payload.get("@graph")
                if isinstance(graph, list):
                    parsed.extend([item for item in graph if isinstance(item, dict)])
                parsed.append(payload)
        return parsed

    def _main_listing_html(self, html: str) -> str:
        markers = [
            "similar-listings",
            "listings-carousel",
            "propiedades similares",
            "propiedades relacionadas",
            "otras propiedades",
        ]
        lower = html.lower()
        cut = len(html)
        for marker in markers:
            index = lower.find(marker)
            if index != -1:
                cut = min(cut, index)
        return html[:cut]

    def _json_path(self, objects: list[dict], key: str) -> str | None:
        for item in objects:
            value = item.get(key)
            if isinstance(value, str):
                return clean_text(value)
        return None

    def _price(self, json_ld: list[dict], html: str) -> tuple[float | None, str | None]:
        for item in json_ld:
            offers = item.get("offers")
            if isinstance(offers, dict):
                price = offers.get("price")
                currency = offers.get("priceCurrency")
                parsed, parsed_currency = normalize_price(f"{price} {currency or ''}")
                if parsed is not None:
                    return parsed, (currency or parsed_currency)
        matches = re.findall(r"(?:MXN|USD|US\$|\$)\s?[0-9][0-9,.\s]+|[0-9][0-9,.\s]+\s?(?:MXN|USD)", html, flags=re.I)
        for match in matches:
            parsed, currency = normalize_price(match)
            if parsed is not None:
                return parsed, currency
        return None, None

    def _coordinates(self, json_ld: list[dict], html: str) -> tuple[float | None, float | None, str | None]:
        for item in json_ld:
            geo = item.get("geo")
            if isinstance(geo, dict):
                lat = normalize_float(geo.get("latitude"))
                lng = normalize_float(geo.get("longitude"))
                if lat is not None and lng is not None:
                    return lat, lng, "json_ld"
        patterns = [
            (r'"(?:lat|latitude)"\s*:\s*(-?\d+(?:\.\d+)?).*?"(?:lng|lon|longitude)"\s*:\s*(-?\d+(?:\.\d+)?)', "json_inline"),
            (r'"(?:lng|lon|longitude)"\s*:\s*(-?\d+(?:\.\d+)?).*?"(?:lat|latitude)"\s*:\s*(-?\d+(?:\.\d+)?)', "json_inline_reversed"),
            (r'[?&](?:q|query|center|ll)=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)', "map_url"),
            (r'@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)', "map_url_at"),
        ]
        for pattern, source in patterns:
            match = re.search(pattern, html or "", flags=re.I | re.S)
            if not match:
                continue
            first = normalize_float(match.group(1))
            second = normalize_float(match.group(2))
            if first is None or second is None:
                continue
            if source == "json_inline_reversed":
                return second, first, source
            return first, second, source
        return None, None, None

    def _areas(self, text: str, property_type: str | None) -> dict:
        evidence = self._area_evidence(text)
        land = self._first_area(evidence, {"terreno", "lote", "superficie de terreno"})
        construction = self._first_area(evidence, {"construcción", "construccion", "construidos", "superficie construida"})
        generic = None
        if land is None and construction is None:
            generic = self._first_area(evidence, {"generic"})
        if property_type == "terreno" and land is None:
            generic_candidate = self._first_area(evidence, {"generic"})
            if generic_candidate:
                land = {**generic_candidate, "source": "visible_label"}
                generic = None
        return {
            "land_area_m2": land["value"] if land else None,
            "construction_area_m2": construction["value"] if construction else None,
            "generic_area_m2": generic["value"] if generic else None,
            "land_area_source": land["source"] if land else None,
            "construction_area_source": construction["source"] if construction else None,
            "generic_area_source": generic["source"] if generic else None,
            "evidence": evidence,
        }

    def _area_evidence(self, text: str) -> list[dict]:
        evidence: list[dict] = []
        patterns = [
            ("terreno", r"(?:terreno|lote|superficie de terreno)\D{0,40}([0-9][0-9,.]*)\s*(?:m2|m²|metros?)"),
            ("terreno", r"([0-9][0-9,.]*)\s*(?:m2|m²|metros?)(?:\s+de)?\s+(?:terreno|lote)"),
            ("construcción", r"([0-9][0-9,.]*)\s*(?:m2|m²|metros?)\s+de\s+(?:construcci[oó]n|construidos)"),
            ("construcción", r"(?:construcci[oó]n|construidos|superficie construida|[aá]rea construida)\D{0,25}([0-9][0-9,.]*)\s*(?:m2|m²|metros?)"),
            ("generic", r"(?<![A-Za-zÁÉÍÓÚÜÑáéíóúüñ])([0-9][0-9,.]*)\s*(?:m2|m²|metros?)(?!\s+(?:de\s+)?(?:terreno|lote|construcci[oó]n|construidos))"),
        ]
        for label, pattern in patterns:
            for match in re.finditer(pattern, text, flags=re.I):
                value = normalize_area_m2(match.group(1))
                if value is None:
                    continue
                snippet = clean_text(text[max(0, match.start() - 50):match.end() + 50])
                evidence.append({
                    "label": label,
                    "value": value,
                    "source": "visible_label" if label != "generic" else "unknown",
                    "snippet": snippet,
                })
        return evidence

    def _first_area(self, evidence: list[dict], labels: set[str]) -> dict | None:
        for item in evidence:
            if item["label"] in labels:
                return item
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

    def _location(self, json_ld: list[dict], text: str) -> dict[str, str | None]:
        result = {
            "street": None,
            "address_text": None,
            "location_raw": None,
            "locality": None,
            "neighborhood": None,
            "postal_code": self._postal_code(json_ld, text),
        }
        for item in json_ld:
            address = item.get("address")
            if isinstance(address, dict):
                result["street"] = clean_text(address.get("streetAddress"))
                result["neighborhood"] = clean_text(address.get("addressLocality"))
                result["postal_code"] = clean_text(address.get("postalCode")) or result["postal_code"]
                result["location_raw"] = clean_text(", ".join([value for value in [
                    result["street"], result["neighborhood"], address.get("addressRegion"), address.get("addressCountry")
                ] if value]))
        location_match = re.search(
            r"(?:Ubicaci[oó]n|Colonia|Zona)\s*:?\s*([A-ZÁÉÍÓÚÜÑa-záéíóúüñ0-9 .'-]{3,80}),\s*(Cuautla|Cuernavaca|Jiutepec|Yautepec|Atlatlahucan),\s*(Morelos)",
            text,
            flags=re.I,
        )
        if location_match:
            result["location_raw"] = clean_text(", ".join([location_match.group(1), location_match.group(2), location_match.group(3)]))
            result["neighborhood"] = result["neighborhood"] or self._clean_location_segment(location_match.group(1))
            result["locality"] = result["locality"] or clean_text(location_match.group(2))
        colonia_match = re.search(r"(?:col\.|colonia|zona)\s+([A-Za-zÁÉÍÓÚÜÑáéíóúüñ0-9 .'-]{3,80})", text, flags=re.I)
        if colonia_match and not result["neighborhood"]:
            result["neighborhood"] = clean_text(colonia_match.group(1))
        result["address_text"] = result["location_raw"] or clean_text(", ".join([value for value in [
            result["street"], result["neighborhood"], result["locality"]
        ] if value]))
        return result

    def _clean_location_segment(self, value: str | None) -> str | None:
        text = clean_text(value)
        if not text:
            return None
        for marker in (" en venta ", " en renta "):
            if marker in text.lower():
                index = text.lower().rfind(marker)
                text = text[index + len(marker):].strip()
        return clean_text(text)

    def _postal_code(self, json_ld: list[dict], text: str) -> str | None:
        for item in json_ld:
            address = item.get("address")
            if isinstance(address, dict) and address.get("postalCode"):
                return clean_text(address["postalCode"])
        match = re.search(r"\b\d{5}\b", text)
        return match.group(0) if match else None

    def _title_from_html(self, html: str) -> str | None:
        match = re.search(r"<title[^>]*>(.*?)</title>", html or "", flags=re.I | re.S)
        return clean_text(unescape(re.sub(r"<[^>]+>", " ", match.group(1)))) if match else None

    def _regex_number(self, text: str, pattern: str) -> float | None:
        match = re.search(pattern, text or "", flags=re.I)
        return normalize_float(match.group(1)) if match else None

    def _first(self, *values: str | None) -> str | None:
        for value in values:
            cleaned = clean_text(value)
            if cleaned:
                return cleaned
        return None

    def _slug(self, value: str) -> str:
        text = value.lower().strip()
        replacements = str.maketrans("áéíóúüñ", "aeiouun")
        text = text.translate(replacements)
        return re.sub(r"[^a-z0-9]+", "-", text).strip("-")
