#!/usr/bin/env python3
"""Audit the CDMX candidate dataset and produce clean/excluded derivatives."""

from __future__ import annotations

import argparse
import csv
import json
import math
import re
import sqlite3
import statistics
import unicodedata
from collections import Counter, defaultdict
from pathlib import Path

import numpy as np


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_INPUT = ROOT / "data/experiments/avm_cdmx_v1_candidates.csv"
DEFAULT_DB = ROOT / "data/listings_cdmx.sqlite3"
DEFAULT_CLEAN = ROOT / "data/experiments/avm_cdmx_v1_clean.csv"
DEFAULT_EXCLUDED = ROOT / "data/experiments/avm_cdmx_v1_excluded.csv"
DEFAULT_REPORT = ROOT / "data/experiments/avm_cdmx_v1_clean_report.json"
DEFAULT_REPORT_MD = ROOT / "data/experiments/avm_cdmx_v1_clean_report.md"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", type=Path, default=DEFAULT_INPUT)
    parser.add_argument("--db-path", type=Path, default=DEFAULT_DB)
    parser.add_argument("--clean", type=Path, default=DEFAULT_CLEAN)
    parser.add_argument("--excluded", type=Path, default=DEFAULT_EXCLUDED)
    parser.add_argument("--report", type=Path, default=DEFAULT_REPORT)
    parser.add_argument("--report-md", type=Path, default=DEFAULT_REPORT_MD)
    args = parser.parse_args()

    rows = read_csv(args.input)
    base_fields = list(rows[0].keys())
    details = read_listing_details(args.db_path)
    for row in rows:
        row["_description"] = details.get(row["source_id"], {}).get("description", "")
        row["_db_raw_data_json"] = details.get(row["source_id"], {}).get("raw_data_json", "")

    duplicate_info = duplicate_audit(rows)
    stats = statistical_audit(rows)
    annotated, exclusion_counts = classify_rows(rows)
    clean = [row for row in annotated if not row["exclusion_reason"]]
    excluded = [row for row in annotated if row["exclusion_reason"]]

    derived_fields = ["exclusion_reason", "audit_flags", "price_per_construction_m2", "price_per_land_m2", "price_m2_classification", "price_m2_reason", "semantic_evidence"]
    csv_fields = base_fields + [field for field in derived_fields if field not in base_fields]
    write_csv(clean, args.clean, csv_fields)
    write_csv(excluded, args.excluded, csv_fields)

    stats["clean"] = statistical_audit(clean)
    report = build_report(rows, clean, excluded, duplicate_info, stats, exclusion_counts)
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    args.report_md.write_text(render_markdown(report), encoding="utf-8")

    print(json.dumps({
        "original": len(rows), "clean": len(clean), "excluded": len(excluded),
        "clean_path": str(args.clean), "excluded_path": str(args.excluded), "report": str(args.report),
    }, indent=2, ensure_ascii=False))
    return 0


def read_csv(path: Path) -> list[dict]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def read_listing_details(path: Path) -> dict[str, dict]:
    connection = sqlite3.connect(path)
    connection.row_factory = sqlite3.Row
    rows = connection.execute("SELECT source_id, description, raw_data_json FROM listing_normalized WHERE source = 'mercadolibre'").fetchall()
    connection.close()
    return {row["source_id"]: dict(row) for row in rows}


def number(row: dict, field: str) -> float | None:
    value = row.get(field, "")
    try:
        return float(value) if value not in (None, "") else None
    except (TypeError, ValueError):
        return None


def percentile(values: list[float], p: float) -> float | None:
    return float(np.percentile(values, p)) if values else None


def distribution(values: list[float]) -> dict:
    values = sorted(value for value in values if math.isfinite(value))
    if not values:
        return {"count": 0}
    return {"count": len(values), "min": values[0], "p1": percentile(values, 1), "p5": percentile(values, 5),
            "p10": percentile(values, 10), "p25": percentile(values, 25), "median": percentile(values, 50),
            "p75": percentile(values, 75), "p90": percentile(values, 90), "p95": percentile(values, 95),
            "p99": percentile(values, 99), "max": values[-1]}


def add_derived(row: dict) -> None:
    price = number(row, "price")
    construction = number(row, "construction_area_m2")
    land = number(row, "land_area_m2")
    row["price_per_construction_m2"] = price / construction if price and construction and construction > 0 else None
    row["price_per_land_m2"] = price / land if price and land and land > 0 else None


def statistical_audit(rows: list[dict]) -> dict:
    for row in rows:
        add_derived(row)
    result = {"overall": {}, "by_property_type": {}, "by_municipality_type": {}}
    metrics = ("price", "construction_area_m2", "land_area_m2", "price_per_construction_m2", "price_per_land_m2")
    for label, subset in [("overall", rows), ("casa", [r for r in rows if r["property_type"] == "casa"]), ("departamento", [r for r in rows if r["property_type"] == "departamento"])]:
        result["by_property_type"][label] = {metric: distribution([number(row, metric) for row in subset if number(row, metric) is not None]) for metric in metrics}
    result["overall"] = {metric: distribution([number(row, metric) for row in rows if number(row, metric) is not None]) for metric in metrics}
    groups = defaultdict(list)
    for row in rows:
        groups[(row["municipality"], row["property_type"])].append(row)
    for (municipality, property_type), subset in sorted(groups.items()):
        if len(subset) >= 10:
            result["by_municipality_type"][f"{municipality} | {property_type}"] = {
                "count": len(subset),
                "price": distribution([number(row, "price") for row in subset if number(row, "price") is not None]),
                "price_per_construction_m2": distribution([number(row, "price_per_construction_m2") for row in subset if number(row, "price_per_construction_m2") is not None]),
            }
    result["outlier_review"] = outlier_review(rows)
    return result


def robust_group_limits(values: list[float]) -> tuple[float, float]:
    median = statistics.median(values)
    deviations = [abs(value - median) for value in values]
    mad = statistics.median(deviations) or 1.0
    return median - 6 * 1.4826 * mad, median + 6 * 1.4826 * mad


def outlier_review(rows: list[dict]) -> dict:
    groups = defaultdict(list)
    for row in rows:
        value = row.get("price_per_construction_m2")
        if value is not None and value > 0:
            groups[(row["municipality"], row["property_type"])].append(value)
    reviewed = []
    for row in rows:
        value = row.get("price_per_construction_m2")
        if value is None:
            continue
        values = groups[(row["municipality"], row["property_type"])]
        q1, q3 = np.percentile(values, [25, 75]) if len(values) >= 4 else (None, None)
        iqr_flag = q1 is not None and (value < q1 - 1.5 * (q3 - q1) or value > q3 + 1.5 * (q3 - q1))
        low, high = robust_group_limits(values) if len(values) >= 8 else (None, None)
        mad_flag = low is not None and (value < low or value > high)
        if iqr_flag or mad_flag:
            row["audit_flags"] = append_flag(row.get("audit_flags", ""), "price_m2_statistical_outlier")
            reviewed.append({"source_id": row["source_id"], "municipality": row["municipality"], "property_type": row["property_type"], "price_per_construction_m2": float(value), "iqr_outlier": bool(iqr_flag), "mad_outlier": bool(mad_flag), "classification": "suspicious", "reason": "Robust group outlier; retained pending contextual review."})
    return {"count": len(reviewed), "cases": reviewed}


def normalize_text(value: str) -> str:
    text = unicodedata.normalize("NFKD", value or "").encode("ascii", "ignore").decode().lower()
    return re.sub(r"\s+", " ", text).strip()


def semantic_evidence(row: dict) -> tuple[str, list[str]]:
    text = normalize_text(" ".join([row.get("title", ""), row.get("_description", ""), row.get("url", "")]))
    evidence = []
    strong_patterns = {
        "hotel": r"\bhotel\b|hotel boutique|hotel en venta",
        "office": r"\boficina(?:s)?\b|corporativo|uso de suelo para oficina",
        "complete_building": r"edificio completo|edificio de departamentos|edificio en venta",
        "multiple_units": r"departamentos independientes|multifamiliar|\b\d+ departamentos\b|varias unidades|renta de departamentos",
        "commercial": r"uso de suelo comercial|inmueble comercial|local comercial|plaza comercial",
        "as_land": r"como terreno|solo terreno|sólo terreno|terreno para desarrollar|casa para desarrollar",
        "remate": r"\bremate\b|cesion de derechos|cesion de derechos|adjudicad",
        "development": r"\bpreventa\b|desarrollo inmobiliario|desarrollo residencial|entrega\s+\w+\s+20\d{2}",
        "rent_or_rights": r"derechos litigiosos|cesion de derechos",
    }
    for label, pattern in strong_patterns.items():
        if re.search(pattern, text):
            evidence.append(label)
    # A single generic word is not enough; only strong explicit phrases or corroborating evidence exclude.
    rent_title = normalize_text(row.get("title", ""))
    if re.search(r"\bse renta\b|\ben renta\b|\brenta mensual\b", rent_title) and not re.search(r"\bventa\b", rent_title):
        evidence.append("rent_title")
    strong = {"hotel", "complete_building", "commercial", "as_land", "remate", "rent_or_rights", "rent_title", "development"}
    if any(item in strong for item in evidence):
        return ";".join(evidence), evidence
    if len(set(evidence) & {"office", "multiple_units", "development"}) >= 2:
        return ";".join(evidence), evidence
    return ";".join(evidence), []


def append_flag(existing: str, flag: str) -> str:
    values = [item for item in existing.split(";") if item]
    if flag not in values:
        values.append(flag)
    return ";".join(sorted(values))


def classify_rows(rows: list[dict]) -> tuple[list[dict], dict]:
    counts = Counter()
    groups = defaultdict(list)
    for row in rows:
        groups[(row["municipality"], row["property_type"])].append(row)
    for group_rows in groups.values():
        values = [row["price_per_construction_m2"] for row in group_rows if row.get("price_per_construction_m2") and row["price_per_construction_m2"] > 0]
        low, high = robust_group_limits(values) if len(values) >= 8 else (None, None)
        for row in group_rows:
            row["exclusion_reason"] = ""
            row["audit_flags"] = row.get("audit_flags", "")
            reasons = []
            price = number(row, "price")
            construction = number(row, "construction_area_m2")
            land = number(row, "land_area_m2")
            if price is None or price <= 0:
                reasons.append("invalid_price")
            elif price < 100000:
                reasons.append("clearly_invalid_total_price_context")
            if construction is not None and construction <= 0:
                reasons.append("invalid_construction_area")
            if land is not None and land <= 0:
                reasons.append("invalid_land_area")
            if row["property_type"] == "casa" and construction is None:
                reasons.append("missing_construction_area")
            if row["currency"] != "MXN":
                reasons.append("non_mxn_currency")
            if row["operation"] != "venta":
                reasons.append("not_sale")
            for field, limit in (("bedrooms", 20), ("bathrooms", 20), ("parking_spaces", 30)):
                value = number(row, field)
                if value is not None and value > limit:
                    reasons.append(f"extreme_{field}")
            evidence_text, evidence = semantic_evidence(row)
            row["semantic_evidence"] = evidence_text
            if evidence:
                reasons.append("semantic_nonstandard_residential:" + ",".join(evidence))
            ppm = row.get("price_per_construction_m2")
            if price is None or construction is None or construction <= 0:
                row["price_m2_classification"] = "clearly_invalid"
                row["price_m2_reason"] = "No existe un precio y una superficie de construcción válidos para calcular precio/m²."
            elif price < 100000:
                row["price_m2_classification"] = "clearly_invalid"
                row["price_m2_reason"] = "Precio total inferior a 100,000 MXN, incompatible con una venta residencial CDMX y consistente con anuncio de renta/error."
            elif ppm is not None and low is not None and (ppm < low or ppm > high):
                row["price_m2_classification"] = "suspicious"
                row["price_m2_reason"] = "Outlier robusto dentro de su grupo alcaldía/tipo; retenido para revisión contextual."
            else:
                row["price_m2_classification"] = "plausible"
                row["price_m2_reason"] = "No es outlier robusto dentro de su grupo alcaldía/tipo."
            if ppm is not None and ppm > 0 and low is not None and (ppm < low or ppm > high):
                row["audit_flags"] = append_flag(row["audit_flags"], "price_m2_statistical_outlier")
            # Existing flags are retained as audit evidence. same_land_and_construction_area and missing_land_area are not automatic exclusions.
            row["exclusion_reason"] = ";".join(sorted(set(reasons)))
            for reason in set(reasons):
                counts[reason] += 1
    return rows, dict(sorted(counts.items()))


def duplicate_audit(rows: list[dict]) -> dict:
    fields = {"source_id": Counter(row["source_id"] for row in rows), "canonical_url": Counter(canonical_url(row["url"]) for row in rows), "fingerprint": Counter(row["dedupe_fingerprint"] for row in rows)}
    result = {}
    for key, counts in fields.items():
        groups = {value: count for value, count in counts.items() if count > 1}
        result[key] = {"duplicate_groups": len(groups), "duplicate_rows_beyond_first": sum(count - 1 for count in groups.values()), "groups": groups}
    semantic = Counter(semantic_key(row) for row in rows)
    semantic_groups = {key: count for key, count in semantic.items() if count > 1}
    result["semantic"] = {"duplicate_groups": len(semantic_groups), "duplicate_rows_beyond_first": sum(count - 1 for count in semantic_groups.values()), "groups": semantic_groups}
    return result


def semantic_key(row: dict) -> str:
    return "|".join(str(row.get(field, "")).strip().lower() for field in ("price", "municipality", "neighborhood", "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms"))


def canonical_url(url: str) -> str:
    return (url or "").rstrip("/").split("?")[0]


def build_report(rows, clean, excluded, duplicates, stats, exclusion_counts) -> dict:
    municipality_counts = Counter(row["municipality"] for row in clean)
    ageb_counts = Counter(row["inegi_cve_ageb"] for row in clean)
    type_counts = Counter(row["property_type"] for row in clean)
    return {
        "input": {"path": str(DEFAULT_INPUT), "original_count": len(rows)},
        "result": {"clean_count": len(clean), "excluded_count": len(excluded), "houses": type_counts["casa"], "apartments": type_counts["departamento"]},
        "exclusions": exclusion_counts,
        "statistics": stats,
        "duplicates": duplicates,
        "coverage": {"municipalities_represented": len(municipality_counts), "municipality_counts": dict(sorted(municipality_counts.items())), "missing_municipalities": ["Álvaro Obregón"], "agebs_represented": len(ageb_counts), "ageb_count_distribution": distribution(list(ageb_counts.values())), "lowest_municipality_counts": sorted(municipality_counts.items(), key=lambda item: item[1])[:5], "highest_ageb_counts": sorted(ageb_counts.items(), key=lambda item: item[1], reverse=True)[:10]},
        "surface_review": surface_review(rows),
        "physical_review": physical_review(rows),
        "remaining_quality_issues": {"existing_quality_flags": dict(Counter(row["quality_flags_json"] for row in clean)), "audit_flags": dict(Counter(flag for row in clean for flag in row.get("audit_flags", "").split(";") if flag)), "price_m2_classification": dict(Counter(row.get("price_m2_classification") for row in clean)), "price_context_review": "No global price threshold was applied; only prices below 100,000 MXN were excluded as clearly invalid in this residential-sale context. Statistical price/m2 outliers were retained and flagged."},
    }


def surface_review(rows: list[dict]) -> dict:
    def valid(field, row):
        value = number(row, field)
        return value is not None and value > 0
    houses = [row for row in rows if row["property_type"] == "casa"]
    apartments = [row for row in rows if row["property_type"] == "departamento"]
    return {
        "missing_land_area": sum(not valid("land_area_m2", row) for row in rows),
        "missing_construction_area": sum(not valid("construction_area_m2", row) for row in rows),
        "land_equals_construction": sum(valid("land_area_m2", row) and valid("construction_area_m2", row) and number(row, "land_area_m2") == number(row, "construction_area_m2") for row in rows),
        "construction_at_least_three_times_land": sum(valid("land_area_m2", row) and valid("construction_area_m2", row) and number(row, "construction_area_m2") >= 3 * number(row, "land_area_m2") for row in rows),
        "construction_under_20m2": sum(number(row, "construction_area_m2") is not None and number(row, "construction_area_m2") < 20 for row in rows),
        "construction_over_1000m2": sum(number(row, "construction_area_m2") is not None and number(row, "construction_area_m2") > 1000 for row in rows),
        "apartments_with_land_area": sum(valid("land_area_m2", row) for row in apartments),
        "houses": len(houses), "apartments": len(apartments),
    }


def physical_review(rows: list[dict]) -> dict:
    result = {}
    for field in ("bedrooms", "bathrooms", "parking_spaces"):
        values = [number(row, field) for row in rows if number(row, field) is not None]
        result[field] = {"distribution": distribution(values), "zeros": sum(value == 0 for value in values), "over_10": sum(value > 10 for value in values), "missing": len(rows) - len(values)}
    result["high_bedroom_house"] = sum(row["property_type"] == "casa" and (number(row, "bedrooms") or 0) > 10 for row in rows)
    result["high_bathroom_house"] = sum(row["property_type"] == "casa" and (number(row, "bathrooms") or 0) > 10 for row in rows)
    return result


def write_csv(rows: list[dict], path: Path, fields: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            output = {key: ("" if row.get(key) is None else row.get(key)) for key in fields}
            for key in ("price_per_construction_m2", "price_per_land_m2"):
                if isinstance(output.get(key), float):
                    output[key] = f"{output[key]:.6f}"
            writer.writerow(output)


def render_markdown(report: dict) -> str:
    result = report["result"]
    coverage = report["coverage"]
    stats = report["statistics"]["clean"]["by_property_type"]
    lines = ["# Auditoría final y dataset clean AVM CDMX v1", "", f"Filas originales: **{report['input']['original_count']}**", f"Filas clean: **{result['clean_count']}**", f"Filas excluidas: **{result['excluded_count']}**", "", "## Resultado", "", f"- Casas: {result['houses']}", f"- Departamentos: {result['apartments']}", f"- Alcaldías representadas: {coverage['municipalities_represented']}", f"- AGEB representadas: {coverage['agebs_represented']}", f"- Sin cobertura: Álvaro Obregón", "", "## Exclusiones", ""]
    for key, value in report["exclusions"].items():
        lines.append(f"- `{key}`: {value}")
    lines += ["", "## Distribución de precio", "", "| Tipo | N | Min | P5 | Mediana | P95 | Max |", "|---|---:|---:|---:|---:|---:|---:|"]
    for label in ("casa", "departamento"):
        item = stats[label]["price"]
        lines.append(f"| {label} | {item.get('count', 0)} | {fmt(item.get('min'))} | {fmt(item.get('p5'))} | {fmt(item.get('median'))} | {fmt(item.get('p95'))} | {fmt(item.get('max'))} |")
    lines += ["", "## Precio por construcción", "", "Los outliers estadísticos por grupo se conservaron y quedaron marcados; no se aplicó un filtro estadístico automático.", "", "## Cobertura", "", f"- Alcaldías: `{json.dumps(coverage['municipality_counts'], ensure_ascii=False)}`", f"- AGEB más concentradas: `{json.dumps(coverage['highest_ageb_counts'], ensure_ascii=False)}`", "", "## Calidad remanente", "", f"`{json.dumps(report['remaining_quality_issues'], ensure_ascii=False)}`", ""]
    return "\n".join(lines)


def fmt(value) -> str:
    return "n/d" if value is None else f"{value:,.2f}"


if __name__ == "__main__":
    raise SystemExit(main())
