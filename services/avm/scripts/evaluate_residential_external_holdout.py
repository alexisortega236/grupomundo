#!/usr/bin/env python3
from __future__ import annotations

import json
import math
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.metrics import mean_absolute_error, mean_squared_error, median_absolute_error, r2_score


ROOT = Path(__file__).resolve().parents[1]
HOLDOUT = ROOT / "data" / "experiments" / "avm_v2_residential_external_holdout.csv"
MODEL = ROOT / "experiments" / "avm_v2_v2" / "model_residential_experimental.joblib"
PREVIOUS_OOF = ROOT / "experiments" / "avm_v2_v2" / "predictions_residential.csv"
PREVIOUS_BOOTSTRAP = ROOT / "experiments" / "avm_v2_v2" / "residential_validation" / "bootstrap_metrics.json"
OUT = ROOT / "experiments" / "avm_v2_v2" / "external_validation"

FEATURES = [
    "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces",
    "population_density", "housing_density", "car_ownership_ratio", "internet_access_ratio",
    "average_schooling", "employment_ratio",
    "establishments_500m", "establishments_1km", "retail_500m", "retail_1km",
    "restaurants_hotels_500m", "restaurants_hotels_1km", "health_500m", "health_1km",
    "education_500m", "education_1km", "financial_500m", "financial_1km",
    "professional_services_500m", "professional_services_1km",
    "property_type", "municipality", "inegi_cve_ageb",
]
PRICE_BANDS = ["<1M", "1M-2M", "2M-3M", "3M-5M", "5M-8M", "8M-12M", "12M-20M", ">20M"]


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    holdout = pd.read_csv(HOLDOUT)
    if holdout.empty:
        raise SystemExit("Holdout vacío; no hay registros nuevos elegibles para evaluar.")
    model = joblib.load(MODEL)
    previous = pd.read_csv(PREVIOUS_OOF)
    for col in FEATURES + ["price"]:
        if col in holdout.columns and col not in {"property_type", "municipality", "inegi_cve_ageb"}:
            holdout[col] = pd.to_numeric(holdout[col], errors="coerce")
    pred = np.maximum(np.expm1(model.predict(holdout[FEATURES])), 1)
    predictions = prediction_frame(holdout, pred)
    predictions = add_intervals(predictions, previous)
    predictions.to_csv(OUT / "external_predictions.csv", index=False)

    metrics = {
        "created_at": now(),
        "holdout": {
            "n": int(len(predictions)),
            "by_source": counts(predictions, "source"),
            "by_property_type": counts(predictions, "property_type"),
            "by_municipality": counts(predictions, "municipality"),
            "by_price_band": counts(predictions, "price_band"),
        },
        "overall": metric_dict(predictions),
        "reference_cv": {
            "mae": 1460673.4719746816,
            "r2": 0.7037420628675256,
            "mape": 28.790510871934774,
            "within_20_pct": 44.680851063829785,
            "within_30_pct": 63.829787234042556,
        },
        "bootstrap_ci95": json.loads(PREVIOUS_BOOTSTRAP.read_text(encoding="utf-8")) if PREVIOUS_BOOTSTRAP.exists() else None,
        "classification": classify(metric_dict(predictions)),
    }
    (OUT / "metrics.json").write_text(json.dumps(metrics, ensure_ascii=False, indent=2), encoding="utf-8")
    group_table(predictions, "property_type").to_csv(OUT / "error_by_property_type.csv", index=False)
    group_table(predictions, "municipality").to_csv(OUT / "error_by_municipality.csv", index=False)
    price_band_table(predictions).to_csv(OUT / "error_by_price_band.csv", index=False)
    interval_coverage(predictions).to_csv(OUT / "interval_coverage.csv", index=False)
    predictions.sort_values(["percentage_error", "absolute_error"], ascending=False).head(20).to_csv(OUT / "worst_cases.csv", index=False)
    predictions.sort_values(["percentage_error", "absolute_error"], ascending=True).head(20).to_csv(OUT / "best_cases.csv", index=False)
    write_readme(metrics)
    print(json.dumps(metrics, ensure_ascii=False, indent=2))
    return 0


def prediction_frame(df: pd.DataFrame, pred: np.ndarray) -> pd.DataFrame:
    actual = df["price"].to_numpy()
    abs_error = np.abs(actual - pred)
    pct_error = abs_error / np.maximum(actual, 1) * 100
    out = df.copy()
    out["actual_price"] = actual
    out["predicted_price"] = pred
    out["absolute_error"] = abs_error
    out["percentage_error"] = pct_error
    out["prediction_ratio"] = pred / np.maximum(actual, 1)
    out["residual"] = pred - actual
    return out


def add_intervals(predictions: pd.DataFrame, previous: pd.DataFrame) -> pd.DataFrame:
    global_p80 = np.percentile(previous["percentage_error"], 80) / 100
    global_p90 = np.percentile(previous["percentage_error"], 90) / 100
    low80 = []
    high80 = []
    low90 = []
    high90 = []
    for _, row in predictions.iterrows():
        subset = previous[previous["property_type"] == row["property_type"]]
        if len(subset) < 20:
            subset = previous
        p80 = np.percentile(subset["percentage_error"], 80) / 100 if len(subset) else global_p80
        p90 = np.percentile(subset["percentage_error"], 90) / 100 if len(subset) else global_p90
        pred = row["predicted_price"]
        low80.append(pred * (1 - p80))
        high80.append(pred * (1 + p80))
        low90.append(pred * (1 - p90))
        high90.append(pred * (1 + p90))
    predictions["range_80_low"] = low80
    predictions["range_80_high"] = high80
    predictions["range_90_low"] = low90
    predictions["range_90_high"] = high90
    predictions["inside_80_range"] = (predictions["actual_price"] >= predictions["range_80_low"]) & (predictions["actual_price"] <= predictions["range_80_high"])
    predictions["inside_90_range"] = (predictions["actual_price"] >= predictions["range_90_low"]) & (predictions["actual_price"] <= predictions["range_90_high"])
    return predictions


def metric_dict(df: pd.DataFrame) -> dict:
    actual = df["actual_price"].to_numpy()
    pred = df["predicted_price"].to_numpy()
    pct = df["percentage_error"].to_numpy()
    residual = pred - actual
    return {
        "n": int(len(df)),
        "mae": float(mean_absolute_error(actual, pred)),
        "median_ae": float(median_absolute_error(actual, pred)),
        "rmse": float(math.sqrt(mean_squared_error(actual, pred))),
        "r2": float(r2_score(actual, pred)) if len(df) >= 2 else None,
        "mape": float(np.mean(pct)),
        "median_absolute_percentage_error": float(np.median(pct)),
        "within_10_pct": float(np.mean(pct <= 10) * 100),
        "within_20_pct": float(np.mean(pct <= 20) * 100),
        "within_30_pct": float(np.mean(pct <= 30) * 100),
        "bias_mean": float(np.mean(residual)),
        "bias_median": float(np.median(residual)),
        "median_prediction_ratio": float(np.median(df["prediction_ratio"])),
    }


def group_table(df: pd.DataFrame, column: str) -> pd.DataFrame:
    rows = []
    for value, group in df.groupby(column):
        row = {column: value, "sample_quality": "LOW_SAMPLE" if len(group) < 10 else "OK", **metric_dict(group)}
        rows.append(row)
    return pd.DataFrame(rows).sort_values(["sample_quality", column])


def price_band_table(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for band in PRICE_BANDS:
        group = df[df["price_band"] == band]
        if group.empty:
            continue
        rows.append({"price_band": band, "sample_quality": "LOW_SAMPLE" if len(group) < 10 else "OK", **metric_dict(group)})
    return pd.DataFrame(rows)


def interval_coverage(df: pd.DataFrame) -> pd.DataFrame:
    return pd.DataFrame([
        {
            "method": "property_type_pct_with_global_fallback",
            "coverage_80": float(df["inside_80_range"].mean() * 100),
            "coverage_90": float(df["inside_90_range"].mean() * 100),
            "mean_width_80": float((df["range_80_high"] - df["range_80_low"]).mean()),
            "median_width_80": float((df["range_80_high"] - df["range_80_low"]).median()),
            "mean_width_90": float((df["range_90_high"] - df["range_90_low"]).mean()),
            "median_width_90": float((df["range_90_high"] - df["range_90_low"]).median()),
        }
    ])


def classify(overall: dict) -> str:
    if overall["within_20_pct"] < 25 or overall["mape"] > 60:
        return "A_FALLA_EN_DATOS_NUEVOS"
    if overall["mape"] <= 33.106689231084 and overall["within_20_pct"] >= 37.765957446808514 and overall["within_30_pct"] >= 56.91489361702128:
        return "C_RESULTADO_CONSISTENTE_CON_VALIDACION_PREVIA"
    if overall["mae"] > 2200000 or overall["mape"] > 40 or overall["within_20_pct"] < 35:
        return "B_SENAL_EXISTE_PERO_DEGRADA_FUERTEMENTE"
    return "B_SENAL_EXISTE_PERO_DEGRADA_FUERTEMENTE"


def counts(df: pd.DataFrame, column: str) -> dict[str, int]:
    return {str(k): int(v) for k, v in df[column].fillna("missing").value_counts().items()}


def write_readme(metrics: dict) -> None:
    content = f"""# External Residential Holdout

Evaluación prospectiva sin reentrenar `model_residential_experimental.joblib`.

{json.dumps(metrics, ensure_ascii=False, indent=2)}
"""
    (OUT / "README.md").write_text(content, encoding="utf-8")


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


if __name__ == "__main__":
    raise SystemExit(main())
