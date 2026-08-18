#!/usr/bin/env python3
from __future__ import annotations

import json
import math
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import GradientBoostingRegressor, HistGradientBoostingRegressor, RandomForestRegressor
from sklearn.impute import SimpleImputer
from sklearn.inspection import permutation_importance
from sklearn.metrics import mean_absolute_error, mean_squared_error, median_absolute_error, r2_score
from sklearn.model_selection import GroupKFold, GroupShuffleSplit
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder


ROOT = Path(__file__).resolve().parents[1]
INPUT = ROOT / "data" / "experiments" / "avm_v2_dataset_v2_candidates.csv"
DATASET_OUT = ROOT / "data" / "experiments" / "avm_v2_dataset_v2.csv"
META_OUT = ROOT / "data" / "experiments" / "avm_v2_dataset_v2_metadata.json"
EXPERIMENT_DIR = ROOT / "experiments" / "avm_v2_v2"
V1_METRICS = ROOT / "experiments" / "avm_v2_v1" / "metrics.json"
RANDOM_STATE = 42

PRICE_BANDS = ["<1M", "1M-2M", "2M-3M", "3M-5M", "5M-8M", "8M-12M", "12M-20M", ">20M"]
LIKELY_DATA_ERROR_FLAGS = {
    "invalid_price",
    "invalid_land_area",
    "invalid_construction_area",
    "suspicious_construction_area",
    "suspicious_land_area",
    "suspicious_price_m2",
    "suspicious_price_per_construction_m2",
    "suspicious_price_per_land_m2",
}
CENSO = [
    "population_density", "housing_density", "car_ownership_ratio",
    "internet_access_ratio", "average_schooling", "employment_ratio",
]
DENUE = [
    "establishments_500m", "establishments_1km", "retail_500m", "retail_1km",
    "restaurants_hotels_500m", "restaurants_hotels_1km", "health_500m", "health_1km",
    "education_500m", "education_1km", "financial_500m", "financial_1km",
    "professional_services_500m", "professional_services_1km",
]
GLOBAL_FEATURES = {
    "numeric": ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", *CENSO, *DENUE],
    "categorical": ["property_type", "market_segment", "municipality", "inegi_cve_ageb"],
}
RESIDENTIAL_FEATURES = {
    "numeric": ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", *CENSO, *DENUE],
    "categorical": ["property_type", "municipality", "inegi_cve_ageb"],
}
LAND_FEATURES = {
    "numeric": ["land_area_m2", *CENSO, *DENUE],
    "categorical": ["municipality", "inegi_cve_ageb"],
}
RESIDENTIAL_ABLATION = {
    "A_physical": {"numeric": ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces"], "categorical": ["property_type"]},
    "B_municipality": {"numeric": ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces"], "categorical": ["property_type", "municipality"]},
    "C_ageb": {"numeric": ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces"], "categorical": ["property_type", "municipality", "inegi_cve_ageb"]},
    "D_censo": {"numeric": ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", *CENSO], "categorical": ["property_type", "municipality", "inegi_cve_ageb"]},
    "E_denue": RESIDENTIAL_FEATURES,
}
LAND_ABLATION = {
    "A_land_area": {"numeric": ["land_area_m2"], "categorical": []},
    "B_municipality": {"numeric": ["land_area_m2"], "categorical": ["municipality"]},
    "C_ageb": {"numeric": ["land_area_m2"], "categorical": ["municipality", "inegi_cve_ageb"]},
    "D_censo": {"numeric": ["land_area_m2", *CENSO], "categorical": ["municipality", "inegi_cve_ageb"]},
    "E_denue": LAND_FEATURES,
}


def main() -> int:
    EXPERIMENT_DIR.mkdir(parents=True, exist_ok=True)
    raw = pd.read_csv(INPUT)
    prepared = prepare(raw)
    final, removed_quality = remove_quality_errors(prepared)
    final.to_csv(DATASET_OUT, index=False)
    meta = metadata(raw, prepared, final, removed_quality)
    META_OUT.write_text(json.dumps(meta, ensure_ascii=False, indent=2), encoding="utf-8")

    residential = final[final["market_segment"] == "residential"].copy()
    land = final[final["market_segment"] == "land"].copy()

    global_eval = evaluate_suite(final, GLOBAL_FEATURES, "global", baselines_global)
    residential_eval = evaluate_suite(residential, RESIDENTIAL_FEATURES, "residential", baselines_residential)
    land_eval = evaluate_suite(land, LAND_FEATURES, "land", baselines_land)

    write_predictions(global_eval, "global")
    write_predictions(residential_eval, "residential")
    write_predictions(land_eval, "land")

    ablation_res = ablation(residential, RESIDENTIAL_ABLATION)
    ablation_land = ablation(land, LAND_ABLATION)
    ablation_res.to_csv(EXPERIMENT_DIR / "ablation_residential.csv", index=False)
    ablation_land.to_csv(EXPERIMENT_DIR / "ablation_land.csv", index=False)

    lc_res = learning_curve(residential, RESIDENTIAL_FEATURES)
    lc_land = learning_curve(land, LAND_FEATURES)
    lc_res.to_csv(EXPERIMENT_DIR / "learning_curve_residential.csv", index=False)
    lc_land.to_csv(EXPERIMENT_DIR / "learning_curve_land.csv", index=False)

    fitted_global = fit_full(final, GLOBAL_FEATURES, global_eval["best_algorithm"], global_eval["best_log_target"])
    fitted_res = fit_full(residential, RESIDENTIAL_FEATURES, residential_eval["best_algorithm"], residential_eval["best_log_target"])
    fitted_land = fit_full(land, LAND_FEATURES, land_eval["best_algorithm"], land_eval["best_log_target"])
    joblib.dump(fitted_global, EXPERIMENT_DIR / "model_global_experimental.joblib")
    joblib.dump(fitted_res, EXPERIMENT_DIR / "model_residential_experimental.joblib")
    joblib.dump(fitted_land, EXPERIMENT_DIR / "model_land_experimental.joblib")

    fi_global = feature_importance(fitted_global, final, GLOBAL_FEATURES)
    fi_res = feature_importance(fitted_res, residential, RESIDENTIAL_FEATURES)
    fi_land = feature_importance(fitted_land, land, LAND_FEATURES)
    fi_global.to_csv(EXPERIMENT_DIR / "feature_importance_global.csv", index=False)
    fi_res.to_csv(EXPERIMENT_DIR / "feature_importance_residential.csv", index=False)
    fi_land.to_csv(EXPERIMENT_DIR / "feature_importance_land.csv", index=False)

    comparison = comparison_v1_v2(global_eval, residential_eval, land_eval)
    comparison.to_csv(EXPERIMENT_DIR / "comparison_v1_v2.csv", index=False)

    worst_res = residential_eval["best_predictions"].sort_values(["percentage_error", "absolute_error"], ascending=False).head(20)
    worst_land = land_eval["best_predictions"].sort_values(["percentage_error", "absolute_error"], ascending=False).head(20)
    worst_res.to_csv(EXPERIMENT_DIR / "worst_predictions_residential.csv", index=False)
    worst_land.to_csv(EXPERIMENT_DIR / "worst_predictions_land.csv", index=False)

    outlier_impact = outlier_comparison(final, residential, land)
    metrics = {
        "created_at": now(),
        "dataset": dataset_summary(final),
        "freeze": meta,
        "global": clean_eval(global_eval),
        "residential": clean_eval(residential_eval),
        "land": clean_eval(land_eval),
        "same_dataset_segmented": segmented_metrics(residential_eval["best_predictions"], land_eval["best_predictions"]),
        "bias": {
            "global": bias_by_band(global_eval["best_predictions"]).to_dict(orient="records"),
            "residential": bias_by_band(residential_eval["best_predictions"]).to_dict(orient="records"),
            "land": bias_by_band(land_eval["best_predictions"]).to_dict(orient="records"),
        },
        "segments": {
            "property_type": group_metrics(global_eval["best_predictions"], "property_type").to_dict(orient="records"),
            "municipality": group_metrics(global_eval["best_predictions"], "municipality").to_dict(orient="records"),
        },
        "outlier_impact": outlier_impact,
        "decision": decisions(global_eval, residential_eval, land_eval),
    }
    (EXPERIMENT_DIR / "metrics.json").write_text(json.dumps(metrics, ensure_ascii=False, indent=2), encoding="utf-8")
    (EXPERIMENT_DIR / "dataset_summary.json").write_text(json.dumps(metrics["dataset"], ensure_ascii=False, indent=2), encoding="utf-8")
    write_readme(metrics, comparison, ablation_res, ablation_land, lc_res, lc_land)
    print_summary(metrics)
    return 0


def prepare(df: pd.DataFrame) -> pd.DataFrame:
    out = df.copy()
    out = out[out["currency"].fillna("MXN").eq("MXN")].copy()
    out["price"] = pd.to_numeric(out["price"], errors="coerce")
    out = out[out["price"].notna() & (out["price"] > 0)].copy()
    for col in set(GLOBAL_FEATURES["numeric"] + ["price_per_land_m2", "price_per_construction_m2"]):
        if col in out.columns:
            out[col] = pd.to_numeric(out[col], errors="coerce")
    out["quality_flags"] = out["quality_flags"].fillna("")
    return out.reset_index(drop=True)


def remove_quality_errors(df: pd.DataFrame) -> tuple[pd.DataFrame, list[dict]]:
    mask = df["quality_flags"].apply(lambda value: bool(set(str(value).split(",")) & LIKELY_DATA_ERROR_FLAGS))
    removed = df[mask][["source", "source_id", "property_type", "municipality", "price", "quality_flags"]].to_dict(orient="records")
    return df[~mask].reset_index(drop=True), removed


def metadata(raw, prepared, final, removed_quality):
    return {
        "created_at": now(),
        "input": str(INPUT),
        "output": str(DATASET_OUT),
        "initial_candidates": int(len(raw)),
        "after_price_currency_filters": int(len(prepared)),
        "removed_duplicates": 0,
        "removed_quality_errors": int(len(removed_quality)),
        "removed_quality_error_rows": removed_quality,
        "final_rows": int(len(final)),
        "by_source": counts(final, "source"),
        "by_market_segment": counts(final, "market_segment"),
        "by_property_type": counts(final, "property_type"),
        "by_municipality": counts(final, "municipality"),
        "by_price_band": counts(final, "price_band"),
    }


def evaluate_suite(df, features, label, baseline_func):
    baselines = baseline_func(df)
    model_results = {}
    prediction_frames = {}
    for algorithm in ["GradientBoosting", "RandomForest", "HistGradientBoosting"]:
        for log_target in [False, True]:
            key = f"{algorithm}_{'log1p' if log_target else 'price'}"
            preds = cross_val_predictions(df, features, algorithm, log_target)
            model_results[key] = prediction_metrics(preds)
            model_results[key]["algorithm"] = algorithm
            model_results[key]["log_target"] = log_target
            prediction_frames[key] = preds
    best_key = min(model_results, key=lambda key: model_results[key]["mae"])
    return {
        "label": label,
        "n": int(len(df)),
        "baselines": baselines,
        "models": model_results,
        "best_key": best_key,
        "best_algorithm": model_results[best_key]["algorithm"],
        "best_log_target": model_results[best_key]["log_target"],
        "best_metrics": model_results[best_key],
        "best_predictions": prediction_frames[best_key],
    }


def cross_val_predictions(df, features, algorithm, log_target=False):
    frames = []
    for fold, (train_idx, test_idx) in enumerate(group_folds(df)):
        train = df.iloc[train_idx].copy()
        test = df.iloc[test_idx].copy()
        model = make_pipeline(features, algorithm)
        target = np.log1p(train["price"]) if log_target else train["price"]
        model.fit(train, target)
        raw = model.predict(test)
        pred = np.expm1(raw) if log_target else raw
        pred = np.maximum(pred, 1)
        frames.append(prediction_frame(test, pred, fold))
    return pd.concat(frames, ignore_index=True)


def baselines_global(df):
    return evaluate_baselines(df, {
        "global_median": ([], 1, "price"),
        "property_type_median": (["property_type"], 1, "price"),
        "municipality_property_type_median": (["municipality", "property_type"], 5, "price"),
    })


def baselines_residential(df):
    return evaluate_baselines(df, {
        "residential_median": ([], 1, "price"),
        "property_type_median": (["property_type"], 1, "price"),
        "municipality_property_type_median": (["municipality", "property_type"], 5, "price"),
    })


def baselines_land(df):
    return evaluate_baselines(df, {
        "land_median": ([], 1, "price"),
        "municipality_median": (["municipality"], 5, "price"),
        "municipality_price_per_land_m2": (["municipality"], 5, "price_per_land_m2"),
    })


def evaluate_baselines(df, specs):
    rows = []
    for fold, (train_idx, test_idx) in enumerate(group_folds(df)):
        train = df.iloc[train_idx]
        test = df.iloc[test_idx]
        for name, (groups, min_count, mode) in specs.items():
            if mode == "price_per_land_m2":
                pred = predict_price_per_land_baseline(train, test, groups, min_count)
            elif not groups:
                pred = np.repeat(train["price"].median(), len(test))
            else:
                pred = predict_group_median(train, test, groups, min_count)
            rows.append(metric_row(name, fold, test, test["price"].to_numpy(), pred))
    return aggregate_rows(rows)


def predict_group_median(train, test, groups, min_count):
    global_median = train["price"].median()
    stats = train.groupby(groups)["price"].agg(["median", "count"]).reset_index()
    values = []
    for _, row in test.iterrows():
        match = stats
        for group in groups:
            match = match[match[group] == row[group]]
        values.append(float(match.iloc[0]["median"]) if not match.empty and int(match.iloc[0]["count"]) >= min_count else float(global_median))
    return np.array(values)


def predict_price_per_land_baseline(train, test, groups, min_count):
    global_m2 = train["price_per_land_m2"].median()
    stats = train.groupby(groups)["price_per_land_m2"].agg(["median", "count"]).reset_index()
    values = []
    for _, row in test.iterrows():
        match = stats
        for group in groups:
            match = match[match[group] == row[group]]
        price_m2 = float(match.iloc[0]["median"]) if not match.empty and int(match.iloc[0]["count"]) >= min_count else float(global_m2)
        values.append(price_m2 * float(row["land_area_m2"] or 0))
    return np.maximum(np.array(values), 1)


def make_pipeline(features, algorithm):
    pre = ColumnTransformer([
        ("num", Pipeline([("imputer", SimpleImputer(strategy="median"))]), features["numeric"]),
        ("cat", Pipeline([
            ("imputer", SimpleImputer(strategy="most_frequent")),
            ("onehot", OneHotEncoder(handle_unknown="ignore", sparse_output=False)),
        ]), features["categorical"]),
    ], remainder="drop", verbose_feature_names_out=False)
    return Pipeline([("preprocess", pre), ("model", model_factory(algorithm))])


def model_factory(name):
    if name == "GradientBoosting":
        return GradientBoostingRegressor(n_estimators=220, learning_rate=0.055, max_depth=3, random_state=RANDOM_STATE)
    if name == "RandomForest":
        return RandomForestRegressor(n_estimators=350, min_samples_leaf=3, random_state=RANDOM_STATE, n_jobs=-1)
    if name == "HistGradientBoosting":
        return HistGradientBoostingRegressor(max_iter=220, learning_rate=0.055, l2_regularization=0.05, random_state=RANDOM_STATE)
    raise ValueError(name)


def group_folds(df):
    n = min(5, df["duplicate_group_id"].nunique())
    return list(GroupKFold(n_splits=n).split(df, groups=df["duplicate_group_id"]))


def prediction_frame(test, pred, fold):
    actual = test["price"].to_numpy()
    abs_error = np.abs(actual - pred)
    pct_error = abs_error / np.maximum(actual, 1) * 100
    return pd.DataFrame({
        "source_id": test["source_id"].values,
        "source": test["source"].values,
        "property_type": test["property_type"].values,
        "market_segment": test["market_segment"].values,
        "municipality": test["municipality"].values,
        "neighborhood": test["neighborhood"].values,
        "price_band": test["price_band"].values,
        "actual_price": actual,
        "predicted_price": pred,
        "absolute_error": abs_error,
        "percentage_error": pct_error,
        "prediction_ratio": pred / np.maximum(actual, 1),
        "fold": fold,
    })


def prediction_metrics(preds):
    return metrics_from_arrays(preds["actual_price"].to_numpy(), preds["predicted_price"].to_numpy(), len(preds))


def metric_row(name, fold, test, actual, pred):
    row = metrics_from_arrays(actual, pred, len(test))
    row["model"] = name
    row["fold"] = fold
    return row


def metrics_from_arrays(actual, pred, n):
    abs_error = np.abs(actual - pred)
    pct_error = abs_error / np.maximum(actual, 1) * 100
    residual = pred - actual
    return {
        "n": int(n),
        "mae": float(mean_absolute_error(actual, pred)),
        "median_ae": float(median_absolute_error(actual, pred)),
        "rmse": float(math.sqrt(mean_squared_error(actual, pred))),
        "r2": float(r2_score(actual, pred)) if n >= 2 else None,
        "mape": float(np.mean(pct_error)),
        "median_absolute_percentage_error": float(np.median(pct_error)),
        "within_10_pct": float(np.mean(pct_error <= 10) * 100),
        "within_20_pct": float(np.mean(pct_error <= 20) * 100),
        "within_30_pct": float(np.mean(pct_error <= 30) * 100),
        "bias_mean": float(np.mean(residual)),
        "bias_median": float(np.median(residual)),
        "median_prediction_ratio": float(np.median(pred / np.maximum(actual, 1))),
    }


def aggregate_rows(rows):
    df = pd.DataFrame(rows)
    out = {}
    for name, group in df.groupby("model"):
        out[name] = {m: float(group[m].mean()) for m in ["mae", "median_ae", "rmse", "r2", "mape", "median_absolute_percentage_error", "within_10_pct", "within_20_pct", "within_30_pct", "bias_mean", "bias_median", "median_prediction_ratio"]}
        out[name]["n"] = int(group["n"].sum())
        out[name]["folds"] = int(group["fold"].nunique())
    return out


def ablation(df, feature_sets):
    rows = []
    base = None
    for name, features in feature_sets.items():
        preds = cross_val_predictions(df, features, "GradientBoosting", False)
        metrics = prediction_metrics(preds)
        row = {"experiment": name, **metrics}
        if base is None:
            base = metrics
            row.update({"delta_mae": 0, "delta_mape": 0, "delta_r2": 0})
        else:
            row.update({"delta_mae": metrics["mae"] - base["mae"], "delta_mape": metrics["mape"] - base["mape"], "delta_r2": metrics["r2"] - base["r2"]})
        rows.append(row)
    return pd.DataFrame(rows)


def learning_curve(df, features):
    rows = []
    for frac in [0.25, 0.5, 0.75, 1.0]:
        runs = []
        repeats = 6 if frac < 1 else 1
        for repeat in range(repeats):
            sample = sample_df(df, frac, repeat) if frac < 1 else df.copy()
            if len(sample) < 20:
                continue
            preds = cross_val_predictions(sample, features, "GradientBoosting", False)
            runs.append(prediction_metrics(preds))
        frame = pd.DataFrame(runs)
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


def sample_df(df, frac, repeat):
    return df.groupby("property_type", group_keys=False).apply(lambda g: g.sample(max(1, round(len(g) * frac)), random_state=RANDOM_STATE + repeat)).reset_index(drop=True)


def fit_full(df, features, algorithm, log_target):
    model = make_pipeline(features, algorithm)
    target = np.log1p(df["price"]) if log_target else df["price"]
    model.fit(df, target)
    return model


def feature_importance(model, df, features):
    if len(df) < 25:
        return pd.DataFrame()
    splitter = GroupShuffleSplit(n_splits=1, test_size=0.2, random_state=RANDOM_STATE)
    _, test_idx = next(splitter.split(df, groups=df["duplicate_group_id"]))
    test = df.iloc[test_idx]
    result = permutation_importance(model, test, test["price"], n_repeats=15, random_state=RANDOM_STATE, scoring="neg_mean_absolute_error")
    names = list(test.columns)
    if len(names) != len(result.importances_mean):
        names = [f"feature_{i}" for i in range(len(result.importances_mean))]
    return pd.DataFrame({"feature": names, "importance_mean": result.importances_mean, "importance_std": result.importances_std}).sort_values("importance_mean", ascending=False).head(20)


def bias_by_band(preds):
    rows = []
    for band in PRICE_BANDS:
        group = preds[preds["price_band"] == band]
        if group.empty:
            continue
        m = prediction_metrics(group)
        rows.append({"price_band": band, **m})
    return pd.DataFrame(rows)


def group_metrics(preds, column):
    rows = []
    for value, group in preds.groupby(column):
        m = prediction_metrics(group)
        m[column] = value
        m["sample_quality"] = "LOW_SAMPLE" if len(group) < 20 else "OK"
        rows.append(m)
    return pd.DataFrame(rows)


def outlier_comparison(final, residential, land):
    suspicious = final[final["quality_flags"].str.contains("suspicious|invalid", case=False, na=False)]
    no_quality_errors = final[~final["quality_flags"].apply(lambda v: bool(set(str(v).split(",")) & LIKELY_DATA_ERROR_FLAGS))]
    result = {
        "flagged_candidates": suspicious[["source", "source_id", "property_type", "municipality", "price", "quality_flags"]].to_dict(orient="records"),
        "likely_data_error_excluded_count": int(len(final) - len(no_quality_errors)),
    }
    if len(no_quality_errors) < len(final):
        result["global_without_likely_data_errors"] = evaluate_suite(no_quality_errors, GLOBAL_FEATURES, "global_no_errors", baselines_global)["best_metrics"]
    return result


def comparison_v1_v2(global_eval, residential_eval, land_eval):
    rows = []
    if V1_METRICS.exists():
        v1 = json.loads(V1_METRICS.read_text())
        best = v1["models"][v1["best_model"]]["metrics"]
        rows.append({"comparison_type": "historical_different_dataset", "model": "avm_v2_v1_global", **pick(best)})
        if v1.get("houses_only_experiment"):
            rows.append({"comparison_type": "historical_different_dataset", "model": "avm_v2_v1_houses_only", **pick(v1["houses_only_experiment"])})
    rows.extend([
        {"comparison_type": "v2_same_dataset_cv", "model": "avm_v2_v2_global", **pick(global_eval["best_metrics"])},
        {"comparison_type": "v2_same_dataset_cv", "model": "avm_v2_v2_residential", **pick(residential_eval["best_metrics"])},
        {"comparison_type": "v2_same_dataset_cv", "model": "avm_v2_v2_land", **pick(land_eval["best_metrics"])},
        {"comparison_type": "v2_same_dataset_cv", "model": "avm_v2_v2_segmented_combined", **pick(segmented_metrics(residential_eval["best_predictions"], land_eval["best_predictions"]))},
    ])
    return pd.DataFrame(rows)


def segmented_metrics(res_preds, land_preds):
    return prediction_metrics(pd.concat([res_preds, land_preds], ignore_index=True))


def pick(metrics):
    return {k: metrics.get(k) for k in ["n", "mae", "median_ae", "rmse", "r2", "mape", "within_20_pct", "within_30_pct", "bias_mean", "bias_median", "median_prediction_ratio"]}


def write_predictions(eval_result, name):
    eval_result["best_predictions"].to_csv(EXPERIMENT_DIR / f"predictions_{name}.csv", index=False)


def clean_eval(eval_result):
    return {k: v for k, v in eval_result.items() if k != "best_predictions"}


def dataset_summary(df):
    return {
        "records": int(len(df)),
        "by_source": counts(df, "source"),
        "by_market_segment": counts(df, "market_segment"),
        "by_property_type": counts(df, "property_type"),
        "by_municipality": counts(df, "municipality"),
        "by_price_band": counts(df, "price_band"),
        "by_training_readiness": counts(df, "training_readiness"),
        "by_coordinate_quality": counts(df, "coordinate_quality"),
    }


def decisions(global_eval, residential_eval, land_eval):
    res = residential_eval["best_metrics"]
    land = land_eval["best_metrics"]
    seg = segmented_metrics(residential_eval["best_predictions"], land_eval["best_predictions"])
    return {
        "residential": "C" if res["r2"] and res["r2"] > 0.65 and res["mape"] < 40 else "B" if res["r2"] and res["r2"] > 0.25 else "A",
        "land": "C" if land["r2"] and land["r2"] > 0.45 and land["mape"] < 60 else "B" if land["r2"] and land["r2"] > 0.10 else "A",
        "segmented_beats_global": seg["mae"] < global_eval["best_metrics"]["mae"],
    }


def write_readme(metrics, comparison, ablation_res, ablation_land, lc_res, lc_land):
    content = f"""# AVM v2 v2 Experimental

No sustituye `app/model/modelo_precio.joblib`, no modifica `/predict` y no toca Laravel.

## Dataset

{json.dumps(metrics['dataset'], ensure_ascii=False, indent=2)}

## Comparación

{comparison.to_csv(index=False)}

## Ablation residential

{ablation_res.to_csv(index=False)}

## Ablation land

{ablation_land.to_csv(index=False)}

## Learning curve residential

{lc_res.to_csv(index=False)}

## Learning curve land

{lc_land.to_csv(index=False)}
"""
    (EXPERIMENT_DIR / "README.md").write_text(content, encoding="utf-8")


def print_summary(metrics):
    print("AVM v2 v2 experimental generado")
    for label in ["global", "residential", "land"]:
        item = metrics[label]
        print(label, item["best_key"], item["best_metrics"])
    print("Decisión:", metrics["decision"])


def counts(df, col):
    return {str(k): int(v) for k, v in Counter(df[col].fillna("missing")).most_common()}


def now():
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


if __name__ == "__main__":
    raise SystemExit(main())
