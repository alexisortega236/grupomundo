#!/usr/bin/env python3
from __future__ import annotations

import json
import math
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.ensemble import GradientBoostingRegressor
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
from sklearn.metrics import mean_absolute_error, mean_squared_error, median_absolute_error, r2_score
from sklearn.model_selection import GroupKFold
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder


ROOT = Path(__file__).resolve().parents[1]
DATASET_PATH = ROOT / "data" / "experiments" / "avm_v2_dataset_v1.csv"
PREDICTIONS_PATH = ROOT / "experiments" / "avm_v2_v1" / "predictions.csv"
DIAG_DIR = ROOT / "experiments" / "avm_v2_v1" / "diagnostics"
REQUIREMENTS_PATH = ROOT / "data" / "experiments" / "avm_v2_data_requirements.json"
RANDOM_STATE = 42

NUMERIC_PROFILE_COLUMNS = [
    "price",
    "land_area_m2",
    "construction_area_m2",
    "bedrooms",
    "bathrooms",
    "parking_spaces",
]
PRICE_BINS = [-np.inf, 1_000_000, 2_000_000, 3_000_000, 5_000_000, 8_000_000, 12_000_000, 20_000_000, np.inf]
PRICE_LABELS = ["<1M", "1M-2M", "2M-3M", "3M-5M", "5M-8M", "8M-12M", "12M-20M", ">20M"]

PHYSICAL_NUMERIC = ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces"]
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
FEATURE_SETS = {
    "A_physical": {
        "numeric": PHYSICAL_NUMERIC,
        "categorical": ["property_type"],
    },
    "B_physical_municipality": {
        "numeric": PHYSICAL_NUMERIC,
        "categorical": ["property_type", "municipality"],
    },
    "C_physical_municipality_ageb": {
        "numeric": PHYSICAL_NUMERIC,
        "categorical": ["property_type", "municipality", "inegi_cve_ageb"],
    },
    "D_physical_municipality_censo": {
        "numeric": PHYSICAL_NUMERIC + CENSO_NUMERIC,
        "categorical": ["property_type", "municipality"],
    },
    "E_physical_municipality_censo_denue": {
        "numeric": PHYSICAL_NUMERIC + CENSO_NUMERIC + DENUE_NUMERIC,
        "categorical": ["property_type", "municipality"],
    },
}
QUALITY_ERROR_FLAGS = {
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
    DIAG_DIR.mkdir(parents=True, exist_ok=True)
    REQUIREMENTS_PATH.parent.mkdir(parents=True, exist_ok=True)

    frozen = pd.read_csv(DATASET_PATH)
    predictions = pd.read_csv(PREDICTIONS_PATH)
    prepared = prepare(frozen)
    deduped = dedupe(prepared)
    deduped = add_derived_columns(deduped)
    pred = enrich_predictions(predictions, deduped)

    dataset_profile = {
        "created_at": now(),
        "records_analyzed": int(len(deduped)),
        "global": profile_frame(deduped),
        "by_property_type": grouped_profile(deduped, "property_type"),
        "by_municipality": grouped_profile(deduped, "municipality"),
        "price_distribution": {
            "global": price_distribution(deduped),
            "by_property_type": grouped_price_distribution(deduped, "property_type"),
            "by_municipality": grouped_price_distribution(deduped, "municipality"),
        },
    }
    write_json(DIAG_DIR / "dataset_profile.json", dataset_profile)

    price_per_m2 = price_per_m2_table(deduped)
    price_per_m2.to_csv(DIAG_DIR / "price_per_m2.csv", index=False)

    error_tables = {
        "property_type": grouped_prediction_metrics(pred, "property_type"),
        "municipality": grouped_prediction_metrics(pred, "municipality"),
        "price_band": grouped_prediction_metrics(pred, "price_band"),
        "source": grouped_prediction_metrics(pred, "source"),
        "training_readiness": grouped_prediction_metrics(pred, "training_readiness"),
        "coordinate_quality": grouped_prediction_metrics(pred, "coordinate_quality"),
    }
    error_tables["property_type"].to_csv(DIAG_DIR / "error_by_property_type.csv", index=False)
    error_tables["municipality"].to_csv(DIAG_DIR / "error_by_municipality.csv", index=False)
    error_tables["price_band"].to_csv(DIAG_DIR / "error_by_price_band.csv", index=False)
    error_tables["source"].to_csv(DIAG_DIR / "error_by_source.csv", index=False)
    error_tables["training_readiness"].to_csv(DIAG_DIR / "error_by_training_readiness.csv", index=False)
    error_tables["coordinate_quality"].to_csv(DIAG_DIR / "error_by_coordinate_quality.csv", index=False)

    bias = price_band_bias(pred)
    bias.to_csv(DIAG_DIR / "price_band_bias.csv", index=False)

    residuals = residual_summary(pred)
    residuals.to_csv(DIAG_DIR / "residuals.csv", index=False)

    terrenos = pred[pred["property_type"] == "terreno"].copy()
    terrenos[terrain_columns()].to_csv(DIAG_DIR / "terrains.csv", index=False)

    missing = missingness(deduped)
    missing.to_csv(DIAG_DIR / "missingness.csv", index=False)

    source_bias = source_profile(deduped, pred)
    source_bias.to_csv(DIAG_DIR / "source_bias.csv", index=False)

    ablation = homogeneous_ablation(deduped)
    ablation.to_csv(DIAG_DIR / "ablation.csv", index=False)

    learning_curve = run_learning_curve(deduped)
    learning_curve.to_csv(DIAG_DIR / "learning_curve.csv", index=False)

    global_vs_residential = compare_global_residential(deduped)
    global_vs_residential.to_csv(DIAG_DIR / "global_vs_residential.csv", index=False)

    worst = worst_predictions(pred)
    best = best_predictions(pred)
    worst.to_csv(DIAG_DIR / "worst_predictions.csv", index=False)
    best.to_csv(DIAG_DIR / "best_predictions.csv", index=False)

    outliers = outlier_table(deduped, pred)
    outliers.to_csv(DIAG_DIR / "outliers.csv", index=False)

    requirements = build_data_requirements(deduped, pred, error_tables, bias, learning_curve)
    write_json(REQUIREMENTS_PATH, requirements)

    report = build_markdown_report(deduped, pred, error_tables, bias, ablation, learning_curve, global_vs_residential, source_bias, outliers, requirements)
    (DIAG_DIR / "data_diagnostics.md").write_text(report, encoding="utf-8")

    print(report)
    return 0


def prepare(df: pd.DataFrame) -> pd.DataFrame:
    out = df.copy()
    out = out[out["currency"].fillna("MXN").eq("MXN")].copy()
    for col in set(NUMERIC_PROFILE_COLUMNS + PHYSICAL_NUMERIC + CENSO_NUMERIC + DENUE_NUMERIC + ["price_per_construction_m2", "price_per_land_m2"]):
        if col in out.columns:
            out[col] = pd.to_numeric(out[col], errors="coerce")
    out = out[out["price"].notna() & (out["price"] > 0)].copy()
    out["quality_flags"] = out["quality_flags"].fillna("")
    return out.reset_index(drop=True)


def dedupe(df: pd.DataFrame) -> pd.DataFrame:
    return df.sort_values(["duplicate_group_id", "source_id"]).drop_duplicates("duplicate_group_id", keep="first").reset_index(drop=True)


def add_derived_columns(df: pd.DataFrame) -> pd.DataFrame:
    out = df.copy()
    out["price_band"] = pd.cut(out["price"], PRICE_BINS, labels=PRICE_LABELS, right=False)
    out["price_per_m2_model"] = np.where(
        out["property_type"].eq("terreno"),
        out["price_per_land_m2"],
        out["price_per_construction_m2"],
    )
    return out


def enrich_predictions(predictions: pd.DataFrame, deduped: pd.DataFrame) -> pd.DataFrame:
    cols = [
        "source_id", "source", "url", "property_type", "municipality", "neighborhood", "price",
        "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces",
        "price_per_land_m2", "price_per_construction_m2", "price_band", "coordinate_quality",
        "training_readiness", "quality_flags", "inegi_cve_ageb",
    ]
    merged = predictions.merge(deduped[[c for c in cols if c in deduped.columns]], on=["source", "source_id", "property_type", "municipality", "training_readiness", "coordinate_quality"], how="left")
    merged["residual"] = merged["predicted_price"] - merged["actual_price"]
    merged["prediction_ratio"] = merged["predicted_price"] / merged["actual_price"].clip(lower=1)
    if "price_band" not in merged or merged["price_band"].isna().all():
        merged["price_band"] = pd.cut(merged["actual_price"], PRICE_BINS, labels=PRICE_LABELS, right=False)
    return merged


def profile_frame(df: pd.DataFrame) -> dict:
    return {col: profile_series(df[col], len(df)) for col in NUMERIC_PROFILE_COLUMNS if col in df.columns}


def grouped_profile(df: pd.DataFrame, group_col: str) -> dict:
    return {str(key): profile_frame(group) for key, group in df.groupby(group_col, dropna=False)}


def profile_series(series: pd.Series, total: int) -> dict:
    values = pd.to_numeric(series, errors="coerce")
    clean = values.dropna()
    result = {
        "count": int(clean.count()),
        "missing": int(values.isna().sum()),
        "missing_pct": pct(values.isna().sum(), total),
    }
    if clean.empty:
        return result
    result.update({
        "mean": float(clean.mean()),
        "median": float(clean.median()),
        "std": float(clean.std()) if len(clean) > 1 else 0.0,
        "min": float(clean.min()),
        "p05": float(clean.quantile(0.05)),
        "p10": float(clean.quantile(0.10)),
        "p25": float(clean.quantile(0.25)),
        "p50": float(clean.quantile(0.50)),
        "p75": float(clean.quantile(0.75)),
        "p90": float(clean.quantile(0.90)),
        "p95": float(clean.quantile(0.95)),
        "max": float(clean.max()),
    })
    return result


def price_distribution(df: pd.DataFrame) -> list[dict]:
    total = len(df)
    rows = []
    counts = df["price_band"].value_counts(sort=False)
    for band in PRICE_LABELS:
        n = int(counts.get(band, 0))
        rows.append({"price_band": band, "n": n, "pct": pct(n, total), "sample_bucket": sample_bucket(n)})
    return rows


def grouped_price_distribution(df: pd.DataFrame, group_col: str) -> dict:
    return {str(key): price_distribution(group) for key, group in df.groupby(group_col, dropna=False)}


def price_per_m2_table(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for keys, group in df.groupby(["property_type", "municipality"], dropna=False):
        ptype, municipality = keys
        col = "price_per_land_m2" if ptype == "terreno" else "price_per_construction_m2"
        values = pd.to_numeric(group[col], errors="coerce").dropna()
        if values.empty:
            rows.append({"property_type": ptype, "municipality": municipality, "metric": col, "n": 0})
            continue
        q1 = values.quantile(0.25)
        q3 = values.quantile(0.75)
        iqr = q3 - q1
        rows.append({
            "property_type": ptype,
            "municipality": municipality,
            "metric": col,
            "n": int(values.count()),
            "median": float(values.median()),
            "mean": float(values.mean()),
            "p10": float(values.quantile(0.10)),
            "p25": float(q1),
            "p75": float(q3),
            "p90": float(values.quantile(0.90)),
            "iqr": float(iqr),
            "iqr_low_threshold": float(q1 - 1.5 * iqr),
            "iqr_high_threshold": float(q3 + 1.5 * iqr),
            "statistical_outliers": int(((values < q1 - 1.5 * iqr) | (values > q3 + 1.5 * iqr)).sum()),
        })
    return pd.DataFrame(rows)


def grouped_prediction_metrics(pred: pd.DataFrame, group_col: str) -> pd.DataFrame:
    rows = []
    for value, group in pred.groupby(group_col, dropna=False):
        row = prediction_metrics(group)
        row[group_col] = value
        row["sample_quality"] = sample_bucket(len(group))
        rows.append(row)
    if not rows:
        return pd.DataFrame()
    cols = [group_col, "sample_quality", "n", "mae", "median_ae", "rmse", "mape", "median_percentage_error", "within_10_pct", "within_20_pct", "within_30_pct"]
    return pd.DataFrame(rows)[cols].sort_values(["sample_quality", "n"], ascending=[True, False])


def prediction_metrics(group: pd.DataFrame) -> dict:
    actual = group["actual_price"].to_numpy()
    pred = group["predicted_price"].to_numpy()
    abs_error = np.abs(actual - pred)
    pct_error = abs_error / np.maximum(actual, 1) * 100
    return {
        "n": int(len(group)),
        "mae": float(mean_absolute_error(actual, pred)),
        "median_ae": float(median_absolute_error(actual, pred)),
        "rmse": float(math.sqrt(mean_squared_error(actual, pred))),
        "mape": float(np.mean(pct_error)),
        "median_percentage_error": float(np.median(pct_error)),
        "within_10_pct": float(np.mean(pct_error <= 10) * 100),
        "within_20_pct": float(np.mean(pct_error <= 20) * 100),
        "within_30_pct": float(np.mean(pct_error <= 30) * 100),
    }


def price_band_bias(pred: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for band, group in pred.groupby("price_band", dropna=False):
        rows.append({
            "price_band": band,
            "n": int(len(group)),
            "actual_median": float(group["actual_price"].median()),
            "predicted_median": float(group["predicted_price"].median()),
            "mean_bias": float(group["residual"].mean()),
            "median_bias": float(group["residual"].median()),
            "mae": float(group["absolute_error"].mean()),
            "mape": float(group["percentage_error"].mean()),
            "median_prediction_ratio": float(group["prediction_ratio"].median()),
            "bias_direction": "sobrevaluacion" if group["prediction_ratio"].median() > 1 else "subvaluacion",
        })
    return pd.DataFrame(rows)


def residual_summary(pred: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for col in ["actual_price", "land_area_m2", "construction_area_m2"]:
        valid = pred[[col, "residual"]].dropna()
        corr = valid[col].corr(valid["residual"]) if len(valid) > 2 else np.nan
        rows.append({"variable": col, "n": int(len(valid)), "residual_correlation": float(corr) if pd.notna(corr) else None})
    for col in ["property_type", "municipality"]:
        for value, group in pred.groupby(col, dropna=False):
            rows.append({"variable": col, "value": value, "n": int(len(group)), "mean_residual": float(group["residual"].mean()), "median_residual": float(group["residual"].median())})
    return pd.DataFrame(rows)


def missingness(df: pd.DataFrame) -> pd.DataFrame:
    cols = PHYSICAL_NUMERIC + ["inegi_cve_ageb"] + CENSO_NUMERIC + DENUE_NUMERIC
    rows = []
    for scope, frame in [("global", df)]:
        rows.extend(missing_rows(frame, cols, scope, None))
    for group_col in ["source", "property_type", "municipality"]:
        for value, group in df.groupby(group_col, dropna=False):
            rows.extend(missing_rows(group, cols, group_col, value))
    return pd.DataFrame(rows)


def missing_rows(df: pd.DataFrame, cols: list[str], scope: str, value) -> list[dict]:
    rows = []
    for col in cols:
        if col not in df.columns:
            continue
        missing = int(df[col].isna().sum())
        rows.append({"scope": scope, "value": value, "field": col, "n": int(len(df)), "missing": missing, "missing_pct": pct(missing, len(df))})
    return rows


def source_profile(df: pd.DataFrame, pred: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for source, group in df.groupby("source", dropna=False):
        pred_group = pred[pred["source"] == source]
        metrics = prediction_metrics(pred_group) if len(pred_group) else {}
        rows.append({
            "source": source,
            "n": int(len(group)),
            "median_price": float(group["price"].median()),
            "types": json.dumps(counts(group, "property_type"), ensure_ascii=False),
            "municipalities": json.dumps(counts(group, "municipality"), ensure_ascii=False),
            "training_readiness": json.dumps(counts(group, "training_readiness"), ensure_ascii=False),
            "coordinate_quality": json.dumps(counts(group, "coordinate_quality"), ensure_ascii=False),
            "land_missing_pct": pct(group["land_area_m2"].isna().sum(), len(group)),
            "construction_missing_pct": pct(group["construction_area_m2"].isna().sum(), len(group)),
            "mae": metrics.get("mae"),
            "mape": metrics.get("mape"),
            "within_20_pct": metrics.get("within_20_pct"),
            "within_30_pct": metrics.get("within_30_pct"),
        })
    return pd.DataFrame(rows)


def homogeneous_ablation(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    base = None
    for name, features in FEATURE_SETS.items():
        metrics = evaluate_gradient_boosting(df, features)
        row = {"experiment": name, **metrics}
        if base is None:
            base = metrics
            row.update({"delta_mae": 0.0, "delta_mape": 0.0, "delta_r2": 0.0})
        else:
            row.update({
                "delta_mae": metrics["mae"] - base["mae"],
                "delta_mape": metrics["mape"] - base["mape"],
                "delta_r2": metrics["r2"] - base["r2"],
            })
        rows.append(row)
    return pd.DataFrame(rows)


def evaluate_gradient_boosting(df: pd.DataFrame, features: dict, repeats: int = 1, sample_frac: float = 1.0) -> dict:
    rows = []
    for repeat in range(repeats):
        sample = df.copy()
        if sample_frac < 1.0:
            sample = stratified_sample(df, sample_frac, repeat)
        if len(sample) < 20 or sample["duplicate_group_id"].nunique() < 3:
            continue
        for fold, (train_idx, test_idx) in enumerate(group_folds(sample)):
            train = sample.iloc[train_idx]
            test = sample.iloc[test_idx]
            model = make_pipeline(features)
            model.fit(train, train["price"])
            pred = np.maximum(model.predict(test), 1)
            rows.append(metric_row(test["price"].to_numpy(), pred, len(test), fold, repeat))
    return aggregate_metric_rows(rows)


def run_learning_curve(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    features = FEATURE_SETS["E_physical_municipality_censo_denue"]
    for frac in [0.25, 0.50, 0.75, 1.00]:
        metrics_runs = []
        repeats = 8 if frac < 1.0 else 1
        for repeat in range(repeats):
            sample = stratified_sample(df, frac, repeat) if frac < 1.0 else df.copy()
            if len(sample) < 25:
                continue
            metrics_runs.append(evaluate_gradient_boosting(sample, features))
        if not metrics_runs:
            continue
        frame = pd.DataFrame(metrics_runs)
        rows.append({
            "training_size_pct": int(frac * 100),
            "mean_records": float(frame["n"].mean()),
            "mean_cv_mae": float(frame["mae"].mean()),
            "std_cv_mae": float(frame["mae"].std(ddof=0)),
            "mean_cv_r2": float(frame["r2"].mean()),
            "std_cv_r2": float(frame["r2"].std(ddof=0)),
            "mean_within_20": float(frame["within_20_pct"].mean()),
            "mean_within_30": float(frame["within_30_pct"].mean()),
        })
    return pd.DataFrame(rows)


def compare_global_residential(df: pd.DataFrame) -> pd.DataFrame:
    features = FEATURE_SETS["E_physical_municipality_censo_denue"]
    residential = df[df["property_type"].isin(["casa", "departamento"])].copy()
    rows = [{"experiment": "global_all_types", **evaluate_gradient_boosting(df, features)}]
    if len(residential) >= 50:
        rows.append({"experiment": "residential_only_no_terrenos", **evaluate_gradient_boosting(residential, features)})
    return pd.DataFrame(rows)


def make_pipeline(features: dict) -> Pipeline:
    numeric = [c for c in features["numeric"] if c]
    categorical = [c for c in features["categorical"] if c]
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
    model = GradientBoostingRegressor(n_estimators=220, learning_rate=0.055, max_depth=3, random_state=RANDOM_STATE)
    return Pipeline([("preprocess", preprocessor), ("model", model)])


def group_folds(df: pd.DataFrame):
    n_splits = min(5, df["duplicate_group_id"].nunique())
    return list(GroupKFold(n_splits=n_splits).split(df, groups=df["duplicate_group_id"]))


def stratified_sample(df: pd.DataFrame, frac: float, repeat: int) -> pd.DataFrame:
    sampled = (
        df.groupby("property_type", group_keys=False)
        .apply(lambda g: g.sample(max(1, int(round(len(g) * frac))), random_state=RANDOM_STATE + repeat))
        .reset_index(drop=True)
    )
    return sampled.sort_values(["duplicate_group_id", "source_id"]).reset_index(drop=True)


def metric_row(actual: np.ndarray, predicted: np.ndarray, n: int, fold: int, repeat: int) -> dict:
    abs_error = np.abs(actual - predicted)
    pct_error = abs_error / np.maximum(actual, 1) * 100
    return {
        "repeat": repeat,
        "fold": fold,
        "n": int(n),
        "mae": float(mean_absolute_error(actual, predicted)),
        "median_ae": float(median_absolute_error(actual, predicted)),
        "rmse": float(math.sqrt(mean_squared_error(actual, predicted))),
        "r2": float(r2_score(actual, predicted)),
        "mape": float(np.mean(pct_error)),
        "within_10_pct": float(np.mean(pct_error <= 10) * 100),
        "within_20_pct": float(np.mean(pct_error <= 20) * 100),
        "within_30_pct": float(np.mean(pct_error <= 30) * 100),
    }


def aggregate_metric_rows(rows: list[dict]) -> dict:
    frame = pd.DataFrame(rows)
    return {
        "n": int(frame["n"].sum()),
        "folds": int(frame["fold"].nunique()) if "fold" in frame else 0,
        "mae": float(frame["mae"].mean()),
        "median_ae": float(frame["median_ae"].mean()),
        "rmse": float(frame["rmse"].mean()),
        "r2": float(frame["r2"].mean()),
        "mape": float(frame["mape"].mean()),
        "within_10_pct": float(frame["within_10_pct"].mean()),
        "within_20_pct": float(frame["within_20_pct"].mean()),
        "within_30_pct": float(frame["within_30_pct"].mean()),
    }


def worst_predictions(pred: pd.DataFrame) -> pd.DataFrame:
    cols = prediction_export_columns()
    by_pct = pred.sort_values("percentage_error", ascending=False).head(20)
    by_abs = pred.sort_values("absolute_error", ascending=False).head(20)
    out = pd.concat([by_pct, by_abs], ignore_index=True).drop_duplicates(["source", "source_id"])
    return out[cols].head(40)


def best_predictions(pred: pd.DataFrame) -> pd.DataFrame:
    cols = prediction_export_columns()
    return pred.sort_values(["percentage_error", "absolute_error"]).head(25)[cols]


def prediction_export_columns() -> list[str]:
    return [
        "source_id", "source", "property_type", "municipality", "neighborhood",
        "actual_price", "predicted_price", "absolute_error", "percentage_error",
        "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces",
        "coordinate_quality", "training_readiness", "quality_flags",
    ]


def outlier_table(df: pd.DataFrame, pred: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for _, row in pred.iterrows():
        flags = str(row.get("quality_flags") or "")
        outlier_type = None
        reason = None
        if set(flags.split(",")) & QUALITY_ERROR_FLAGS:
            outlier_type = "dato_probablemente_incorrecto"
            reason = flags
        elif row["actual_price"] >= df["price"].quantile(0.95) or row["actual_price"] <= df["price"].quantile(0.05):
            outlier_type = "inmueble_estadisticamente_extremo"
            reason = "precio fuera de p05/p95"
        elif row["percentage_error"] >= pred["percentage_error"].quantile(0.95):
            outlier_type = "error_modelo_extremo"
            reason = "error porcentual fuera de p95"
        if outlier_type:
            rows.append({
                "source_id": row["source_id"],
                "source": row["source"],
                "property_type": row["property_type"],
                "municipality": row["municipality"],
                "actual_price": row["actual_price"],
                "predicted_price": row["predicted_price"],
                "percentage_error": row["percentage_error"],
                "outlier_type": outlier_type,
                "reason": reason,
            })
    return pd.DataFrame(rows)


def build_data_requirements(df: pd.DataFrame, pred: pd.DataFrame, error_tables: dict, bias: pd.DataFrame, learning_curve: pd.DataFrame) -> dict:
    return {
        "created_at": now(),
        "basis": "diagnostico avm_v2_v1 sobre 155 registros deduplicados",
        "property_types": segment_requirements(df, error_tables["property_type"], "property_type", targets={"casa": 200, "departamento": 150, "terreno": 120}),
        "municipalities": segment_requirements(df, error_tables["municipality"], "municipality", targets={m: 40 for m in df["municipality"].dropna().unique()}),
        "price_bands": price_band_requirements(pred, bias),
        "learning_curve": learning_curve.to_dict(orient="records"),
        "priority_collection": [
            "Aumentar departamentos y terrenos para reducir varianza por tipo.",
            "Recolectar más registros de Ayala, Temixco, Xochitepec y Emiliano Zapata antes de interpretar municipio.",
            "Priorizar propiedades de 1M-8M, donde el mercado objetivo es denso y el modelo aún mezcla sobre/subvaluación.",
            "Recolectar propiedades caras >12M con verificación manual para separar mansiones plausibles de errores de superficie/precio.",
        ],
    }


def segment_requirements(df: pd.DataFrame, metrics: pd.DataFrame, column: str, targets: dict) -> dict:
    result = {}
    for value, group in df.groupby(column, dropna=False):
        metric_row_df = metrics[metrics[column].astype(str) == str(value)] if column in metrics else pd.DataFrame()
        mape = float(metric_row_df["mape"].iloc[0]) if not metric_row_df.empty else None
        within20 = float(metric_row_df["within_20_pct"].iloc[0]) if not metric_row_df.empty else None
        n = int(len(group))
        priority = "high" if n < 20 or (mape is not None and mape > 70) else "medium" if n < 50 or (within20 is not None and within20 < 45) else "low"
        result[str(value)] = {
            "current_count": n,
            "priority": priority,
            "recommended_target": int(targets.get(value, max(40, n))),
            "reason": f"n={n}, MAPE={mape}, within20={within20}; prioridad derivada de muestra y error observado.",
        }
    return result


def price_band_requirements(pred: pd.DataFrame, bias: pd.DataFrame) -> dict:
    result = {}
    for _, row in bias.iterrows():
        n = int(row["n"])
        mape = float(row["mape"])
        priority = "high" if n < 15 or mape > 80 else "medium" if n < 30 or mape > 50 else "low"
        result[str(row["price_band"])] = {
            "current_count": n,
            "priority": priority,
            "recommended_target": 30 if n < 30 else n,
            "reason": f"n={n}, MAPE={mape:.2f}, ratio_mediano={row['median_prediction_ratio']:.3f}, sesgo={row['bias_direction']}.",
        }
    return result


def build_markdown_report(df, pred, errors, bias, ablation, learning_curve, global_vs_residential, source_bias, outliers, requirements) -> str:
    best_types = errors["property_type"].sort_values("mape").head(3)
    worst_types = errors["property_type"].sort_values("mape", ascending=False).head(3)
    worst_municipalities = errors["municipality"].sort_values("mape", ascending=False).head(5)
    worst_bands = errors["price_band"].sort_values("mape", ascending=False).head(4)
    ablation_base = ablation.iloc[0]
    ablation_best = ablation.sort_values("mae").iloc[0]
    lc = learning_curve
    if len(lc) >= 2 and lc["mean_cv_mae"].iloc[-1] < lc["mean_cv_mae"].iloc[0] * 0.90:
        lc_class = "A: sigue mejorando claramente con más datos"
    elif len(lc) >= 2 and lc["mean_cv_mae"].iloc[-1] < lc["mean_cv_mae"].iloc[0]:
        lc_class = "B: mejora pero empieza a estabilizarse"
    else:
        lc_class = "D: resultado inconcluso por tamaño/varianza"

    censo_row = ablation[ablation["experiment"] == "D_physical_municipality_censo"].iloc[0]
    denue_row = ablation[ablation["experiment"] == "E_physical_municipality_censo_denue"].iloc[0]
    ageb_row = ablation[ablation["experiment"] == "C_physical_municipality_ageb"].iloc[0]

    def usefulness(row):
        if row["delta_mae"] < -150000 and row["delta_r2"] > 0.02:
            return "useful"
        if row["delta_mae"] > 150000:
            return "currently_harmful"
        return "inconclusive"

    lines = [
        "# Diagnóstico AVM v2 v1",
        "",
        f"- Registros analizados: {len(df)}",
        f"- Tipos: {counts(df, 'property_type')}",
        f"- Municipios: {counts(df, 'municipality')}",
        f"- Mejor precisión por tipo: {best_types[['property_type','n','mape','within_20_pct']].to_dict(orient='records')}",
        f"- Peor precisión por tipo: {worst_types[['property_type','n','mape','within_20_pct']].to_dict(orient='records')}",
        f"- Municipios problemáticos: {worst_municipalities[['municipality','n','mape','within_20_pct']].to_dict(orient='records')}",
        f"- Rangos de precio problemáticos: {worst_bands[['price_band','n','mape','within_20_pct']].to_dict(orient='records')}",
        "",
        "## Sesgo por precio",
        markdown_table(bias),
        "",
        "## Ablation homogéneo GradientBoosting",
        markdown_table(ablation),
        "",
        f"- Aporte municipio: delta MAE de B vs A = {float(ablation.iloc[1]['delta_mae']):,.0f}",
        f"- Aporte AGEB: delta MAE de C vs A = {float(ageb_row['delta_mae']):,.0f}",
        f"- Censo: {usefulness(censo_row)}",
        f"- DENUE: {usefulness(denue_row)}",
        "",
        "## Learning curve",
        markdown_table(learning_curve),
        f"- Clasificación: {lc_class}",
        "",
        "## Terrenos vs residencial",
        markdown_table(global_vs_residential),
        "",
        "## Source bias",
        markdown_table(source_bias),
        "",
        "## Outliers",
        f"- Outliers reportados: {len(outliers)}",
        f"- Dato probablemente incorrecto: {int((outliers['outlier_type'] == 'dato_probablemente_incorrecto').sum()) if len(outliers) else 0}",
        "",
        "## Conclusión",
        "Existe señal, pero el error alto se explica por volumen bajo por segmento, mezcla de mercados distintos y sensibilidad a rangos de precio altos. La formulación debe mejorar junto con más datos; no basta sólo con agregar Censo/DENUE.",
        "",
        "Clasificación final: D) Existe señal, pero volumen y formulación requieren mejoras simultáneas.",
    ]
    return "\n".join(lines)


def terrain_columns() -> list[str]:
    return [
        "source_id", "source", "municipality", "neighborhood", "actual_price", "predicted_price",
        "absolute_error", "percentage_error", "land_area_m2", "price_per_land_m2",
        "inegi_cve_ageb", "coordinate_quality", "quality_flags",
    ]


def markdown_table(df: pd.DataFrame) -> str:
    if df.empty:
        return "_Sin datos._"
    return df.to_csv(index=False)


def counts(df: pd.DataFrame, col: str) -> dict:
    return {str(k): int(v) for k, v in df[col].fillna("missing").value_counts().items()}


def sample_bucket(n: int) -> str:
    if n < 5:
        return "LOW_SAMPLE_<5"
    if n < 10:
        return "LOW_SAMPLE_5_9"
    if n < 20:
        return "LOW_SAMPLE_10_19"
    return "OK_20_PLUS"


def pct(value: int | float, total: int | float) -> float:
    return float(value / total * 100) if total else 0.0


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def write_json(path: Path, data: dict) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


if __name__ == "__main__":
    raise SystemExit(main())
