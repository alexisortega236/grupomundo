#!/usr/bin/env python3
"""Build the first real CDMX residential AVM candidate dataset."""

from __future__ import annotations

import argparse
import csv
import json
import re
import sqlite3
import sys
import unicodedata
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import pandas as pd
import shapefile
from pyproj import Transformer
from scipy.spatial import cKDTree
from shapely.geometry import Point, shape
from shapely.strtree import STRtree

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
DEFAULT_DB = ROOT / "data" / "listings_cdmx.sqlite3"
DEFAULT_AGEB = ROOT.parents[1] / "storage/app/data/2025_1_09_A.shp"
DEFAULT_CENSO = ROOT.parents[1] / "storage/app/data/conjunto_de_datos/conjunto_de_datos_ageb_urbana_09_cpv2020.csv"
DEFAULT_DENUE = ROOT.parents[1] / "storage/app/data/denue_09_csv/conjunto_de_datos/denue_inegi_09_.csv"
DEFAULT_OUTPUT = ROOT / "data/experiments/avm_cdmx_v1_candidates.csv"
DEFAULT_REPORT = ROOT / "data/experiments/avm_cdmx_v1_quality_report.json"
DEFAULT_REPORT_MD = ROOT / "data/experiments/avm_cdmx_v1_quality_report.md"

EXPECTED_MUNICIPALITIES = {
    "Álvaro Obregón", "Azcapotzalco", "Benito Juárez", "Coyoacán",
    "Cuajimalpa de Morelos", "Cuauhtémoc", "Gustavo A. Madero", "Iztacalco",
    "Iztapalapa", "La Magdalena Contreras", "Miguel Hidalgo", "Milpa Alta",
    "Tláhuac", "Tlalpan", "Venustiano Carranza", "Xochimilco",
}

DENUE_CATEGORIES = {
    "retail": ("46",),
    "restaurants_hotels": ("72",),
    "health": ("62",),
    "education": ("61",),
    "financial": ("52",),
    "professional_services": ("54",),
}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--db-path", type=Path, default=DEFAULT_DB)
    parser.add_argument("--ageb-shp", type=Path, default=DEFAULT_AGEB)
    parser.add_argument("--censo-csv", type=Path, default=DEFAULT_CENSO)
    parser.add_argument("--denue-csv", type=Path, default=DEFAULT_DENUE)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--report", type=Path, default=DEFAULT_REPORT)
    parser.add_argument("--report-md", type=Path, default=DEFAULT_REPORT_MD)
    parser.add_argument("--geocode", action="store_true", help="Consulta Nominatim para candidatos MXN sin geocodificar.")
    args = parser.parse_args()

    rows = load_rows(args.db_path)
    report: dict = {"generated_at": datetime.now(timezone.utc).isoformat(), "sources": {}, "losses": {}}
    report["sources"]["listings"] = {"path": str(args.db_path), "initial": len(rows)}

    filtered, source_counts = filter_source(rows)
    report["losses"]["source_filter"] = source_counts
    flagged, flag_counts = classify_rows(filtered)
    report["quality_flags"] = flag_counts

    row_by_source_id = {row["source_id"]: row for row in filtered}
    mxn_rows = [row_by_source_id[item["source_id"]] for item in flagged if item["currency"] == "MXN" and not item["exclusion_flags"]]
    usd_rows = [row for row in flagged if row["currency"] == "USD"]
    report["currency"] = {"MXN": len([r for r in flagged if r["currency"] == "MXN"]), "USD": len(usd_rows), "USD_not_converted": len(usd_rows)}
    report["losses"]["content_and_currency"] = {
        "after_residential_filters": len([r for r in flagged if not r["exclusion_flags"]]),
        "excluded_by_content_or_quality": sum(bool(r["exclusion_flags"]) for r in flagged),
        "excluded_usd_from_mxn_training": len(usd_rows),
    }

    deduped, dedup_stats = deduplicate(mxn_rows)
    report["deduplication"] = dedup_stats
    report["losses"]["after_deduplication"] = len(deduped)

    if args.geocode:
        geocode_rows(args.db_path, deduped)
        rows = load_rows(args.db_path)
        by_id = {row["source_id"]: row for row in rows}
        deduped = [by_id[row["source_id"]] for row in deduped]

    ageb_index = AgebIndex(args.ageb_shp, args.censo_csv)
    censo = CensoIndex(args.censo_csv)
    denue = DenueIndex(args.denue_csv, ageb_index.transformer)
    report["sources"]["ageb"] = ageb_index.stats
    report["sources"]["censo"] = censo.stats
    report["sources"]["denue"] = denue.stats

    enriched = []
    spatial_losses = Counter()
    for row in deduped:
        result = enrich_row(row, ageb_index, censo, denue)
        if result["exclusion_reason"]:
            spatial_losses[result["exclusion_reason"]] += 1
            continue
        enriched.append(result)

    report["losses"]["spatial"] = dict(spatial_losses)
    report["losses"]["geocoded_accepted"] = len(enriched)
    report["losses"]["with_ageb"] = sum(bool(row["inegi_cve_ageb"]) for row in enriched)
    report["losses"]["with_censo"] = sum(row["censo_complete"] for row in enriched)
    report["losses"]["with_denue"] = sum(row["denue_complete"] for row in enriched)

    final_rows = [row for row in enriched if row["censo_complete"] and row["denue_complete"]]
    report["stages"] = {
        "listings_initial": len(rows),
        "residential_sale_selected": len(filtered),
        "residential_content_clean_mxn": len(mxn_rows),
        "deduplicated_mxn": len(deduped),
        "geocoded_and_in_cdmx_ageb": len(enriched),
        "with_censo": sum(row["censo_complete"] for row in enriched),
        "with_denue": sum(row["denue_complete"] for row in enriched),
        "final": len(final_rows),
    }
    report["coverage"] = {
        "expected_municipalities": sorted(EXPECTED_MUNICIPALITIES),
        "final_municipalities": sorted({row.get("municipality") for row in final_rows}),
        "missing_municipalities": sorted(EXPECTED_MUNICIPALITIES - {row.get("municipality") for row in final_rows}),
    }
    write_output(final_rows, args.output)
    report.update(build_summary(final_rows, enriched, flagged))
    report["losses"]["final_rows"] = len(final_rows)
    write_report(report, args.report, args.report_md)

    print(json.dumps({
        "initial": len(rows),
        "after_deduplication": len(deduped),
        "geocoded_accepted": len(enriched),
        "final_rows": len(final_rows),
        "output": str(args.output),
        "report": str(args.report),
    }, indent=2, ensure_ascii=False))
    return 0


def load_rows(path: Path) -> list[sqlite3.Row]:
    connection = sqlite3.connect(path)
    connection.row_factory = sqlite3.Row
    rows = list(connection.execute("SELECT * FROM listing_normalized WHERE source = 'mercadolibre' ORDER BY id"))
    connection.close()
    return rows


def filter_source(rows: list[sqlite3.Row]) -> tuple[list[sqlite3.Row], dict]:
    counts = Counter()
    selected = []
    for row in rows:
        if row["property_type"] not in {"casa", "departamento"}:
            counts["excluded_property_type"] += 1
            continue
        if row["operation"] != "venta":
            counts["excluded_operation"] += 1
            continue
        selected.append(row)
    counts["initial"] = len(rows)
    counts["selected_casa_departamento_venta"] = len(selected)
    counts["selected_casas"] = sum(row["property_type"] == "casa" for row in selected)
    counts["selected_departamentos"] = sum(row["property_type"] == "departamento" for row in selected)
    return selected, dict(counts)


def classify_rows(rows: list[sqlite3.Row]) -> tuple[list[dict], dict]:
    patterns = {
        "office": r"\boficin(?:a|as)\b|corporativo|uso de suelo para oficina",
        "hotel": r"\bhotel\b|permiso de hotel",
        "complete_building": r"edificio completo|\bedificio en venta\b|nave industrial",
        "multiple_units": r"departamentos? independientes?|\bduplex\b|\bdúplex\b|multifamiliar|\b\d+ departamentos?\b|varias propiedades|varias casas",
        "commercial_use": r"uso de suelo.*comercial|inmueble comercial|uso comercial|local comercial|vivienda/negocio|accesorias comerciales",
        "as_land": r"como terreno|terreno para desarrollar|solo terreno|sólo terreno|para desarrollar|casa para desarrollar",
        "development": r"\bpreventa\b|desarrollo inmobiliario|desarrollo residencial|entrega de desarrollo",
        "remate": r"\bremate\b|cesión de derechos|cesion de derechos|adjudicad",
    }
    flag_counts = Counter()
    result = []
    for row in rows:
        text = " ".join(str(row[field] or "") for field in ("title", "description", "neighborhood", "address_text"))
        flags = []
        for flag, pattern in patterns.items():
            if re.search(pattern, text, flags=re.I):
                flags.append(flag)
                flag_counts[flag] += 1
        price = row["price"]
        if price is None or float(price) <= 0:
            flags.append("invalid_price")
            flag_counts["invalid_price"] += 1
        if "suspicious_surface_pair" in json.loads(row["quality_flags_json"] or "[]"):
            flags.append("inconsistent_surfaces")
            flag_counts["inconsistent_surfaces"] += 1
        if row["construction_area_m2"] is None:
            flags.append("missing_construction_area")
            flag_counts["missing_construction_area"] += 1
        if row["property_type"] == "casa" and row["land_area_m2"] is None:
            flags.append("missing_land_area")
            flag_counts["missing_land_area"] += 1
        result.append({"source_id": row["source_id"], "exclusion_flags": sorted(set(flags)), "currency": row["currency"] or ""})
    return result, dict(sorted(flag_counts.items()))


def deduplicate(rows: list[sqlite3.Row]) -> tuple[list[sqlite3.Row], dict]:
    seen_ids: set[str] = set()
    seen_urls: set[str] = set()
    groups: dict[str, list[sqlite3.Row]] = {}
    duplicate_source_id = duplicate_url = 0
    for row in rows:
        source_id = str(row["source_id"])
        url = canonical_url(str(row["url"] or ""))
        if source_id in seen_ids:
            duplicate_source_id += 1
            continue
        if url in seen_urls:
            duplicate_url += 1
            continue
        seen_ids.add(source_id)
        seen_urls.add(url)
        fingerprint = row["dedupe_fingerprint"] or semantic_fingerprint(row)
        groups.setdefault(fingerprint, []).append(row)
    selected = []
    semantic_duplicates = 0
    for group in groups.values():
        group.sort(key=quality_sort_key, reverse=True)
        selected.append(group[0])
        semantic_duplicates += len(group) - 1
    return selected, {
        "source_id_duplicates": duplicate_source_id,
        "canonical_url_duplicates": duplicate_url,
        "semantic_duplicate_rows": semantic_duplicates,
        "fingerprint_groups": len(groups),
        "after_deduplication": len(selected),
    }


def quality_sort_key(row: sqlite3.Row) -> tuple:
    return (
        row["price"] is not None,
        row["construction_area_m2"] is not None,
        row["bedrooms"] is not None,
        row["bathrooms"] is not None,
        row["neighborhood"] is not None,
        -int(row["id"]),
    )


def semantic_fingerprint(row: sqlite3.Row) -> str:
    values = [row[field] for field in ("price", "municipality", "neighborhood", "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms")]
    return "|".join(str(value or "").strip().lower() for value in values)


def canonical_url(url: str) -> str:
    return url.rstrip("/").split("?")[0]


def geocode_rows(db_path: Path, rows: list[sqlite3.Row]) -> None:
    from app.listings.geocoding.providers.nominatim import NominatimGeocodingProvider
    from app.listings.geocoding.service import GeocodingService
    from app.listings.storage import ListingStorage

    storage = ListingStorage(db_path)
    service = GeocodingService(storage, NominatimGeocodingProvider())
    by_id = {row["id"] for row in rows}
    eligible = [row for row in storage.eligible_geocoding_rows(source="mercadolibre") if row["id"] in by_id]
    for index, row in enumerate(eligible, start=1):
        service.geocode_listing(row)
        if index % 25 == 0:
            print(f"geocoding {index}/{len(eligible)}", flush=True)
    storage.close()


class AgebIndex:
    def __init__(self, path: Path, censo_path: Path):
        self.transformer = Transformer.from_crs("EPSG:4326", read_prj(path), always_xy=True)
        reader = shapefile.Reader(str(path), encoding="latin-1")
        fields = [field[0] for field in reader.fields[1:]]
        self.geometries = []
        self.attrs = []
        for item in reader.iterShapeRecords():
            self.geometries.append(shape(item.shape.__geo_interface__))
            self.attrs.append(dict(zip(fields, item.record)))
        self.tree = STRtree(self.geometries)
        self.expected_municipalities = censo_municipality_codes(censo_path)
        self.stats = {"path": str(path), "polygons": len(self.geometries), "crs": "EPSG:6372"}

    def match(self, latitude: float, longitude: float, expected_municipality: str) -> dict | None:
        x, y = self.transformer.transform(longitude, latitude)
        point = Point(x, y)
        candidates = self.tree.query(point)
        wrong_municipality = False
        for candidate in candidates:
            index = int(candidate)
            if not self.geometries[index].covers(point):
                continue
            attrs = self.attrs[index]
            mun_code = clean_code(attrs.get("CVE_MUN"), 3)
            expected_code = self.expected_municipalities.get(normalize(expected_municipality))
            if expected_code and mun_code != expected_code:
                wrong_municipality = True
                continue
            return {
                "cve_ent": clean_code(attrs.get("CVE_ENT"), 2),
                "cve_mun": mun_code,
                "cve_loc": clean_code(attrs.get("CVE_LOC"), 4),
                "cve_ageb": clean_code(attrs.get("CVE_AGEB"), 4),
                "area_km2": self.geometries[index].area / 1_000_000,
            }
        return {"invalid": "coordinate_wrong_municipality" if wrong_municipality else "coordinate_outside_cdmx_ageb"}


class CensoIndex:
    def __init__(self, path: Path):
        frame = read_censo(path)
        frame = frame[(frame["ENTIDAD"] == "09") & (frame["MZA"] == "000") & (frame["AGEB"] != "0000")]
        self.by_key = {}
        for _, row in frame.iterrows():
            key = (clean_code(row.ENTIDAD, 2), clean_code(row.MUN, 3), clean_code(row.LOC, 4), clean_code(row.AGEB, 4))
            self.by_key[key] = row.to_dict()
        self.stats = {"path": str(path), "rows_mza_000_ageb": len(self.by_key)}

    def features(self, match: dict) -> dict:
        row = self.by_key.get((match["cve_ent"], match["cve_mun"], match["cve_loc"], match["cve_ageb"]))
        if not row:
            return {}
        population = number(row.get("POBTOT"))
        housing = number(row.get("TVIVHAB"))
        pea = number(row.get("PEA"))
        occupied = number(row.get("POCUPADA"))
        values = {
            "population_density": safe_div(population, match["area_km2"]),
            "housing_density": safe_div(housing, match["area_km2"]),
            "car_ownership_ratio": safe_div(number(row.get("VPH_AUTOM")), housing),
            "internet_access_ratio": safe_div(number(row.get("VPH_INTER")), housing),
            "average_schooling": number(row.get("GRAPROES")),
            "employment_ratio": safe_div(occupied, pea),
        }
        values["censo_complete"] = all(values[key] is not None for key in (
            "population_density", "housing_density", "car_ownership_ratio", "internet_access_ratio", "average_schooling", "employment_ratio",
        ))
        return values


class DenueIndex:
    def __init__(self, path: Path, transformer: Transformer):
        columns = ["id", "codigo_act", "latitud", "longitud"]
        frame = pd.read_csv(path, encoding="latin-1", usecols=columns, dtype={"codigo_act": str})
        frame["lat"] = pd.to_numeric(frame["latitud"], errors="coerce")
        frame["lon"] = pd.to_numeric(frame["longitud"], errors="coerce")
        frame = frame.dropna(subset=["lat", "lon"])
        outside = (frame["lat"] > 20) | (frame["lon"] < -100)
        self.stats = {"path": str(path), "raw_rows": len(frame), "outside_cdmx_excluded": int(outside.sum())}
        frame = frame.loc[~outside].copy()
        x, y = transformer.transform(frame["lon"].to_numpy(), frame["lat"].to_numpy())
        self.coordinates = np.column_stack([x, y])
        self.codes = frame["codigo_act"].fillna("").astype(str).to_numpy()
        self.tree = cKDTree(self.coordinates)

    def counts(self, latitude: float, longitude: float, transformer: Transformer) -> dict:
        x, y = transformer.transform(longitude, latitude)
        result = {"denue_complete": True}
        for radius in (500, 1000):
            indices = self.tree.query_ball_point([x, y], radius)
            codes = self.codes[indices]
            suffix = "500m" if radius == 500 else "1km"
            result[f"establishments_{suffix}"] = int(len(indices))
            for label, prefixes in DENUE_CATEGORIES.items():
                result[f"{label}_{suffix}"] = int(sum(code.startswith(prefixes) for code in codes))
        return result


def enrich_row(row: sqlite3.Row, ageb: AgebIndex, censo: CensoIndex, denue: DenueIndex) -> dict:
    lat = row["geocode_latitude"] if row["geocode_latitude"] is not None else row["latitude"]
    lon = row["geocode_longitude"] if row["geocode_longitude"] is not None else row["longitude"]
    if lat is None or lon is None:
        return {"exclusion_reason": "not_geocoded"}
    if row["geocode_precision"] in ("municipality", "locality", "state", "unknown", None):
        return {"exclusion_reason": "geocode_too_coarse"}
    match = ageb.match(float(lat), float(lon), row["municipality"])
    if not match or match.get("invalid"):
        return {"exclusion_reason": match.get("invalid", "invalid_coordinate") if match else "invalid_coordinate"}
    censo_values = censo.features(match)
    denue_values = denue.counts(float(lat), float(lon), ageb.transformer)
    output = {key: row[key] for key in row.keys() if key in OUTPUT_FIELDS}
    quality_flags = json.loads(row["quality_flags_json"] or "[]")
    quality_flags = sorted(set(quality_flags) - {"missing_coordinates"})
    output.update({
        "latitude": float(lat), "longitude": float(lon), "coordinate_quality": row["geocode_usability"] or "medium",
        "geocode_source": row["geocode_provider"] or "nominatim", "geocode_precision": row["geocode_precision"],
        "quality_flags_json": json.dumps(quality_flags, ensure_ascii=False), "inegi_cve_ent": match["cve_ent"],
        "inegi_cve_mun": match["cve_mun"], "inegi_cve_loc": match["cve_loc"], "inegi_cve_ageb": match["cve_ageb"],
        **censo_values, **denue_values, "exclusion_reason": "",
    })
    return output


OUTPUT_FIELDS = [
    "source", "source_id", "url", "title", "property_type", "operation", "price", "currency",
    "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", "latitude", "longitude",
    "coordinate_quality", "geocode_source", "geocode_precision", "state", "municipality", "locality", "neighborhood", "postal_code", "street", "inegi_cve_ent", "inegi_cve_mun",
    "inegi_cve_loc", "inegi_cve_ageb", "population_density", "housing_density", "car_ownership_ratio",
    "internet_access_ratio", "average_schooling", "employment_ratio", "establishments_500m", "establishments_1km",
    "retail_500m", "retail_1km", "restaurants_hotels_500m", "restaurants_hotels_1km", "health_500m", "health_1km",
    "education_500m", "education_1km", "financial_500m", "financial_1km", "professional_services_500m",
    "professional_services_1km", "dedupe_fingerprint", "quality_flags_json",
]


def build_summary(final_rows: list[dict], enriched: list[dict], flagged: list[dict]) -> dict:
    return {
        "final": {
            "rows": len(final_rows),
            "houses": sum(row.get("property_type") == "casa" for row in final_rows),
            "apartments": sum(row.get("property_type") == "departamento" for row in final_rows),
            "by_municipality": dict(sorted(Counter(row.get("municipality") for row in final_rows).items())),
            "price_distribution_mxn": price_distribution(final_rows),
            "price_distribution_by_property_type": {
                property_type: price_distribution([row for row in final_rows if row.get("property_type") == property_type])
                for property_type in ("casa", "departamento")
            },
        },
        "intermediate": {"spatially_valid_rows": len(enriched), "classified_rows": len(flagged)},
    }


def price_distribution(rows: list[dict]) -> dict:
    values = sorted(float(row["price"]) for row in rows if row.get("price") is not None and row.get("currency") == "MXN")
    if not values:
        return {"count": 0}
    return {"count": len(values), "min": values[0], "p25": float(np.percentile(values, 25)), "median": float(np.median(values)), "p75": float(np.percentile(values, 75)), "p95": float(np.percentile(values, 95)), "max": values[-1]}


def write_output(rows: list[dict], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=OUTPUT_FIELDS, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def write_report(report: dict, path: Path, markdown_path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    final = report.get("final", {})
    lines = ["# AVM CDMX v1 - Reporte de calidad", "", f"Filas finales: **{final.get('rows', 0)}**", "", "## Pérdidas por etapa", ""]
    for key, value in report.get("losses", {}).items():
        lines.append(f"- `{key}`: {value}")
    lines += ["", "## Final", "", f"- Casas: {final.get('houses', 0)}", f"- Departamentos: {final.get('apartments', 0)}", f"- Por alcaldía: `{json.dumps(final.get('by_municipality', {}), ensure_ascii=False)}`", "", "## Flags", "", f"`{json.dumps(report.get('quality_flags', {}), ensure_ascii=False)}`", ""]
    markdown_path.write_text("\n".join(lines), encoding="utf-8")


def read_censo(path: Path) -> pd.DataFrame:
    frame = pd.read_csv(path, encoding="utf-8-sig", dtype=str)
    frame.columns = [str(column).replace("ï»¿", "").replace("\ufeff", "").strip() for column in frame.columns]
    return frame


def censo_municipality_codes(path: Path) -> dict[str, str]:
    frame = read_censo(path)
    frame = frame[(frame["ENTIDAD"] == "09") & (frame["MUN"] != "000")]
    return {normalize(row.NOM_MUN): clean_code(row.MUN, 3) for _, row in frame.iterrows() if not str(row.NOM_MUN).startswith("Total")}


def read_prj(shp_path: Path) -> str:
    return shp_path.with_suffix(".prj").read_text(encoding="utf-8", errors="ignore")


def clean_code(value: object, width: int) -> str | None:
    if value in (None, "", "nan"):
        return None
    return str(value).strip().zfill(width)


def number(value: object) -> float | None:
    if value in (None, "", "*", "N/D"):
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def safe_div(a: float | None, b: float | None) -> float | None:
    return None if a is None or b in (None, 0) else a / b


def normalize(value: object) -> str:
    text = unicodedata.normalize("NFKD", str(value or "")).encode("ascii", "ignore").decode().lower()
    return re.sub(r"[^a-z0-9]+", " ", text).strip()


if __name__ == "__main__":
    raise SystemExit(main())
