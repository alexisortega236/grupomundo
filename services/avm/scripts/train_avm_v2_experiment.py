#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
import shutil
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.ensemble import GradientBoostingRegressor, HistGradientBoostingRegressor, RandomForestRegressor
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
from sklearn.inspection import permutation_importance
from sklearn.metrics import mean_absolute_error, mean_squared_error, median_absolute_error, r2_score
from sklearn.model_selection import GroupKFold, GroupShuffleSplit
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder


ROOT = Path(__file__).resolve().parents[1]
INPUT = ROOT / "data" / "avm_training_candidates.csv"
DATASET_OUT = ROOT / "data" / "experiments" / "avm_v2_dataset_v1.csv"
DATASET_META_OUT = ROOT / "data" / "experiments" / "avm_v2_dataset_v1_metadata.json"
EXPERIMENT_DIR = ROOT / "experiments" / "avm_v2_v1"
RANDOM_STATE = 42

BASIC_NUMERIC = [
    "land_area_m2",
    "construction_area_m2",
    "bedrooms",
    "bathrooms",
    "parking_spaces",
]
BASIC_CATEGORICAL = ["property_type", "municipality"]
CENSO_NUMERIC = [
    "population_density",
    "housing_density",
    "car_ownership_ratio",
    "internet_access_ratio",
    "average_schooling",
    "employment_ratio",
]
DENUE_NUMERIC = [
    "establishments_500m",
    "establishments_1km",
    "retail_500m",
    "retail_1km",
    "restaurants_hotels_500m",
    "restaurants_hotels_1km",
    "health_500m",
    "health_1km",
    "education_500m",
    "education_1km",
    "financial_500m",
    "financial_1km",
    "professional_services_500m",
    "professional_services_1km",
]
SPATIAL_CATEGORICAL = ["inegi_cve_ageb", "neighborhood", "coordinate_quality"]

FEATURE_GROUPS = {
    "physical": {
        "numeric": BASIC_NUMERIC,
        "categorical": BASIC_CATEGORICAL,
    },
    "physical_geography": {
        "numeric": BASIC_NUMERIC,
        "categorical": BASIC_CATEGORICAL + ["inegi_cve_ageb", "coordinate_quality"],
    },
    "physical_censo": {
        "numeric": BASIC_NUMERIC + CENSO_NUMERIC,
        "categorical": BASIC_CATEGORICAL + ["inegi_cve_ageb", "coordinate_quality"],
    },
    "physical_censo_denue": {
        "numeric": BASIC_NUMERIC + CENSO_NUMERIC + DENUE_NUMERIC,
        "categorical": BASIC_CATEGORICAL + SPATIAL_CATEGORICAL,
    },
}
QUALITY_OUTLIER_FLAGS = {
    "invalid_price",
    "invalid_land_area",
    "invalid_construction_area",
    "suspicious_construction_area",
    "suspicious_land_area",
    "suspicious_price_m2",
    "suspicious_price_per_construction_m2",
    "suspicious_price_per_land_m2",
}


def main() -> int:
    parser = argparse.ArgumentParser(description="Entrena y evalua AVM v2 experimental sin tocar el modelo productivo.")
    parser.add_argument("--input", default=str(INPUT))
    parser.add_argument("--experiment-dir", default=str(EXPERIMENT_DIR))
    args = parser.parse_args()

    experiment_dir = Path(args.experiment_dir)
    experiment_dir.mkdir(parents=True, exist_ok=True)
    (ROOT / "data" / "experiments").mkdir(parents=True, exist_ok=True)

    raw = pd.read_csv(args.input)
    frozen = freeze_dataset(raw)
    DATASET_OUT.parent.mkdir(parents=True, exist_ok=True)
    frozen.to_csv(DATASET_OUT, index=False)
    metadata = dataset_metadata(raw, frozen)
    DATASET_META_OUT.write_text(json.dumps(metadata, ensure_ascii=False, indent=2), encoding="utf-8")

    prepared = prepare_training_dataset(frozen)
    deduped, duplicate_report = apply_training_deduplication(prepared)
    outlier_filtered, excluded_outliers = exclude_quality_outliers(deduped)
    holdout_train, holdout_test = holdout_split(deduped)

    results: dict[str, object] = {
        "created_at": now_iso(),
        "records_initial": int(len(raw)),
        "records_after_target_filters": int(len(prepared)),
        "records_after_deduplication": int(len(deduped)),
        "records_outlier_filtered": int(len(outlier_filtered)),
        "holdout_size": int(len(holdout_test)),
        "duplicate_groups": duplicate_report,
        "excluded_outliers": excluded_outliers,
        "dataset": summarize_frame(deduped),
        "target_distribution": numeric_summary(deduped["price"]),
        "area_distribution": {
            "land_area_m2": numeric_summary(deduped["land_area_m2"]),
            "construction_area_m2": numeric_summary(deduped["construction_area_m2"]),
            "price_per_construction_m2": numeric_summary(deduped["price_per_construction_m2"]),
            "price_per_land_m2": numeric_summary(deduped["price_per_land_m2"]),
        },
    }

    baselines = evaluate_baselines(deduped)
    model_results, prediction_frames, fitted_models = evaluate_models(deduped, FEATURE_GROUPS["physical_censo_denue"])
    ablations = {
        name: evaluate_models(deduped, group, algorithms={"HistGradientBoosting": model_factory("HistGradientBoosting")})[0]["HistGradientBoosting"]["metrics"]
        for name, group in FEATURE_GROUPS.items()
    }
    target_comparison = compare_target_transforms(deduped, FEATURE_GROUPS["physical_censo_denue"])
    outlier_comparison = {
        "with_outliers": model_results["HistGradientBoosting"]["metrics"],
        "quality_outliers_excluded": evaluate_models(outlier_filtered, FEATURE_GROUPS["physical_censo_denue"], algorithms={"HistGradientBoosting": model_factory("HistGradientBoosting")})[0]["HistGradientBoosting"]["metrics"] if len(outlier_filtered) >= 30 else None,
    }
    houses_only = None
    houses = deduped[deduped["property_type"] == "casa"].copy()
    if len(houses) >= 50:
        houses_only = evaluate_models(houses, FEATURE_GROUPS["physical_censo_denue"], algorithms={"HistGradientBoosting": model_factory("HistGradientBoosting")})[0]["HistGradientBoosting"]["metrics"]

    best_name = min(model_results, key=lambda name: model_results[name]["metrics"]["mae"])
    best_predictions = prediction_frames[best_name].copy()
    best_predictions.to_csv(experiment_dir / "predictions.csv", index=False)
    feature_importance = compute_feature_importance(
        fitted_models[best_name],
        holdout_train,
        holdout_test,
        FEATURE_GROUPS["physical_censo_denue"],
    )
    feature_importance.to_csv(experiment_dir / "feature_importance.csv", index=False)
    joblib.dump(fitted_models[best_name], experiment_dir / "model_experimental.joblib")

    results.update({
        "baselines": baselines,
        "models": model_results,
        "best_model": best_name,
        "ablation": ablations,
        "target_comparison": target_comparison,
        "outlier_comparison": outlier_comparison,
        "houses_only_experiment": houses_only,
        "segment_metrics": segment_metrics(best_predictions, deduped),
        "legacy_comparison": legacy_comparison_note(),
        "worst_predictions": best_predictions.sort_values("absolute_error", ascending=False).head(10).to_dict(orient="records"),
    })

    (experiment_dir / "metrics.json").write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")
    (experiment_dir / "dataset_summary.json").write_text(json.dumps(results["dataset"], ensure_ascii=False, indent=2), encoding="utf-8")
    write_readme(experiment_dir, results)
    print_summary(results, experiment_dir)
    return 0


def freeze_dataset(raw: pd.DataFrame) -> pd.DataFrame:
    frozen = raw.copy()
    frozen["experiment_frozen_at"] = now_iso()
    frozen["duplicate_group_id"] = build_duplicate_groups(frozen)
    return frozen


def dataset_metadata(raw: pd.DataFrame, frozen: pd.DataFrame) -> dict:
    return {
        "created_at": now_iso(),
        "input": str(INPUT),
        "output": str(DATASET_OUT),
        "records": int(len(frozen)),
        "distribution_by_source": counts(frozen, "source"),
        "distribution_by_municipality": counts(frozen, "municipality"),
        "distribution_by_type": counts(frozen, "property_type"),
        "distribution_by_training_readiness": counts(frozen, "training_readiness"),
        "columns_used": sorted(set(sum([group["numeric"] + group["categorical"] for group in FEATURE_GROUPS.values()], []))),
        "filters_applied": [
            "training_readiness in A/B/C from avm_training_candidates.csv",
            "currency must be MXN",
            "price must be present and > 0",
            "duplicate groups kept together in validation splits",
            "one representative retained for clearly repeated duplicate_group_id in experimental deduped dataset",
        ],
        "deduplication_rules": [
            "group by source, price, municipality, neighborhood, land_area_m2, construction_area_m2, bedrooms, bathrooms",
            "duplicate_group_id assigned before split",
            "first record kept for training when duplicate group has more than one row",
        ],
        "source_records_original": int(len(raw)),
    }


def prepare_training_dataset(df: pd.DataFrame) -> pd.DataFrame:
    prepared = df.copy()
    prepared = prepared[(prepared["currency"].fillna("MXN") == "MXN")]
    prepared["price"] = pd.to_numeric(prepared["price"], errors="coerce")
    prepared = prepared[prepared["price"].notna() & (prepared["price"] > 0)]
    for column in numeric_columns():
        if column in prepared:
            prepared[column] = pd.to_numeric(prepared[column], errors="coerce")
    prepared["quality_flags_list"] = prepared["quality_flags"].fillna("").apply(lambda value: [item for item in str(value).split(",") if item])
    return prepared.reset_index(drop=True)


def build_duplicate_groups(df: pd.DataFrame) -> list[str]:
    groups = []
    for _, row in df.iterrows():
        parts = [
            norm(row.get("source")),
            norm(row.get("price")),
            norm(row.get("municipality")),
            norm(row.get("neighborhood")),
            norm(row.get("land_area_m2")),
            norm(row.get("construction_area_m2")),
            norm(row.get("bedrooms")),
            norm(row.get("bathrooms")),
        ]
        groups.append("|".join(parts))
    return groups


def apply_training_deduplication(df: pd.DataFrame) -> tuple[pd.DataFrame, dict]:
    group_sizes = df.groupby("duplicate_group_id").size()
    duplicate_groups = group_sizes[group_sizes > 1]
    kept = df.sort_values(["duplicate_group_id", "source_id"]).drop_duplicates("duplicate_group_id", keep="first").reset_index(drop=True)
    examples = []
    for group_id in duplicate_groups.index[:20]:
        rows = df[df["duplicate_group_id"] == group_id]
        example_columns = [column for column in ["source", "source_id", "title", "price", "municipality", "neighborhood", "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms"] if column in rows.columns]
        examples.append({
            "duplicate_group_id": group_id,
            "count": int(len(rows)),
            "records": rows[example_columns].to_dict(orient="records"),
        })
    return kept, {
        "groups_with_duplicates": int(len(duplicate_groups)),
        "records_in_duplicate_groups": int(duplicate_groups.sum()) if len(duplicate_groups) else 0,
        "records_removed_for_experiment": int(len(df) - len(kept)),
        "examples": examples,
    }


def exclude_quality_outliers(df: pd.DataFrame) -> tuple[pd.DataFrame, list[dict]]:
    mask = df["quality_flags_list"].apply(lambda flags: bool(set(flags) & QUALITY_OUTLIER_FLAGS))
    columns = [column for column in ["source", "source_id", "title", "price", "municipality", "property_type", "quality_flags"] if column in df.columns]
    excluded = df[mask][columns].to_dict(orient="records")
    return df[~mask].reset_index(drop=True), excluded


def holdout_split(df: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame]:
    splitter = GroupShuffleSplit(n_splits=1, test_size=0.18, random_state=RANDOM_STATE)
    train_idx, test_idx = next(splitter.split(df, groups=df["duplicate_group_id"]))
    return df.iloc[train_idx].copy(), df.iloc[test_idx].copy()


def evaluate_baselines(df: pd.DataFrame) -> dict:
    folds = group_folds(df)
    rows = []
    for fold, (train_idx, test_idx) in enumerate(folds):
        train = df.iloc[train_idx]
        test = df.iloc[test_idx]
        y_true = test["price"].to_numpy()
        predictions = {
            "global_median": np.repeat(train["price"].median(), len(test)),
            "property_type_median": predict_group_median(train, test, ["property_type"]),
            "municipality_property_type_median": predict_group_median(train, test, ["municipality", "property_type"], min_count=5),
        }
        for name, y_pred in predictions.items():
            rows.append(metrics_row(name, fold, test, y_true, y_pred))
    return aggregate_metric_rows(rows)


def evaluate_models(df: pd.DataFrame, feature_group: dict, algorithms: dict | None = None, log_target: bool = False):
    algorithms = algorithms or {
        "RandomForest": model_factory("RandomForest"),
        "HistGradientBoosting": model_factory("HistGradientBoosting"),
        "GradientBoosting": model_factory("GradientBoosting"),
    }
    folds = group_folds(df)
    model_results = {}
    prediction_frames = {}
    fitted_models = {}
    for name, regressor in algorithms.items():
        rows = []
        predictions = []
        for fold, (train_idx, test_idx) in enumerate(folds):
            train = df.iloc[train_idx]
            test = df.iloc[test_idx]
            model = make_pipeline(feature_group, regressor)
            y_train = np.log1p(train["price"]) if log_target else train["price"]
            model.fit(train, y_train)
            raw_pred = model.predict(test)
            y_pred = np.expm1(raw_pred) if log_target else raw_pred
            y_pred = np.maximum(y_pred, 1)
            rows.append(metrics_row(name, fold, test, test["price"].to_numpy(), y_pred))
            predictions.append(prediction_frame(test, y_pred, fold))
        full_model = make_pipeline(feature_group, model_factory(name))
        full_target = np.log1p(df["price"]) if log_target else df["price"]
        full_model.fit(df, full_target)
        model_results[name] = {"metrics": aggregate_metric_rows(rows)[name], "log_target": log_target}
        prediction_frames[name] = pd.concat(predictions, ignore_index=True)
        fitted_models[name] = full_model
    return model_results, prediction_frames, fitted_models


def compare_target_transforms(df: pd.DataFrame, feature_group: dict) -> dict:
    raw = evaluate_models(df, feature_group, algorithms={"HistGradientBoosting": model_factory("HistGradientBoosting")}, log_target=False)[0]["HistGradientBoosting"]["metrics"]
    logged = evaluate_models(df, feature_group, algorithms={"HistGradientBoosting": model_factory("HistGradientBoosting")}, log_target=True)[0]["HistGradientBoosting"]["metrics"]
    return {"price_direct": raw, "log1p_price": logged}


def model_factory(name: str):
    if name == "RandomForest":
        return RandomForestRegressor(n_estimators=350, min_samples_leaf=3, random_state=RANDOM_STATE, n_jobs=-1)
    if name == "HistGradientBoosting":
        return HistGradientBoostingRegressor(max_iter=220, learning_rate=0.055, l2_regularization=0.05, random_state=RANDOM_STATE)
    if name == "GradientBoosting":
        return GradientBoostingRegressor(n_estimators=220, learning_rate=0.055, max_depth=3, random_state=RANDOM_STATE)
    raise ValueError(f"Modelo no soportado: {name}")


def make_pipeline(feature_group: dict, regressor) -> Pipeline:
    numeric = [col for col in feature_group["numeric"] if col]
    categorical = [col for col in feature_group["categorical"] if col]
    preprocessor = ColumnTransformer(
        transformers=[
            ("num", Pipeline([("imputer", SimpleImputer(strategy="median"))]), numeric),
            ("cat", Pipeline([
                ("imputer", SimpleImputer(strategy="most_frequent")),
                ("onehot", OneHotEncoder(handle_unknown="ignore", sparse_output=False)),
            ]), categorical),
        ],
        remainder="drop",
        verbose_feature_names_out=False,
    )
    return Pipeline([("preprocess", preprocessor), ("model", regressor)])


def group_folds(df: pd.DataFrame):
    groups = df["duplicate_group_id"]
    n_groups = groups.nunique()
    n_splits = min(5, n_groups)
    return list(GroupKFold(n_splits=n_splits).split(df, groups=groups))


def predict_group_median(train: pd.DataFrame, test: pd.DataFrame, group_cols: list[str], min_count: int = 1) -> np.ndarray:
    global_median = train["price"].median()
    stats = train.groupby(group_cols)["price"].agg(["median", "count"]).reset_index()
    predictions = []
    for _, row in test.iterrows():
        match = stats
        for col in group_cols:
            match = match[match[col] == row[col]]
        if not match.empty and int(match.iloc[0]["count"]) >= min_count:
            predictions.append(float(match.iloc[0]["median"]))
        else:
            predictions.append(float(global_median))
    return np.array(predictions)


def metrics_row(name: str, fold: int, test: pd.DataFrame, y_true: np.ndarray, y_pred: np.ndarray) -> dict:
    abs_error = np.abs(y_true - y_pred)
    pct_error = abs_error / np.maximum(y_true, 1)
    return {
        "model": name,
        "fold": fold,
        "mae": float(mean_absolute_error(y_true, y_pred)),
        "median_ae": float(median_absolute_error(y_true, y_pred)),
        "rmse": float(math.sqrt(mean_squared_error(y_true, y_pred))),
        "r2": float(r2_score(y_true, y_pred)),
        "mape": float(np.mean(pct_error) * 100),
        "median_absolute_percentage_error": float(np.median(pct_error) * 100),
        "within_10_pct": float(np.mean(pct_error <= 0.10) * 100),
        "within_20_pct": float(np.mean(pct_error <= 0.20) * 100),
        "within_30_pct": float(np.mean(pct_error <= 0.30) * 100),
        "n": int(len(test)),
    }


def aggregate_metric_rows(rows: list[dict]) -> dict:
    frame = pd.DataFrame(rows)
    result = {}
    for name, group in frame.groupby("model"):
        result[name] = {
            metric: float(group[metric].mean())
            for metric in ["mae", "median_ae", "rmse", "r2", "mape", "median_absolute_percentage_error", "within_10_pct", "within_20_pct", "within_30_pct"]
        }
        result[name]["n"] = int(group["n"].sum())
        result[name]["folds"] = int(group["fold"].nunique())
    return result


def prediction_frame(test: pd.DataFrame, y_pred: np.ndarray, fold: int) -> pd.DataFrame:
    actual = test["price"].to_numpy()
    absolute_error = np.abs(actual - y_pred)
    percentage_error = absolute_error / np.maximum(actual, 1) * 100
    return pd.DataFrame({
        "source": test["source"].values,
        "source_id": test["source_id"].values,
        "property_type": test["property_type"].values,
        "municipality": test["municipality"].values,
        "training_readiness": test["training_readiness"].values,
        "coordinate_quality": test["coordinate_quality"].values,
        "actual_price": actual,
        "predicted_price": y_pred,
        "absolute_error": absolute_error,
        "percentage_error": percentage_error,
        "fold": fold,
    })


def compute_feature_importance(model: Pipeline, train: pd.DataFrame, test: pd.DataFrame, feature_group: dict) -> pd.DataFrame:
    if len(test) < 5:
        return pd.DataFrame(columns=["feature", "importance_mean", "importance_std"])
    result = permutation_importance(model, test, test["price"], n_repeats=20, random_state=RANDOM_STATE, scoring="neg_mean_absolute_error")
    features = list(test.columns)
    if len(features) != len(result.importances_mean):
        features = [f"feature_{index}" for index in range(len(result.importances_mean))]
    return pd.DataFrame({
        "feature": features,
        "importance_mean": result.importances_mean,
        "importance_std": result.importances_std,
    }).sort_values("importance_mean", ascending=False).head(20)


def segment_metrics(predictions: pd.DataFrame, df: pd.DataFrame) -> dict:
    segments = {}
    for column in ["property_type", "municipality", "coordinate_quality", "training_readiness", "source"]:
        values = {}
        for value, group in predictions.groupby(column):
            if len(group) < 5:
                continue
            values[str(value)] = prediction_metrics(group)
        segments[column] = values
    return segments


def prediction_metrics(group: pd.DataFrame) -> dict:
    y_true = group["actual_price"].to_numpy()
    y_pred = group["predicted_price"].to_numpy()
    return metrics_row("segment", 0, group, y_true, y_pred)


def legacy_comparison_note() -> dict:
    return {
        "status": "not_comparable",
        "reason": "El modelo legacy app/model/modelo_precio.joblib requiere contrato legacy con colonia COL_XX y features entrenadas en otro catálogo. No se inventaron COL_XX para los 168 registros reales, por lo que una comparación directa contra los mismos registros no es válida.",
    }


def summarize_frame(df: pd.DataFrame) -> dict:
    return {
        "records": int(len(df)),
        "by_source": counts(df, "source"),
        "by_municipality": counts(df, "municipality"),
        "by_type": counts(df, "property_type"),
        "by_training_readiness": counts(df, "training_readiness"),
        "by_coordinate_quality": counts(df, "coordinate_quality"),
    }


def numeric_summary(series: pd.Series) -> dict:
    values = pd.to_numeric(series, errors="coerce").dropna()
    if values.empty:
        return {"count": 0}
    return {
        "count": int(values.count()),
        "min": float(values.min()),
        "p25": float(values.quantile(0.25)),
        "median": float(values.median()),
        "mean": float(values.mean()),
        "p75": float(values.quantile(0.75)),
        "max": float(values.max()),
    }


def counts(df: pd.DataFrame, column: str) -> dict:
    return {str(key): int(value) for key, value in Counter(df[column].fillna("missing")).most_common()}


def numeric_columns() -> list[str]:
    return sorted(set(BASIC_NUMERIC + CENSO_NUMERIC + DENUE_NUMERIC + ["price", "price_per_construction_m2", "price_per_land_m2"]))


def norm(value) -> str:
    if pd.isna(value):
        return ""
    if isinstance(value, float):
        return str(round(value, 2))
    return str(value).strip().lower()


def now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def write_readme(experiment_dir: Path, results: dict) -> None:
    best = results["models"][results["best_model"]]["metrics"]
    content = f"""# AVM v2 Experimental v1

Este experimento fue generado con datos reales de `data/avm_training_candidates.csv`.

No sustituye el modelo productivo `app/model/modelo_precio.joblib` y no modifica `/predict`.

## Resultado principal

- Registros iniciales: {results['records_initial']}
- Registros después de deduplicación: {results['records_after_deduplication']}
- Mejor modelo: {results['best_model']}
- MAE: {best['mae']:.2f}
- MedianAE: {best['median_ae']:.2f}
- RMSE: {best['rmse']:.2f}
- R2: {best['r2']:.4f}
- MAPE: {best['mape']:.2f}%
- Dentro de ±20%: {best['within_20_pct']:.2f}%

## Comparación legacy

No se hizo comparación directa porque el modelo legacy requiere `COL_XX` y un contrato de features no equivalente al dataset real actual.
"""
    (experiment_dir / "README.md").write_text(content, encoding="utf-8")


def print_summary(results: dict, experiment_dir: Path) -> None:
    print(f"Experimento guardado en: {experiment_dir}")
    print(f"Registros iniciales: {results['records_initial']}")
    print(f"Registros después de deduplicación: {results['records_after_deduplication']}")
    print(f"Mejor modelo: {results['best_model']}")
    best = results["models"][results["best_model"]]["metrics"]
    for key in ["mae", "median_ae", "rmse", "r2", "mape", "within_10_pct", "within_20_pct", "within_30_pct"]:
        print(f"{key}: {best[key]:.4f}")


if __name__ == "__main__":
    raise SystemExit(main())
