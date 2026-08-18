#!/usr/bin/env python3
from __future__ import annotations

import argparse
import logging
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.listings.storage import ListingStorage  # noqa: E402
from app.listings.normalizer import canonical_url, source_id_from_url  # noqa: E402
from app.listings.sources.easybroker import EasyBrokerPublicSource  # noqa: E402
from app.listings.sources.icasas import IcasasPublicSource  # noqa: E402
from app.listings.sources.mercadolibre import MercadoLibrePublicSource  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Recolecta listings inmobiliarios publicos para dataset AVM.")
    parser.add_argument("--source", choices=["easybroker", "icasas", "mercadolibre"], required=True)
    parser.add_argument("--state", required=True)
    parser.add_argument("--municipality", default=None)
    parser.add_argument("--municipalities", default=None, help="Lista separada por comas. Usa guion bajo para espacios.")
    parser.add_argument("--operation", choices=["venta", "renta"], default="venta")
    parser.add_argument("--max-pages", type=int, default=2)
    parser.add_argument("--max-listings", type=int, default=50)
    parser.add_argument("--property-type", action="append", choices=["casa", "departamento", "terreno"], default=[], help="Tipo para fuentes que lo soportan. Puede repetirse.")
    parser.add_argument("--start-url", action="append", default=[], help="URL publica inicial de una agencia EasyBroker. Puede repetirse.")
    parser.add_argument("--seed-file", default=None, help="Archivo con una URL publica por linea para ampliar semillas sin descubrir endpoints privados.")
    parser.add_argument("--refresh", action="store_true", help="Vuelve a descargar listings existentes.")
    parser.add_argument("--db-path", default=str(ROOT / "data" / "listings.sqlite3"))
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.INFO if args.verbose else logging.WARNING,
        format="%(levelname)s %(message)s",
    )

    source = make_source(args.source, args.property_type)
    storage = ListingStorage(args.db_path)
    errors = 0
    parsed = []
    downloaded = 0
    skipped_existing = 0

    try:
        municipalities = municipality_list(args.municipalities, args.municipality)
        seed_urls = [*args.start_url, *read_seed_file(args.seed_file)]
        urls_by_municipality = discover_urls(source, args.state, municipalities, args.operation, args.max_pages, seed_urls)
        candidates = flatten_unique(urls_by_municipality)[: args.max_listings]

        for url, municipality in candidates:
            try:
                canonical = canonical_url(url)
                source_id = source._source_id(canonical) if hasattr(source, "_source_id") else source_id_from_url(canonical)
                if not args.refresh and storage.raw_exists(source.name, source_id):
                    skipped_existing += 1
                    logging.info("Listing existente omitido sin --refresh: %s", url)
                    continue
                fetched = source.fetch(url)
                downloaded += 1 if fetched.raw_content else 0
                raw_id = storage.save_raw(
                    source=fetched.source,
                    source_id=fetched.source_id,
                    url=fetched.url,
                    http_status=fetched.http_status,
                    raw_content=fetched.raw_content,
                )
                listing = source.parse(fetched, state=args.state, municipality=municipality)
                if listing.operation != args.operation:
                    logging.warning("Listing omitido por operacion distinta: %s (%s)", listing.url, listing.operation)
                    continue
                storage.save_normalized(listing, raw_id=raw_id)
                parsed.append(listing)
                logging.info("Listing guardado: %s", listing.url)
            except Exception as exc:  # noqa: BLE001
                errors += 1
                logging.exception("Error procesando listing %s: %s", url, exc)

        storage.refresh_training_metrics(source.name)
        print_summary(len(candidates), downloaded, parsed, errors, skipped_existing)
    finally:
        storage.close()

    return 0 if errors == 0 else 1


def municipality_list(municipalities: str | None, municipality: str | None) -> list[str]:
    if municipalities:
        return [item.strip().replace("_", " ") for item in municipalities.split(",") if item.strip()]
    if municipality:
        return [municipality.replace("_", " ")]
    raise SystemExit("Debes indicar --municipality o --municipalities.")


def make_source(name: str, property_types: list[str]):
    if name == "easybroker":
        return EasyBrokerPublicSource()
    if name == "icasas":
        return IcasasPublicSource()
    if name == "mercadolibre":
        return MercadoLibrePublicSource(property_types=property_types or None)
    raise SystemExit(f"Fuente no soportada: {name}")


def read_seed_file(path: str | None) -> list[str]:
    if not path:
        return []
    seed_path = Path(path)
    if not seed_path.exists():
        raise SystemExit(f"No existe seed-file: {seed_path}")
    urls: list[str] = []
    for line in seed_path.read_text(encoding="utf-8").splitlines():
        clean = line.strip()
        if clean and not clean.startswith("#"):
            urls.append(clean)
    return urls


def discover_urls(source, state: str, municipalities: list[str], operation: str, max_pages: int, seed_urls: list[str]) -> dict[str, list[str]]:
    discovered: dict[str, list[str]] = {}
    for municipality in municipalities:
        urls = source.discover(
            state=state,
            municipality=municipality,
            operation=operation,
            max_pages=max_pages,
            start_urls=seed_urls or None,
        )
        discovered[municipality] = urls
        logging.info("%s: %s URLs descubiertas", municipality, len(urls))
    return discovered


def flatten_unique(urls_by_municipality: dict[str, list[str]]) -> list[tuple[str, str]]:
    seen: set[str] = set()
    flattened: list[tuple[str, str]] = []
    municipalities = list(urls_by_municipality.keys())
    max_len = max((len(urls) for urls in urls_by_municipality.values()), default=0)
    for index in range(max_len):
        for municipality in municipalities:
            urls = urls_by_municipality[municipality]
            if index >= len(urls):
                continue
            url = urls[index]
            canonical = canonical_url(url)
            if canonical in seen:
                continue
            seen.add(canonical)
            flattened.append((canonical, municipality))
    return flattened


def print_summary(discovered: int, downloaded: int, listings: list, errors: int, skipped_existing: int) -> None:
    def count(field: str) -> int:
        return sum(1 for listing in listings if getattr(listing, field) is not None)

    print(f"Listings descubiertos: {discovered}")
    print(f"Existentes omitidos: {skipped_existing}")
    print(f"Descargados: {downloaded}")
    print(f"Parseados correctamente: {len(listings)}")
    print(f"Con precio: {count('price')}")
    print(f"Con lat/lng: {sum(1 for listing in listings if listing.latitude is not None and listing.longitude is not None)}")
    print(f"Con m2 terreno: {count('land_area_m2')}")
    print(f"Con m2 construcción: {count('construction_area_m2')}")
    print(f"Con recámaras: {count('bedrooms')}")
    print(f"Con baños: {count('bathrooms')}")
    print(f"Con estacionamiento: {count('parking_spaces')}")
    print(f"Errores: {errors}")


if __name__ == "__main__":
    raise SystemExit(main())
