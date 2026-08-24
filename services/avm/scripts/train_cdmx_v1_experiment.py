#!/usr/bin/env python3
"""Run the isolated first experimental AVM training for CDMX."""

from __future__ import annotations

import argparse
import json
import math
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import HistGradientBoostingRegressor, RandomForestRegressor
from sklearn.impute import SimpleImputer
from sklearn.inspection import permutation_importance
from sklearn.metrics import mean_absolute_error, mean_squared_error, median_absolute_error, r2_score
from sklearn.model_selection import GroupKFold, train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_INPUT = ROOT / "data/experiments/avm_cdmx_v1_clean.csv"
DEFAULT_OUT = ROOT / "data/experiments/cdmx_v1"
SEED = 42

PHYSICAL = ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces"]
CENSO = ["population_density", "housing_density", "car_ownership_ratio", "internet_access_ratio", "average_schooling", "employment_ratio"]
DENUE = ["retail_500m", "retail_1km", "restaurants_hotels_500m", "restaurants_hotels_1km", "health_500m", "health_1km", "education_500m", "education_1km", "financial_500m", "financial_1km", "professional_services_500m", "professional_services_1km"]
FEATURE_SETS = {
    "M1_physical": {"numeric": PHYSICAL, "categorical": ["property_type"]},
    "M2_physical_municipality": {"numeric": PHYSICAL, "categorical": ["property_type", "municipality"]},
    "M3_physical_censo": {"numeric": PHYSICAL + CENSO, "categorical": ["property_type"]},
    "M4_physical_censo_denue": {"numeric": PHYSICAL + CENSO + DENUE, "categorical": ["property_type"]},
    "M5_physical_censo_denue_ageb": {"numeric": PHYSICAL + CENSO + DENUE, "categorical": ["property_type", "inegi_cve_ageb"]},
}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", type=Path, default=DEFAULT_INPUT)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUT)
    args = parser.parse_args()
    args.output_dir.mkdir(parents=True, exist_ok=True)

    df = load_data(args.input)
    config = experiment_config(args.input, df)
    write_json(config, args.output_dir / "config.json")

    comparisons = []
    prediction_frames = {}
    for feature_name, features in FEATURE_SETS.items():
        for algorithm in ("RandomForest", "HistGradientBoosting"):
            for target in ("price", "log1p_price"):
                for validation in ("random", "spatial"):
                    key = f"combined__{feature_name}__{algorithm}__{target}__{validation}"
                    preds, meta = evaluate_combined(df, features, algorithm, target, validation)
                    metrics = metrics_from_predictions(preds)
                    row = {"experiment": key, "family": "combined", "feature_set": feature_name, "algorithm": algorithm, "target": target, "validation": validation, **metrics, **meta}
                    comparisons.append(row)
                    prediction_frames[key] = preds

    for algorithm in ("RandomForest", "HistGradientBoosting"):
        for target in ("price", "log1p_price"):
            for validation in ("random", "spatial"):
                key = f"separate_M4_physical_censo_denue__{algorithm}__{target}__{validation}"
                preds, meta = evaluate_separate(df, FEATURE_SETS["M4_physical_censo_denue"], algorithm, target, validation)
                comparisons.append({"experiment": key, "family": "separate_by_property_type", "feature_set": "M4_physical_censo_denue", "algorithm": algorithm, "target": target, "validation": validation, **metrics_from_predictions(preds), **meta})
                prediction_frames[key] = preds

    baseline_frames = {}
    for validation in ("random", "spatial"):
        for baseline in ("global_median", "property_type_municipality_median"):
            preds = evaluate_baseline(df, validation, baseline)
            key = f"baseline__{baseline}__{validation}"
            baseline_frames[key] = preds
            comparisons.append({"experiment": key, "family": "baseline", "feature_set": "none", "algorithm": baseline, "target": "price", "validation": validation, **metrics_from_predictions(preds), "fallback_count": int(preds.get("baseline_fallback", pd.Series(dtype=bool)).sum())})

    comparison = pd.DataFrame(comparisons)
    comparison.to_csv(args.output_dir / "model_comparison.csv", index=False)
    all_predictions = pd.concat([frame.assign(experiment=key) for key, frame in {**prediction_frames, **baseline_frames}.items()], ignore_index=True)
    all_predictions.to_csv(args.output_dir / "validation_predictions.csv", index=False)

    best_row = choose_best(comparison)
    best_key = best_row["experiment"]
    best_predictions = prediction_frames[best_key]
    best_features = FEATURE_SETS[best_row["feature_set"]]
    best_model = fit_pipeline(df, best_features, best_row["algorithm"], best_row["target"])
    joblib.dump(best_model, args.output_dir / "model_best_experimental.joblib")
    write_json({"experiment": best_key, "features": best_features, "algorithm": best_row["algorithm"], "target": best_row["target"], "n": len(df), "warning": "Experimental only; not connected to production."}, args.output_dir / "best_model_config.json")

    errors = error_analysis(best_predictions)
    errors["all"].to_csv(args.output_dir / "error_analysis_all.csv", index=False)
    errors["top_absolute"].to_csv(args.output_dir / "top20_absolute_errors.csv", index=False)
    errors["top_percentage"].to_csv(args.output_dir / "top20_percentage_errors.csv", index=False)
    errors["by_property_type"].to_csv(args.output_dir / "error_by_property_type.csv", index=False)
    errors["by_municipality"].to_csv(args.output_dir / "error_by_municipality.csv", index=False)
    errors["by_price_band"].to_csv(args.output_dir / "error_by_price_band.csv", index=False)
    errors["by_ageb"].to_csv(args.output_dir / "error_by_ageb.csv", index=False)

    importance = permutation_importance_report(best_model, df, best_features, best_row["target"])
    importance.to_csv(args.output_dir / "feature_importance.csv", index=False)

    suspicious = df[df["price_m2_classification"].eq("suspicious")].copy()
    suspicious_results = suspicious_comparison(df, suspicious, best_row, best_features)
    write_json(suspicious_results, args.output_dir / "suspicious_impact.json")

    metrics = {
        "created_at": now(), "dataset": dataset_summary(df), "best_experiment": best_row.to_dict(),
        "baselines": comparison[comparison.family.eq("baseline")].to_dict(orient="records"),
        "model_comparison": comparison.to_dict(orient="records"),
        "target_comparison": target_comparison(comparison),
        "feature_block_comparison": feature_block_comparison(comparison),
        "separate_vs_combined": separate_comparison(comparison),
        "best_model_metrics": {"random": metrics_for_experiment(comparison, best_row, "random"), "spatial": metrics_for_experiment(comparison, best_row, "spatial")},
        "best_model_segments": {"property_type": errors["by_property_type"].to_dict(orient="records"), "municipality": errors["by_municipality"].to_dict(orient="records"), "price_band": errors["by_price_band"].to_dict(orient="records")},
        "spatial_diagnostics": {"groups": int(df["inegi_cve_ageb"].nunique()), "folds": 5, "same_ageb_across_train_validation": False},
        "suspicious_impact": suspicious_results,
        "morelos_comparison_note": "No se declara superioridad directa: Morelos v2 tiene otra muestra, contrato y validación; sus métricas no son una comparación controlada contra este CSV CDMX.",
    }
    write_json(metrics, args.output_dir / "metrics_complete.json")
    write_json(dataset_summary(df), args.output_dir / "dataset_summary.json")
    (args.output_dir / "report.md").write_text(render_report(metrics, importance, errors), encoding="utf-8")
    print(json.dumps({"rows": len(df), "best_experiment": best_key, "best_random": metrics["best_model_metrics"]["random"], "best_spatial": metrics["best_model_metrics"]["spatial"], "suspicious": len(suspicious), "output_dir": str(args.output_dir)}, indent=2, ensure_ascii=False))
    return 0


def load_data(path: Path) -> pd.DataFrame:
    df = pd.read_csv(path)
    required = {"price", "property_type", "municipality", "inegi_cve_ageb", *PHYSICAL, *CENSO, *DENUE}
    missing = sorted(required - set(df.columns))
    if missing:
        raise ValueError(f"Missing required columns: {missing}")
    df["price"] = pd.to_numeric(df["price"], errors="coerce")
    for column in PHYSICAL + CENSO + DENUE:
        df[column] = pd.to_numeric(df[column], errors="coerce")
    df = df[df["price"].notna() & (df["price"] > 0)].copy().reset_index(drop=True)
    df["inegi_cve_ageb"] = df["inegi_cve_ageb"].astype(str)
    df["group_ageb"] = df[["inegi_cve_ent", "inegi_cve_mun", "inegi_cve_loc", "inegi_cve_ageb"]].astype(str).agg("|".join, axis=1)
    return df


def experiment_config(path: Path, df: pd.DataFrame) -> dict:
    return {"input": str(path), "rows": len(df), "seed": SEED, "target_options": ["price", "log1p_price"], "feature_sets": FEATURE_SETS, "algorithms": {"RandomForest": {"n_estimators": 300, "min_samples_leaf": 3}, "HistGradientBoosting": {"max_iter": 220, "learning_rate": 0.055, "l2_regularization": 0.05}}, "random_validation": {"test_size": 0.2, "stratify": "property_type"}, "spatial_validation": {"method": "GroupKFold", "groups": "CVE_ENT|CVE_MUN|CVE_LOC|CVE_AGEB", "n_splits": 5}}


def make_pipeline(features: dict, algorithm: str) -> Pipeline:
    pre = ColumnTransformer([
        ("num", Pipeline([("imputer", SimpleImputer(strategy="median"))]), features["numeric"]),
        ("cat", Pipeline([("imputer", SimpleImputer(strategy="most_frequent")), ("onehot", OneHotEncoder(handle_unknown="ignore", sparse_output=False))]), features["categorical"]),
    ], remainder="drop", verbose_feature_names_out=False)
    if algorithm == "RandomForest":
        model = RandomForestRegressor(n_estimators=300, min_samples_leaf=3, random_state=SEED, n_jobs=-1)
    elif algorithm == "HistGradientBoosting":
        model = HistGradientBoostingRegressor(max_iter=220, learning_rate=0.055, l2_regularization=0.05, random_state=SEED)
    else:
        raise ValueError(algorithm)
    return Pipeline([("preprocess", pre), ("model", model)])


def target_values(df: pd.DataFrame, target: str) -> pd.Series:
    return np.log1p(df["price"]) if target == "log1p_price" else df["price"]


def fit_predict(train: pd.DataFrame, test: pd.DataFrame, features: dict, algorithm: str, target: str) -> np.ndarray:
    model = make_pipeline(features, algorithm)
    model.fit(train, target_values(train, target))
    prediction = model.predict(test)
    return np.maximum(np.expm1(prediction) if target == "log1p_price" else prediction, 0)


def fit_pipeline(df: pd.DataFrame, features: dict, algorithm: str, target: str) -> Pipeline:
    model = make_pipeline(features, algorithm)
    model.fit(df, target_values(df, target))
    return model


def folds_for(df: pd.DataFrame, validation: str):
    if validation == "random":
        train_idx, test_idx = train_test_split(np.arange(len(df)), test_size=0.2, random_state=SEED, stratify=df["property_type"])
        return [(train_idx, test_idx)]
    splitter = GroupKFold(n_splits=5)
    return list(splitter.split(df, groups=df["group_ageb"]))


def evaluate_combined(df, features, algorithm, target, validation):
    frames = []
    fallback = 0
    for fold, (train_idx, test_idx) in enumerate(folds_for(df, validation), start=1):
        train, test = df.iloc[train_idx], df.iloc[test_idx]
        pred = fit_predict(train, test, features, algorithm, target)
        frames.append(prediction_frame(test, pred, fold, "combined", validation))
    result = pd.concat(frames, ignore_index=True)
    return result, {"folds": len(frames), "fallback_count": fallback}


def evaluate_separate(df, features, algorithm, target, validation):
    frames = []
    for fold, (train_idx, test_idx) in enumerate(folds_for(df, validation), start=1):
        train, test = df.iloc[train_idx], df.iloc[test_idx]
        predicted = np.zeros(len(test), dtype=float)
        for property_type in ("casa", "departamento"):
            train_part = train[train["property_type"].eq(property_type)]
            test_mask = test["property_type"].eq(property_type).to_numpy()
            if test_mask.any():
                predicted[test_mask] = fit_predict(train_part, test[test_mask], {"numeric": features["numeric"], "categorical": []}, algorithm, target)
        frames.append(prediction_frame(test, predicted, fold, "separate_by_property_type", validation))
    return pd.concat(frames, ignore_index=True), {"folds": len(frames), "fallback_count": 0}


def evaluate_baseline(df, validation, baseline):
    frames = []
    fallback_total = 0
    for fold, (train_idx, test_idx) in enumerate(folds_for(df, validation), start=1):
        train, test = df.iloc[train_idx], df.iloc[test_idx]
        global_median = float(train["price"].median())
        if baseline == "global_median":
            pred = np.full(len(test), global_median)
            fallback = np.zeros(len(test), dtype=bool)
        else:
            medians = train.groupby(["property_type", "municipality"])["price"].median().to_dict()
            pred, fallback = [], []
            for _, row in test.iterrows():
                key = (row["property_type"], row["municipality"])
                exists = key in medians
                pred.append(float(medians.get(key, global_median)))
                fallback.append(not exists)
            pred, fallback = np.array(pred), np.array(fallback)
        frame = prediction_frame(test, pred, fold, "baseline", validation)
        frame["baseline_fallback"] = fallback
        fallback_total += int(fallback.sum())
        frames.append(frame)
    result = pd.concat(frames, ignore_index=True)
    return result


def prediction_frame(test, pred, fold, family, validation):
    actual = test["price"].to_numpy(float)
    return pd.DataFrame({"source_id": test["source_id"].values, "property_type": test["property_type"].values, "municipality": test["municipality"].values, "neighborhood": test["neighborhood"].values, "ageb": test["group_ageb"].values, "actual_price": actual, "predicted_price": pred, "absolute_error": np.abs(actual - pred), "percentage_error": np.abs(actual - pred) / np.maximum(actual, 1) * 100, "fold": fold, "family": family, "validation": validation})


def metrics_from_predictions(preds: pd.DataFrame) -> dict:
    actual, predicted = preds["actual_price"].to_numpy(), preds["predicted_price"].to_numpy()
    pct = np.abs(actual - predicted) / np.maximum(np.abs(actual), 1) * 100
    return {"n": int(len(preds)), "mae": float(mean_absolute_error(actual, predicted)), "rmse": float(math.sqrt(mean_squared_error(actual, predicted))), "medae": float(median_absolute_error(actual, predicted)), "mape": float(np.mean(pct)), "median_ape": float(np.median(pct)), "r2": float(r2_score(actual, predicted)), "bias_mean": float(np.mean(predicted - actual)), "within_20_pct": float(np.mean(pct <= 20) * 100)}


def choose_best(comparison: pd.DataFrame) -> pd.Series:
    models = comparison[(comparison.family != "baseline") & comparison.validation.eq("spatial")].copy()
    return models.sort_values(["mae", "medae", "median_ape"], ascending=True).iloc[0]


def metrics_for_experiment(comparison, best_row, validation):
    experiment = str(best_row["experiment"])
    if validation == "random":
        experiment = experiment.rsplit("__spatial", 1)[0] + "__random"
    row = comparison[comparison.experiment.eq(experiment)].iloc[0]
    return {key: clean_value(row[key]) for key in ("experiment", "n", "mae", "rmse", "medae", "mape", "median_ape", "r2", "bias_mean", "within_20_pct")}


def target_comparison(comparison):
    rows = []
    for target, group in comparison[(comparison.family == "combined") & comparison.validation.eq("spatial")].groupby("target"):
        rows.append({"target": target, "mean_mae": group.mae.mean(), "mean_medae": group.medae.mean(), "mean_median_ape": group.median_ape.mean(), "mean_r2": group.r2.mean()})
    return rows


def feature_block_comparison(comparison):
    subset = comparison[(comparison.family == "combined") & comparison.validation.eq("spatial")]
    return subset.groupby("feature_set")[["mae", "medae", "median_ape", "r2"]].mean().reset_index().to_dict(orient="records")


def separate_comparison(comparison):
    subset = comparison[comparison.validation.eq("spatial")]
    return subset.groupby("family")[["mae", "medae", "median_ape", "r2"]].mean().reset_index().to_dict(orient="records")


def error_analysis(preds: pd.DataFrame) -> dict[str, pd.DataFrame]:
    frame = preds.copy()
    frame["price_band"] = pd.cut(frame["actual_price"], bins=[0, 1_000_000, 2_000_000, 3_000_000, 5_000_000, 8_000_000, 12_000_000, 20_000_000, np.inf], labels=["<1M", "1M-2M", "2M-3M", "3M-5M", "5M-8M", "8M-12M", "12M-20M", ">20M"])
    def grouped(column):
        rows = []
        for value, group in frame.groupby(column, observed=False):
            if len(group) >= 3:
                rows.append({column: value, **metrics_from_predictions(group)})
        return pd.DataFrame(rows).sort_values("mae", ascending=False)
    return {"all": frame.sort_values("absolute_error", ascending=False), "top_absolute": frame.sort_values("absolute_error", ascending=False).head(20), "top_percentage": frame.sort_values("percentage_error", ascending=False).head(20), "by_property_type": grouped("property_type"), "by_municipality": grouped("municipality"), "by_price_band": grouped("price_band"), "by_ageb": grouped("ageb")}


def permutation_importance_report(model, df, features, target):
    train, test = train_test_split(df, test_size=0.2, random_state=SEED, stratify=df["property_type"])
    columns = features["numeric"] + features["categorical"]
    result = permutation_importance(model, test[columns], test["price"], n_repeats=10, random_state=SEED, scoring="neg_mean_absolute_error")
    frame = pd.DataFrame({"feature": columns, "importance_mean_mae": result.importances_mean, "importance_std": result.importances_std})
    frame["block"] = frame["feature"].map(feature_block)
    return frame.sort_values("importance_mean_mae", ascending=False)


def feature_block(feature: str) -> str:
    if feature in PHYSICAL or feature == "property_type": return "physical"
    if feature == "municipality": return "administrative_location"
    if feature in CENSO: return "censo"
    if feature in DENUE: return "denue"
    if feature == "inegi_cve_ageb": return "ageb"
    return "other"


def suspicious_comparison(df, suspicious, best_row, features):
    rows = []
    for label, subset in (("with_2_suspicious", df), ("without_2_suspicious", df[~df.source_id.isin(suspicious.source_id)])):
        for validation in ("random", "spatial"):
            preds, _ = evaluate_combined(subset, features, best_row["algorithm"], best_row["target"], validation)
            rows.append({"sample": label, "validation": validation, **metrics_from_predictions(preds)})
    return {"suspicious_rows": suspicious[["source_id", "property_type", "municipality", "price", "price_per_construction_m2"]].to_dict(orient="records"), "metrics": rows}


def dataset_summary(df):
    return {"rows": len(df), "houses": int((df.property_type == "casa").sum()), "apartments": int((df.property_type == "departamento").sum()), "municipalities": int(df.municipality.nunique()), "agebs": int(df.group_ageb.nunique()), "price_min": float(df.price.min()), "price_median": float(df.price.median()), "price_max": float(df.price.max())}


def render_report(metrics, importance, errors):
    best = metrics["best_experiment"]
    random_metrics = metrics["best_model_metrics"]["random"]
    spatial_metrics = metrics["best_model_metrics"]["spatial"]
    lines = ["# AVM CDMX v1 — Primer entrenamiento experimental", "", "Este artefacto es experimental y está aislado; no se conecta a producción.", "", "## Resultado principal", "", f"- Mejor experimento espacial: `{best['experiment']}`", f"- Features: `{best['feature_set']}`", f"- Algoritmo: `{best['algorithm']}`", f"- Target: `{best['target']}`", f"- Random MAE: ${random_metrics['mae']:,.0f}; MedAE: ${random_metrics['medae']:,.0f}; MedAPE: {random_metrics['median_ape']:.1f}%; R²: {random_metrics['r2']:.3f}", f"- Spatial MAE: ${spatial_metrics['mae']:,.0f}; MedAE: ${spatial_metrics['medae']:,.0f}; MedAPE: {spatial_metrics['median_ape']:.1f}%; R²: {spatial_metrics['r2']:.3f}", "", "## Advertencia", "", "La validación espacial por AGEB es la referencia principal. Una mejora en random no se interpreta como generalización si no se mantiene en spatial.", "", "## Comparaciones", "", f"- Target: `{json.dumps(metrics['target_comparison'], ensure_ascii=False)}`", f"- Bloques: `{json.dumps(metrics['feature_block_comparison'], ensure_ascii=False)}`", f"- Conjunto vs separado: `{json.dumps(metrics['separate_vs_combined'], ensure_ascii=False)}`", "", "## Importancia", "", importance.head(15).to_csv(index=False), "", "## Cobertura de errores", "", errors["by_property_type"].to_csv(index=False), errors["by_municipality"].to_csv(index=False), "", "## Suspicious", json.dumps(metrics["suspicious_impact"], ensure_ascii=False, indent=2), "", "## Morelos", metrics["morelos_comparison_note"], ""]
    return "\n".join(lines)


def clean_value(value):
    return value.item() if hasattr(value, "item") else value


def write_json(data, path: Path):
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2, default=clean_value) + "\n", encoding="utf-8")


def now():
    return datetime.now(timezone.utc).isoformat()


if __name__ == "__main__":
    raise SystemExit(main())
