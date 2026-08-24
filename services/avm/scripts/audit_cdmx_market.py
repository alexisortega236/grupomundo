#!/usr/bin/env python3
"""Descriptive and retrospective market/comparable audit for CDMX AVM data.

This script creates analysis-only artifacts. It never modifies the source dataset,
trains a model, or writes production/runtime files.
"""

from __future__ import annotations

import argparse
import json
import math
import re
from pathlib import Path

import numpy as np
import pandas as pd
try:
    import joblib
except ImportError:  # pragma: no cover - audit can still run without inference
    joblib = None


REQUIRED_FIELDS = [
    "source", "source_id", "url", "price", "currency", "property_type",
    "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms",
    "parking_spaces", "property_age_years", "state", "municipality",
    "neighborhood", "postal_code", "latitude", "longitude",
    "geocode_latitude", "geocode_longitude", "inegi_cve_ageb",
]


def json_default(value):
    if isinstance(value, (np.integer, np.floating)):
        return value.item()
    if isinstance(value, (pd.Timestamp,)):
        return value.isoformat()
    if pd.isna(value):
        return None
    raise TypeError(f"Not JSON serializable: {type(value)!r}")


def write_json(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2, default=json_default) + "\n", encoding="utf-8")


def stats(series: pd.Series) -> dict:
    values = pd.to_numeric(series, errors="coerce").dropna()
    if values.empty:
        return {"count": 0}
    q = values.quantile([.01, .05, .10, .25, .50, .75, .90, .95, .99])
    return {
        "count": int(values.size),
        "min": float(values.min()),
        "p1": float(q.loc[.01]), "p5": float(q.loc[.05]), "p10": float(q.loc[.10]),
        "p25": float(q.loc[.25]), "median": float(q.loc[.50]),
        "p75": float(q.loc[.75]), "p90": float(q.loc[.90]),
        "p95": float(q.loc[.95]), "p99": float(q.loc[.99]),
        "max": float(values.max()), "mean": float(values.mean()),
        "std": float(values.std(ddof=1)) if len(values) > 1 else 0.0,
    }


def grouped_stats(df: pd.DataFrame, keys: list[str], value: str) -> pd.DataFrame:
    rows = []
    for key, group in df.groupby(keys, dropna=False, sort=True):
        if not isinstance(key, tuple):
            key = (key,)
        item = {column: value_ for column, value_ in zip(keys, key)}
        item.update(stats(group[value]))
        rows.append(item)
    return pd.DataFrame(rows)


def haversine_matrix(lat: np.ndarray, lon: np.ndarray) -> np.ndarray:
    radius = 6371.0088
    lat_r = np.radians(lat)
    lon_r = np.radians(lon)
    dlat = lat_r[:, None] - lat_r[None, :]
    dlon = lon_r[:, None] - lon_r[None, :]
    a = np.sin(dlat / 2) ** 2 + np.cos(lat_r[:, None]) * np.cos(lat_r[None, :]) * np.sin(dlon / 2) ** 2
    return radius * 2 * np.arcsin(np.sqrt(np.clip(a, 0, 1)))


def weighted_median(values: np.ndarray, weights: np.ndarray) -> float:
    mask = np.isfinite(values) & np.isfinite(weights) & (weights > 0)
    values, weights = values[mask], weights[mask]
    if not len(values):
        return float("nan")
    order = np.argsort(values)
    values, weights = values[order], weights[order]
    return float(values[np.searchsorted(np.cumsum(weights), weights.sum() / 2)])


def comparable_score(target: pd.Series, candidates: pd.DataFrame, distances: np.ndarray) -> np.ndarray:
    """Exploratory 0-100 score; thresholds are audit hypotheses, not production rules."""
    score = np.zeros(len(candidates), dtype=float)
    score += np.maximum(0, 25 * (1 - distances / 3.0))

    target_area = float(target["construction_area_m2"])
    candidate_area = candidates["construction_area_m2"].to_numpy(float)
    area_ratio = np.minimum(target_area, candidate_area) / np.maximum(target_area, candidate_area)
    score += np.clip((area_ratio - .70) / .30, 0, 1) * 20

    if target["property_type"] == "casa" and pd.notna(target["land_area_m2"]):
        land = candidates["land_area_m2"].to_numpy(float)
        valid = np.isfinite(land) & (land > 0)
        land_ratio = np.zeros(len(candidates))
        land_ratio[valid] = np.minimum(float(target["land_area_m2"]), land[valid]) / np.maximum(float(target["land_area_m2"]), land[valid])
        score += np.clip((land_ratio - .50) / .50, 0, 1) * 15
    else:
        score += 15

    for column, weight, tolerance in [("bedrooms", 10, 1), ("bathrooms", 8, 1), ("parking_spaces", 5, 1)]:
        delta = np.abs(candidates[column].to_numpy(float) - float(target[column]))
        score += np.clip(1 - delta / (tolerance + 1), 0, 1) * weight
    score += (candidates["neighborhood"].to_numpy(object) == target["neighborhood"]).astype(float) * 10
    score += (candidates["inegi_cve_ageb"].to_numpy(object) == target["inegi_cve_ageb"]).astype(float) * 7
    return score


def prediction_metrics(actual: pd.Series, predicted: pd.Series) -> dict:
    mask = actual.notna() & predicted.notna() & (actual > 0)
    y = actual[mask].to_numpy(float)
    p = predicted[mask].to_numpy(float)
    if not len(y):
        return {"count": 0}
    errors = p - y
    abs_errors = np.abs(errors)
    ape = abs_errors / y
    ss_res = np.sum(errors ** 2)
    ss_tot = np.sum((y - y.mean()) ** 2)
    return {
        "count": int(len(y)), "mae": float(abs_errors.mean()),
        "medae": float(np.median(abs_errors)), "medape": float(np.median(ape)),
        "mape": float(ape.mean()), "rmse": float(np.sqrt(np.mean(errors ** 2))),
        "r2": float(1 - ss_res / ss_tot) if ss_tot else None,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", default="services/avm/data/experiments/avm_cdmx_v1_clean.csv")
    parser.add_argument("--output", default="services/avm/data/experiments/cdmx_market_audit")
    args = parser.parse_args()
    source = Path(args.input)
    out = Path(args.output)
    out.mkdir(parents=True, exist_ok=True)

    df = pd.read_csv(source)
    source_column_count = len(df.columns)
    df["_row_id"] = np.arange(len(df))
    for column in ["price", "construction_area_m2", "land_area_m2", "bedrooms", "bathrooms", "parking_spaces", "latitude", "longitude"]:
        if column in df:
            df[column] = pd.to_numeric(df[column], errors="coerce")
    df["valid_construction"] = df["construction_area_m2"].gt(0)
    df["pp_construction"] = np.where(df["valid_construction"], df["price"] / df["construction_area_m2"], np.nan)
    model_path = Path("services/avm/data/experiments/cdmx_v1/model_best_experimental.joblib")
    if joblib is not None and model_path.exists():
        existing_model = joblib.load(model_path)
        df["ml_prediction"] = np.maximum(np.expm1(existing_model.predict(df)), 0)
    else:
        df["ml_prediction"] = np.nan
    validation_path = Path("services/avm/data/experiments/cdmx_v1/validation_predictions.csv")
    config_path = Path("services/avm/data/experiments/cdmx_v1/best_model_config.json")
    if validation_path.exists() and config_path.exists():
        best_experiment = json.loads(config_path.read_text(encoding="utf-8"))["experiment"]
        validation = pd.read_csv(validation_path)
        validation = validation[validation["experiment"].eq(best_experiment)].drop_duplicates("source_id")
        validation_predictions = validation.set_index("source_id")["predicted_price"]
        df["ml_prediction"] = df["source_id"].map(validation_predictions)

    completeness = []
    for field in REQUIRED_FIELDS:
        present = field in df.columns
        count = int(df[field].notna().sum()) if present else 0
        if present and field in ["latitude", "longitude"]:
            count = int(df[field].notna().sum() & df[field].ne(0).sum()) if False else count
        completeness.append({"field": field, "present_in_schema": present, "non_null": count, "pct": round(count / len(df) * 100, 2)})
    pd.DataFrame(completeness).to_csv(out / "field_completeness.csv", index=False)

    price_groups = []
    for prop, group in df.groupby("property_type"):
        item = {"property_type": prop}
        item.update({f"price_{k}": v for k, v in stats(group["price"]).items()})
        item.update({f"construction_m2_{k}": v for k, v in stats(group["construction_area_m2"]).items()})
        item.update({f"land_m2_{k}": v for k, v in stats(group["land_area_m2"]).items()})
        item.update({f"pp_construction_{k}": v for k, v in stats(group["pp_construction"]).items()})
        price_groups.append(item)
    pd.DataFrame(price_groups).to_csv(out / "price_summary_by_type.csv", index=False)

    grouped_stats(df, ["municipality", "property_type"], "pp_construction").to_csv(out / "market_by_municipality.csv", index=False)
    grouped_stats(df, ["municipality", "neighborhood", "property_type"], "pp_construction").to_csv(out / "market_by_neighborhood.csv", index=False)
    grouped_stats(df, ["inegi_cve_ageb", "property_type"], "pp_construction").to_csv(out / "market_by_ageb.csv", index=False)

    zone_counts = df.groupby(["municipality", "neighborhood", "property_type"], dropna=False).size().reset_index(name="listing_count")
    def bucket(n):
        return "1" if n == 1 else "2-4" if n <= 4 else "5-9" if n <= 9 else "10-19" if n <= 19 else "20+"
    zone_counts["coverage_bucket"] = zone_counts["listing_count"].map(bucket)
    zone_counts.to_csv(out / "neighborhood_coverage.csv", index=False)
    ageb_counts = df.groupby(["inegi_cve_ageb", "property_type"], dropna=False).size().reset_index(name="listing_count")
    ageb_counts["coverage_bucket"] = ageb_counts["listing_count"].map(bucket)
    ageb_counts.to_csv(out / "ageb_coverage.csv", index=False)

    coords = df[["latitude", "longitude"]].to_numpy(float)
    valid_coords = np.isfinite(coords).all(axis=1)
    distance = haversine_matrix(coords[:, 0], coords[:, 1])
    same_type = df["property_type"].to_numpy()[:, None] == df["property_type"].to_numpy()[None, :]
    np.fill_diagonal(same_type, False)

    comparable_rows = []
    retrospective_rows = []
    sample_pool = []
    for i, target in df.iterrows():
        candidates = same_type[i].copy() & valid_coords & valid_coords[i]
        candidates[i] = False
        same_neigh = candidates & (df["neighborhood"].to_numpy() == target["neighborhood"])
        same_ageb = candidates & (df["inegi_cve_ageb"].to_numpy() == target["inegi_cve_ageb"])
        same_municipality = candidates & (df["municipality"].to_numpy() == target["municipality"])
        row = {"row_id": int(target["_row_id"]), "property_type": target["property_type"], "municipality": target["municipality"], "neighborhood": target["neighborhood"], "ageb": target["inegi_cve_ageb"]}
        for name, mask in [("same_neighborhood", same_neigh), ("same_ageb", same_ageb)]:
            row[name] = int(mask.sum())
        row["same_municipality"] = int(same_municipality.sum())
        for radius in [.5, 1, 2, 3]:
            row[f"radius_{str(radius).replace('.', '')}km"] = int((candidates & (distance[i] <= radius)).sum())
        score = comparable_score(target, df.loc[candidates], distance[i][candidates]) if candidates.any() else np.array([])
        score_mask = np.zeros(len(df), dtype=bool)
        candidate_indices = np.flatnonzero(candidates)
        if len(candidate_indices): score_mask[candidate_indices[score >= 65]] = True
        row["similarity_65"] = int(score_mask.sum())
        comparable_rows.append(row)

        selection_masks = {
            "same_neighborhood": same_neigh,
            "same_ageb": same_ageb,
            "same_municipality": same_municipality,
            "radius_500m": candidates & (distance[i] <= .5),
            "radius_1km": candidates & (distance[i] <= 1),
            "radius_2km": candidates & (distance[i] <= 2),
            "radius_3km": candidates & (distance[i] <= 3),
            "similarity_65": score_mask,
        }
        for strategy, mask in selection_masks.items():
            pp = df.loc[mask, "pp_construction"].to_numpy(float)
            pp = pp[np.isfinite(pp) & (pp > 0)]
            if len(pp):
                weights = np.ones(len(pp))
                if strategy == "similarity_65":
                    weights = np.maximum(score[score >= 65], 1) if len(score) else weights
                p25, p50, p75 = np.quantile(pp, [.25, .5, .75])
                market_base = p50 * float(target["construction_area_m2"])
                range_low = p25 * float(target["construction_area_m2"])
                range_high = p75 * float(target["construction_area_m2"])
            else:
                p25 = p50 = p75 = market_base = range_low = range_high = np.nan
            retrospective_rows.append({
                "row_id": int(target["_row_id"]), "property_type": target["property_type"], "municipality": target["municipality"],
                "strategy": strategy, "comparable_count": int(len(pp)), "market_base": market_base,
                "range_low": range_low, "range_high": range_high, "pp_p25": p25, "pp_median": p50, "pp_p75": p75,
                "actual_price": float(target["price"]),
            })

    comparable_df = pd.DataFrame(comparable_rows)
    comparable_df.to_csv(out / "comparable_coverage.csv", index=False)
    retro = pd.DataFrame(retrospective_rows)
    retro["abs_error"] = (retro["market_base"] - retro["actual_price"]).abs()
    retro["ape"] = retro["abs_error"] / retro["actual_price"]
    strategy_metrics = []
    for strategy, group in retro.groupby("strategy"):
        valid = group["market_base"].notna()
        metrics = prediction_metrics(group.loc[valid, "actual_price"], group.loc[valid, "market_base"])
        counts = group.loc[valid, "comparable_count"]
        metrics.update({"strategy": strategy, "coverage_pct": float(valid.mean() * 100), "comparables_median": float(counts.median()) if len(counts) else 0,
                        "comparables_p25": float(counts.quantile(.25)) if len(counts) else 0, "comparables_p75": float(counts.quantile(.75)) if len(counts) else 0,
                        "at_least_3_pct": float((counts >= 3).mean() * 100) if len(counts) else 0, "at_least_5_pct": float((counts >= 5).mean() * 100) if len(counts) else 0,
                        "at_least_10_pct": float((counts >= 10).mean() * 100) if len(counts) else 0, "at_least_20_pct": float((counts >= 20).mean() * 100) if len(counts) else 0})
        strategy_metrics.append(metrics)
    pd.DataFrame(strategy_metrics).to_csv(out / "comparable_strategy_metrics.csv", index=False)

    by_type_metrics = []
    for (prop, strategy), group in retro.groupby(["property_type", "strategy"]):
        valid = group["market_base"].notna()
        metrics = prediction_metrics(group.loc[valid, "actual_price"], group.loc[valid, "market_base"])
        counts = group.loc[valid, "comparable_count"]
        metrics.update({"property_type": prop, "strategy": strategy, "coverage_pct": float(valid.mean() * 100),
                        "comparables_median": float(counts.median()) if len(counts) else 0,
                        "comparables_p25": float(counts.quantile(.25)) if len(counts) else 0,
                        "comparables_p75": float(counts.quantile(.75)) if len(counts) else 0,
                        "at_least_3_pct": float((counts >= 3).mean() * 100) if len(counts) else 0,
                        "at_least_5_pct": float((counts >= 5).mean() * 100) if len(counts) else 0,
                        "at_least_10_pct": float((counts >= 10).mean() * 100) if len(counts) else 0,
                        "at_least_20_pct": float((counts >= 20).mean() * 100) if len(counts) else 0})
        by_type_metrics.append(metrics)
    pd.DataFrame(by_type_metrics).to_csv(out / "comparable_strategy_metrics_by_type.csv", index=False)

    model_comparison = Path("services/avm/data/experiments/cdmx_v1/model_comparison.csv")
    if model_comparison.exists():
        ml = pd.read_csv(model_comparison)
        ml[(ml["validation"] == "spatial") & (ml["experiment"].str.contains("M4_physical_censo_denue__RandomForest__log1p_price", na=False))].to_csv(out / "ml_current_reference.csv", index=False)

    price_q = df["price"].quantile([.33, .67])
    cases = []
    for prop in ["casa", "departamento"]:
        group = df[df["property_type"] == prop]
        for label, mask in [("barata", group.price <= price_q.iloc[0]), ("media", (group.price > price_q.iloc[0]) & (group.price <= price_q.iloc[1])), ("cara", group.price > price_q.iloc[1])]:
            selected = group[mask].sort_values("price").iloc[[0, len(group[mask]) // 2, -1]] if len(group[mask]) >= 3 else group[mask]
            for _, row in selected.iterrows():
                subset = retro[(retro.row_id == row._row_id) & (retro.strategy == "similarity_65")].iloc[0]
                cases.append({"row_id": int(row._row_id), "segment": label, "property_type": row.property_type, "state": row.state, "municipality": row.municipality,
                              "neighborhood": row.neighborhood, "construction_area_m2": row.construction_area_m2, "land_area_m2": row.land_area_m2,
                              "bedrooms": row.bedrooms, "bathrooms": row.bathrooms, "parking_spaces": row.parking_spaces, "listing_price": row.price,
                              "comparables": subset.comparable_count, "market_base": subset.market_base, "range_low": subset.range_low, "range_high": subset.range_high,
                              "ml_prediction": float(row.ml_prediction),
                              "ml_error": float(row.ml_prediction - row.price),
                              "ml_vs_market": float(row.ml_prediction / subset.market_base) if pd.notna(subset.market_base) and subset.market_base else np.nan,
                              "market_error": float(subset.market_base - row.price) if pd.notna(subset.market_base) else np.nan,
                              "hybrid_hypothetical": float((subset.market_base + row.ml_prediction) / 2) if pd.notna(subset.market_base) and pd.notna(row.ml_prediction) else subset.market_base,
                              "hybrid_note": "50/50 ilustrativo, no política productiva"})
    pd.DataFrame(cases).drop_duplicates("row_id").to_csv(out / "sample_cases.csv", index=False)

    keywords = re.compile(r"remate|preventa|oficina|hotel|bodega|edificio|desarrollo|terreno|comercial|renta|departamentos", re.I)
    semantic_hits = df["title"].fillna("").map(lambda value: bool(keywords.search(value)))
    contamination = {"title_keyword_hits": int(semantic_hits.sum()), "by_keyword": {word: int(df.title.fillna('').str.contains(word, case=False, regex=False).sum()) for word in ["remate", "preventa", "oficina", "hotel", "bodega", "edificio", "desarrollo", "terreno", "comercial", "renta", "departamentos"]},
                    "price_m2_classification": df["price_m2_classification"].value_counts(dropna=False).to_dict() if "price_m2_classification" in df else {}}

    summary = {
        "source_dataset": str(source), "rows": len(df), "columns": source_column_count, "source_size_bytes": source.stat().st_size,
        "property_types": df.property_type.value_counts().to_dict(), "municipalities": df.municipality.value_counts().to_dict(),
        "neighborhoods": int(df.neighborhood.nunique()), "ageb": int(df.inegi_cve_ageb.nunique()), "coordinates_valid": int(valid_coords.sum()),
        "price_stats": {key: stats(group.price) for key, group in df.groupby("property_type")},
        "price_m2_stats": {key: stats(group.pp_construction) for key, group in df.groupby("property_type")},
        "neighborhood_coverage_buckets": zone_counts.coverage_bucket.value_counts().to_dict(),
        "ageb_coverage_buckets": ageb_counts.coverage_bucket.value_counts().to_dict(),
        "comparable_strategy_metrics": strategy_metrics, "contamination_indicators": contamination,
        "score_proposal": {"distance_3km": 25, "construction_similarity": 20, "land_similarity_house": 15, "bedrooms": 10, "bathrooms": 8, "parking": 5, "same_neighborhood": 10, "same_ageb": 7, "threshold_tested": 65},
        "confidence_proposal": {"HIGH": ">=10 strong comparables, same neighborhood/AGEB preferred, low IQR, ML/market ratio near 1", "MEDIUM": "5-9 strong comparables or moderate dispersion", "LOW": "<5 comparables, municipality fallback, high dispersion, or material ML/market conflict"},
        "reconciliation_proposal": {"diagnostic_ratios": [1.25, 1.5, 2.0], "policy": "calibrate conflict thresholds retrospectively; do not apply a fixed clamp or multiplier yet"},
        "notes": ["All metrics are retrospective/descriptive and exclude the target row from comparable sets.", "The source CSV was not modified.", "No ML model was trained or generated."]
    }
    write_json(out / "market_summary.json", summary)

    report = f"""# Auditoría de mercado CDMX\n\n## Dataset base\n\nSe utilizó `{source}`: **{len(df)} filas**, {int(df.property_type.eq('casa').sum())} casas y {int(df.property_type.eq('departamento').sum())} departamentos. Tiene {df.municipality.nunique()} alcaldías, {df.neighborhood.nunique()} colonias y {df.inegi_cve_ageb.nunique()} AGEB. Las coordenadas son válidas en {int(valid_coords.sum())}/{len(df)} filas.\n\nLos datasets `avm_v2_*` existentes corresponden a Morelos y no se mezclaron.\n\n## Lectura preliminar\n\nEl precio/m² se calculó como `price / construction_area_m2`, únicamente con construcción positiva. Las tablas por alcaldía, colonia y AGEB se entregan separadas; P25/P50/P75 son las referencias preferidas.\n\nLa cobertura por colonia es: {json.dumps(summary['neighborhood_coverage_buckets'], ensure_ascii=False)}. La cobertura por AGEB es: {json.dumps(summary['ageb_coverage_buckets'], ensure_ascii=False)}.\n\n## Comparables retrospectivos\n\nSe probaron colonia+tipo, AGEB+tipo, radios de 500 m/1 km/2 km/3 km y un score exploratorio >=65. Cada objetivo excluye su propia fila. Ver `comparable_strategy_metrics.csv` para MAE, MedAE, MedAPE, R², cobertura y cantidad de comparables.\n\nEl score propuesto (no productivo) pondera distancia 25, construcción 20, terreno de casa 15, recámaras 10, baños 8, estacionamiento 5, misma colonia 10 y mismo AGEB 7.\n\n## Propuesta híbrida\n\n1. Resolver ubicación y obtener comparables del mismo tipo.\n2. Preferir comparables similares dentro de 1–2 km; usar colonia/AGEB cuando haya muestra suficiente.\n3. Calcular `comparable_median`, `comparable_p25`, `comparable_p75` de precio/m² y multiplicar por construcción; en casas añadir un ancla explícita de terreno.\n4. Usar ML como segunda señal, no como autoridad única.\n5. Si `ml_prediction / market_base` es muy alto, marcar conflicto y reducir confianza; no aplicar un multiplicador fijo sin calibración retrospectiva.\n\n## Limitaciones\n\nEl dataset tiene una sola fuente y una sola moneda, no tiene CP/calle, y la colonia es geocodificada por catálogo. Hay 15 alcaldías: Álvaro Obregón no está representada. Las zonas de una sola observación no permiten una mediana local confiable. `sample_cases.csv` contiene fichas para revisión, pero no incluye predicciones ML porque esta auditoría no ejecuta modelos.\n\nArtefactos generados sólo dentro de `{out}`. No se modificó el CSV fuente, producción ni modelos.\n"""
    (out / "report.md").write_text(report, encoding="utf-8")


if __name__ == "__main__":
    main()
