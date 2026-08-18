from __future__ import annotations

import hashlib
import re
from decimal import Decimal, InvalidOperation
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit


PROPERTY_TYPE_MAP = {
    "casa": "casa",
    "casas": "casa",
    "house": "casa",
    "departamento": "departamento",
    "departamentos": "departamento",
    "depa": "departamento",
    "apartment": "departamento",
    "terreno": "terreno",
    "terrenos": "terreno",
    "land": "terreno",
    "local": "local",
    "locales": "local",
    "bodega": "bodega",
    "bodegas": "bodega",
    "warehouse": "bodega",
}

OPERATION_MAP = {
    "venta": "venta",
    "en venta": "venta",
    "sale": "venta",
    "renta": "renta",
    "en renta": "renta",
    "rent": "renta",
}


def clean_text(value: object) -> str | None:
    if value is None:
        return None
    text = re.sub(r"\s+", " ", str(value)).strip()
    return text or None


def normalize_property_type(value: object) -> str | None:
    text = clean_text(value)
    if not text:
        return None
    normalized = text.lower()
    for key, result in PROPERTY_TYPE_MAP.items():
        if re.search(rf"\b{re.escape(key)}\b", normalized):
            return result
    return "otro"


def normalize_operation(value: object) -> str:
    text = clean_text(value)
    if not text:
        return "desconocido"
    normalized = text.lower()
    for key, result in OPERATION_MAP.items():
        if key in normalized:
            return result
    return "desconocido"


def normalize_price(value: object) -> tuple[float | None, str | None]:
    text = clean_text(value)
    if not text:
        return None, None

    upper = text.upper()
    currency = None
    if "USD" in upper or "US$" in upper or "DOL" in upper:
        currency = "USD"
    elif "MXN" in upper or "MN" in upper or "$" in upper:
        currency = "MXN"

    numeric = re.sub(r"[^0-9.,]", "", text)
    if not numeric:
        return None, currency

    if "," in numeric and "." in numeric:
        numeric = numeric.replace(",", "")
    elif "," in numeric and "." not in numeric:
        parts = numeric.split(",")
        if len(parts[-1]) == 2:
            numeric = ".".join(["".join(parts[:-1]), parts[-1]])
        else:
            numeric = "".join(parts)

    try:
        price = Decimal(numeric)
    except InvalidOperation:
        return None, currency

    if price <= 0:
        return float(price), currency
    return float(price), currency


def normalize_area_m2(value: object) -> float | None:
    text = clean_text(value)
    if not text:
        return None
    match = re.search(r"([0-9]+(?:[,.][0-9]+)?)", text.replace(",", ""))
    if not match:
        return None
    try:
        area = Decimal(match.group(1))
    except InvalidOperation:
        return None
    return float(area)


def normalize_int(value: object) -> int | None:
    text = clean_text(value)
    if not text:
        return None
    match = re.search(r"-?\d+", text)
    if not match:
        return None
    return int(match.group(0))


def normalize_float(value: object) -> float | None:
    text = clean_text(value)
    if not text:
        return None
    match = re.search(r"-?\d+(?:[,.]\d+)?", text)
    if not match:
        return None
    return float(match.group(0).replace(",", "."))


def canonical_url(url: str) -> str:
    parsed = urlsplit(url.strip())
    query = urlencode(
        [
            (key, value)
            for key, value in parse_qsl(parsed.query, keep_blank_values=False)
            if not key.lower().startswith(("utm_", "fbclid", "gclid"))
        ],
        doseq=True,
    )
    path = parsed.path.rstrip("/") or "/"
    return urlunsplit((parsed.scheme.lower(), parsed.netloc.lower(), path, query, ""))


def source_id_from_url(url: str) -> str:
    url = canonical_url(url)
    slug = urlsplit(url).path.rstrip("/").split("/")[-1]
    if slug and len(slug) > 3:
        return slug
    return hashlib.sha1(url.encode("utf-8")).hexdigest()

