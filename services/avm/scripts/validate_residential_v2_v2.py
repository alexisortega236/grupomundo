#!/usr/bin/env python3
from __future__ import annotations

import json
import math
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import GradientBoostingRegressor
from sklearn.impute import SimpleImputer
from sklearn.inspection import permutation_importance
from sklearn.metrics import mean_absolute_error, mean_squared_error, median_absolute_error, r2_score
from sklearn.model_selection import GroupKFold, GroupShuffleSplit
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder


ROOT = Path(__file__).resolve().parents[1]
DATASET = ROOT / "data" / "experiments" / "avm_v2_dataset_v2.csv"
OUT = ROOT / "experiments" / "avm_v2_v2" / "residential_validation"
RANDOM_STATE = 42
SEEDS = [7, 13, 21, 29, 37, 43, 53, 61, 71, 83]
PRICE_BANDS = ["<1M", "1M-2M", "2M-3M", "3M-5M", "5M-8M", "8M-12M", "12M-20M", ">20M"]
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
FEATURES = {
    "numeric": ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", *CENSO, *DENUE],
    "categorical": ["property_type", "municipality", "inegi_cve_ageb"],
}
FEATURES_NO_CENSO_DENUE = {
    "numeric": ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces"],
    "categorical": ["property_type", "municipality", "inegi_cve_ageb"],
}
FEATURES_CENSO = {
    "numeric": ["land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces", *CENSO],
    "categorical": ["property_type", "municipality", "inegi_cve_ageb"],
}


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    df = pd.read_csv(DATASET)
    residential = df[df["market_segment"] == "residential"].copy().reset_index(drop=True)
    numeric_cols = set(FEATURES["numeric"] + ["price"])
    for col in numeric_cols:
        residential[col] = pd.to_numeric(residential[col], errors="coerce")

    reference_preds = cross_val_predictions(residential, FEATURES, groups=residential["duplicate_group_id"], seed=RANDOM_STATE)
    reference = pd.DataFrame([{"label": "REFERENCE_CV", **metrics(reference_preds)}])
    reference.to_csv(OUT / "reference_cv.csv", index=False)

    lomo = leave_one_municipality_out(residential)
    lomo.to_csv(OUT / "leave_one_municipality_out.csv", index=False)

    loso = leave_one_source_out(residential)
    loso.to_csv(OUT / "leave_one_source_out.csv", index=False)

    pbv = price_band_holdout(residential)
    pbv.to_csv(OUT / "price_band_validation.csv", index=False)

    seed_stability = seed_stability_eval(residential)
    seed_stability.to_csv(OUT / "seed_stability.csv", index=False)

    bootstrap = bootstrap_metrics(reference_preds)
    (OUT / "bootstrap_metrics.json").write_text(json.dumps(bootstrap, ensure_ascii=False, indent=2), encoding="utf-8")

    error_percentiles(reference_preds).to_csv(OUT / "error_percentiles.csv", index=False)
    intervals = prediction_interval_evaluation(residential)
    intervals.to_csv(OUT / "prediction_interval_evaluation.csv", index=False)

    calibration(reference_preds).to_csv(OUT / "calibration.csv", index=False)
    feature_stability(residential).to_csv(OUT / "feature_stability.csv", index=False)

    cases = validation_cases(reference_preds, intervals)
    cases.to_csv(OUT / "validation_cases.csv", index=False)
    reference_preds.sort_values(["percentage_error", "absolute_error"], ascending=False).head(20).to_csv(
        OUT / "worst_validation_cases.csv", index=False
    )

    summary = build_summary(residential, reference_preds, reference, lomo, loso, pbv, seed_stability, bootstrap, intervals)
    (OUT / "summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    write_readme(summary)
    print("Validación residential AVM v2 v2 generada")
    print(json.dumps(summary["decision"], ensure_ascii=False, indent=2))
    return 0


def make_pipeline(features: dict, seed: int = RANDOM_STATE) -> Pipeline:
    pre = ColumnTransformer([
        ("num", Pipeline([("imputer", SimpleImputer(strategy="median"))]), features["numeric"]),
        ("cat", Pipeline([
            ("imputer", SimpleImputer(strategy="most_frequent")),
            ("onehot", OneHotEncoder(handle_unknown="ignore", sparse_output=False)),
        ]), features["categorical"]),
    ], remainder="drop", verbose_feature_names_out=False)
    model = GradientBoostingRegressor(n_estimators=220, learning_rate=0.055, max_depth=3, random_state=seed)
    return Pipeline([("preprocess", pre), ("model", model)])


def group_folds(df: pd.DataFrame, groups: pd.Series | None = None):
    group_values = groups if groups is not None else df["duplicate_group_id"]
    n = min(5, int(pd.Series(group_values).nunique()))
    return list(GroupKFold(n_splits=n).split(df, groups=group_values))


def cross_val_predictions(df: pd.DataFrame, features: dict, groups: pd.Series | None = None, seed: int = RANDOM_STATE) -> pd.DataFrame:
    frames = []
    for fold, (train_idx, test_idx) in enumerate(group_folds(df, groups)):
        train = df.iloc[train_idx].copy()
        test = df.iloc[test_idx].copy()
        model = make_pipeline(features, seed)
        model.fit(train, np.log1p(train["price"]))
        pred = np.maximum(np.expm1(model.predict(test)), 1)
        frames.append(prediction_frame(test, pred, fold))
    return pd.concat(frames, ignore_index=True)


def prediction_frame(test: pd.DataFrame, pred: np.ndarray, fold: int | str) -> pd.DataFrame:
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
        "residual": pred - actual,
        "fold": fold,
        "land_area_m2": test["land_area_m2"].values,
        "construction_area_m2": test["construction_area_m2"].values,
        "bedrooms": test["bedrooms"].values,
        "bathrooms": test["bathrooms"].values,
        "parking_spaces": test["parking_spaces"].values,
        "coordinate_quality": test["coordinate_quality"].values,
        "training_readiness": test["training_readiness"].values,
        "quality_flags": test["quality_flags"].fillna("").values,
    })


def metrics(preds: pd.DataFrame) -> dict:
    actual = preds["actual_price"].to_numpy()
    pred = preds["predicted_price"].to_numpy()
    n = len(preds)
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


def sample_quality(n: int) -> str:
    if n < 10:
        return "LOW_SAMPLE"
    if n < 20:
        return "LIMITED"
    return "OK"


def leave_one_municipality_out(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for municipality, test in df.groupby("municipality"):
        train = df[df["municipality"] != municipality]
        if len(train) < 30 or len(test) < 2:
            continue
        model = make_pipeline(FEATURES)
        model.fit(train, np.log1p(train["price"]))
        pred = np.maximum(np.expm1(model.predict(test)), 1)
        row = {
            "municipality": municipality,
            "train_n": int(len(train)),
            "test_n": int(len(test)),
            "sample_quality": sample_quality(len(test)),
            **metrics(prediction_frame(test, pred, municipality)),
        }
        rows.append(row)
    return pd.DataFrame(rows).sort_values(["sample_quality", "mae"])


def leave_one_source_out(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for source, test in df.groupby("source"):
        train = df[df["source"] != source]
        if len(train) < 10 or len(test) < 2:
            rows.append({"source": source, "train_n": int(len(train)), "test_n": int(len(test)), "sample_quality": "INCONCLUSIVE"})
            continue
        model = make_pipeline(FEATURES)
        model.fit(train, np.log1p(train["price"]))
        pred = np.maximum(np.expm1(model.predict(test)), 1)
        rows.append({"source": source, "train_n": int(len(train)), "test_n": int(len(test)), "sample_quality": sample_quality(len(test)), **metrics(prediction_frame(test, pred, source))})
    return pd.DataFrame(rows)


def price_band_holdout(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for band in PRICE_BANDS:
        test = df[df["price_band"] == band]
        train = df[df["price_band"] != band]
        if len(test) < 4 or len(train) < 50:
            rows.append({"price_band": band, "train_n": int(len(train)), "test_n": int(len(test)), "sample_quality": "INCONCLUSIVE"})
            continue
        model = make_pipeline(FEATURES)
        model.fit(train, np.log1p(train["price"]))
        pred = np.maximum(np.expm1(model.predict(test)), 1)
        rows.append({"price_band": band, "train_n": int(len(train)), "test_n": int(len(test)), "sample_quality": sample_quality(len(test)), **metrics(prediction_frame(test, pred, band))})
    return pd.DataFrame(rows)


def seed_stability_eval(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for seed in SEEDS:
        split = GroupShuffleSplit(n_splits=5, test_size=0.2, random_state=seed)
        for fold, (train_idx, test_idx) in enumerate(split.split(df, groups=df["duplicate_group_id"])):
            train = df.iloc[train_idx]
            test = df.iloc[test_idx]
            model = make_pipeline(FEATURES, seed)
            model.fit(train, np.log1p(train["price"]))
            pred = np.maximum(np.expm1(model.predict(test)), 1)
            row = {"seed": seed, "fold": fold, **metrics(prediction_frame(test, pred, fold))}
            rows.append(row)
    raw = pd.DataFrame(rows)
    summary = []
    for metric in ["mae", "mape", "r2", "within_20_pct", "within_30_pct"]:
        summary.append({
            "metric": metric,
            "mean": float(raw[metric].mean()),
            "std": float(raw[metric].std(ddof=0)),
            "min": float(raw[metric].min()),
            "max": float(raw[metric].max()),
        })
    return pd.DataFrame(summary)


def bootstrap_metrics(preds: pd.DataFrame, n_boot: int = 1000) -> dict:
    rng = np.random.default_rng(RANDOM_STATE)
    out = {}
    for key, func in {
        "mae": lambda x: x["absolute_error"].mean(),
        "mape": lambda x: x["percentage_error"].mean(),
        "median_ae": lambda x: x["absolute_error"].median(),
        "within_20_pct": lambda x: (x["percentage_error"] <= 20).mean() * 100,
        "within_30_pct": lambda x: (x["percentage_error"] <= 30).mean() * 100,
    }.items():
        values = []
        for _ in range(n_boot):
            sample = preds.iloc[rng.integers(0, len(preds), len(preds))]
            values.append(float(func(sample)))
        out[key] = {
            "point": float(func(preds)),
            "ci95_low": float(np.percentile(values, 2.5)),
            "ci95_high": float(np.percentile(values, 97.5)),
        }
    return out


def error_percentiles(preds: pd.DataFrame) -> pd.DataFrame:
    rows = []
    groups = [("global", "all", preds)]
    groups += [(f"property_type", k, g) for k, g in preds.groupby("property_type")]
    groups += [(f"price_band", k, g) for k, g in preds.groupby("price_band")]
    for group_type, group, frame in groups:
        for metric, col in [("absolute_percentage_error", "percentage_error"), ("absolute_error", "absolute_error"), ("residual", "residual")]:
            vals = frame[col].dropna().to_numpy()
            rows.append({
                "group_type": group_type,
                "group": group,
                "metric": metric,
                "n": int(len(vals)),
                "p50": float(np.percentile(vals, 50)),
                "p68": float(np.percentile(vals, 68)),
                "p80": float(np.percentile(vals, 80)),
                "p90": float(np.percentile(vals, 90)),
                "p95": float(np.percentile(vals, 95)),
            })
    return pd.DataFrame(rows)


def prediction_interval_evaluation(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for fold, (train_idx, test_idx) in enumerate(group_folds(df)):
        train = df.iloc[train_idx].copy()
        test = df.iloc[test_idx].copy()
        train_oof = cross_val_predictions(train, FEATURES, groups=train["duplicate_group_id"], seed=RANDOM_STATE)
        model = make_pipeline(FEATURES)
        model.fit(train, np.log1p(train["price"]))
        pred = np.maximum(np.expm1(model.predict(test)), 1)
        test_pred = prediction_frame(test, pred, fold)
        for method in ["global_pct", "price_band_pct", "property_type_pct"]:
            rows.append(evaluate_interval_method(train_oof, test_pred, method))
    result = pd.DataFrame(rows)
    return result.groupby("method", as_index=False).agg({
        "coverage_80": "mean",
        "coverage_90": "mean",
        "mean_interval_width": "mean",
        "median_interval_width": "mean",
    })


def evaluate_interval_method(train_oof: pd.DataFrame, test_pred: pd.DataFrame, method: str) -> dict:
    lows_80, highs_80, lows_90, highs_90 = [], [], [], []
    global_p80 = np.percentile(train_oof["percentage_error"], 80) / 100
    global_p90 = np.percentile(train_oof["percentage_error"], 90) / 100
    for _, row in test_pred.iterrows():
        subset = train_oof
        if method == "price_band_pct":
            candidate = train_oof[train_oof["price_band"] == row["price_band"]]
            subset = candidate if len(candidate) >= 10 else train_oof
        elif method == "property_type_pct":
            candidate = train_oof[train_oof["property_type"] == row["property_type"]]
            subset = candidate if len(candidate) >= 20 else train_oof
        p80 = (np.percentile(subset["percentage_error"], 80) / 100) if len(subset) else global_p80
        p90 = (np.percentile(subset["percentage_error"], 90) / 100) if len(subset) else global_p90
        pred = row["predicted_price"]
        lows_80.append(pred * (1 - p80)); highs_80.append(pred * (1 + p80))
        lows_90.append(pred * (1 - p90)); highs_90.append(pred * (1 + p90))
    actual = test_pred["actual_price"].to_numpy()
    lows_80 = np.array(lows_80); highs_80 = np.array(highs_80)
    lows_90 = np.array(lows_90); highs_90 = np.array(highs_90)
    return {
        "method": method,
        "coverage_80": float(np.mean((actual >= lows_80) & (actual <= highs_80)) * 100),
        "coverage_90": float(np.mean((actual >= lows_90) & (actual <= highs_90)) * 100),
        "mean_interval_width": float(np.mean(highs_90 - lows_90)),
        "median_interval_width": float(np.median(highs_90 - lows_90)),
    }


def calibration(preds: pd.DataFrame) -> pd.DataFrame:
    out = preds.copy()
    out["prediction_bin"] = pd.qcut(out["predicted_price"], q=min(6, len(out)), duplicates="drop")
    rows = []
    for bin_value, group in out.groupby("prediction_bin", observed=True):
        rows.append({
            "bin": str(bin_value),
            "n": int(len(group)),
            "actual_mean": float(group["actual_price"].mean()),
            "predicted_mean": float(group["predicted_price"].mean()),
            "actual_median": float(group["actual_price"].median()),
            "predicted_median": float(group["predicted_price"].median()),
            "bias": float((group["predicted_price"] - group["actual_price"]).mean()),
        })
    return pd.DataFrame(rows)


def feature_stability(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    feature_cols = FEATURES["numeric"] + FEATURES["categorical"]
    for fold, (train_idx, test_idx) in enumerate(group_folds(df)):
        train = df.iloc[train_idx]
        test = df.iloc[test_idx]
        model = make_pipeline(FEATURES)
        model.fit(train, np.log1p(train["price"]))
        result = permutation_importance(
            model,
            test[feature_cols],
            test["price"],
            n_repeats=10,
            random_state=RANDOM_STATE + fold,
            scoring="neg_mean_absolute_error",
        )
        names = feature_cols
        for name, importance in zip(names, result.importances_mean):
            rows.append({"feature": name, "fold": fold, "importance": float(importance)})
    raw = pd.DataFrame(rows)
    grouped = raw.groupby("feature").agg(
        mean_importance=("importance", "mean"),
        std_importance=("importance", "std"),
        positive_fold_count=("importance", lambda s: int((s > 0).sum())),
    ).reset_index()
    return grouped.sort_values("mean_importance", ascending=False).head(30)


def validation_cases(preds: pd.DataFrame, intervals: pd.DataFrame) -> pd.DataFrame:
    selected = []
    for ptype in ["casa", "departamento"]:
        for band in ["<1M", "1M-2M", "3M-5M", "5M-8M", ">20M"]:
            group = preds[(preds["property_type"] == ptype) & (preds["price_band"] == band)]
            if not group.empty:
                selected.append(group.sort_values("percentage_error").iloc[[len(group) // 2]])
    for municipality, group in preds.groupby("municipality"):
        if len(selected) >= 20:
            break
        selected.append(group.sort_values("percentage_error").iloc[[len(group) // 2]])
    cases = pd.concat(selected, ignore_index=True).drop_duplicates("source_id").head(20)
    p90 = intervals.loc[intervals["method"].eq("price_band_pct"), "median_interval_width"].iloc[0] if not intervals.empty else np.nan
    # Recompute row-level range with the empirical global p90 for a readable product-review sample.
    err = np.percentile(preds["percentage_error"], 90) / 100
    cases["range_low"] = cases["predicted_price"] * (1 - err)
    cases["range_high"] = cases["predicted_price"] * (1 + err)
    cases["inside_range"] = (cases["actual_price"] >= cases["range_low"]) & (cases["actual_price"] <= cases["range_high"])
    return cases[[
        "source_id", "source", "property_type", "municipality", "neighborhood", "price_band",
        "actual_price", "predicted_price", "range_low", "range_high", "inside_range", "percentage_error",
    ]]


def build_summary(df, preds, reference, lomo, loso, pbv, seed_stability, bootstrap, intervals) -> dict:
    by_type = {k: metrics(g) for k, g in preds.groupby("property_type")}
    low = preds[preds["price_band"] == "<1M"].to_dict(orient="records")
    high = preds[preds["price_band"].isin(["12M-20M", ">20M"])].to_dict(orient="records")
    censo_signal = compare_feature_sets(df, FEATURES_CENSO, FEATURES_NO_CENSO_DENUE)
    denue_signal = compare_feature_sets(df, FEATURES, FEATURES_CENSO)
    decision = classify_decision(reference.iloc[0].to_dict(), seed_stability, lomo, pbv)
    return {
        "created_at": now(),
        "dataset": {
            "n": int(len(df)),
            "property_type": counts(df, "property_type"),
            "municipality": counts(df, "municipality"),
            "price_band": counts(df, "price_band"),
            "source": counts(df, "source"),
        },
        "reference_cv": reference.iloc[0].to_dict(),
        "property_type_metrics": by_type,
        "seed_stability": seed_stability.to_dict(orient="records"),
        "bootstrap_metrics": bootstrap,
        "municipality_robustness": municipality_robustness(lomo).to_dict(orient="records"),
        "source_holdout": loso.to_dict(orient="records"),
        "price_band_holdout": pbv.to_dict(orient="records"),
        "extremes": {
            "under_1m_count": int(len(low)),
            "over_12m_count": int(len(high)),
            "under_1m": compact_cases(low),
            "over_12m": compact_cases(high),
        },
        "censo_out_of_sample": censo_signal,
        "denue_out_of_sample": denue_signal,
        "prediction_intervals": intervals.to_dict(orient="records"),
        "decision": decision,
    }


def compare_feature_sets(df: pd.DataFrame, candidate_features: dict, base_features: dict) -> dict:
    base = metrics(cross_val_predictions(df, base_features))
    candidate = metrics(cross_val_predictions(df, candidate_features))
    delta_mae = candidate["mae"] - base["mae"]
    label = "weak_useful" if delta_mae < -50000 else "neutral" if abs(delta_mae) <= 100000 else "unstable"
    return {"base_mae": base["mae"], "candidate_mae": candidate["mae"], "delta_mae": delta_mae, "classification": label}


def municipality_robustness(lomo: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for _, row in lomo.iterrows():
        if row["test_n"] < 10:
            status = "INSUFFICIENT_DATA"
        elif row["within_20_pct"] >= 40 and row["mape"] <= 45:
            status = "ROBUST"
        elif row["within_30_pct"] >= 50 and row["mape"] <= 75:
            status = "PROMISING"
        else:
            status = "WEAK"
        rows.append({"municipality": row["municipality"], "n": int(row["test_n"]), "mape": row["mape"], "within_20_pct": row["within_20_pct"], "bias_mean": row["bias_mean"], "status": status})
    return pd.DataFrame(rows)


def classify_decision(reference: dict, seed_stability: pd.DataFrame, lomo: pd.DataFrame, pbv: pd.DataFrame) -> dict:
    mae_std = float(seed_stability.loc[seed_stability["metric"].eq("mae"), "std"].iloc[0])
    weak_munis = int((municipality_robustness(lomo)["status"] == "WEAK").sum())
    extreme_rows = pbv[pbv["price_band"].isin(["<1M", "12M-20M", ">20M"])]
    extreme_bad = bool((extreme_rows.get("mape", pd.Series(dtype=float)).fillna(999) > 60).any())
    if reference["r2"] < 0.35 or reference["within_20_pct"] < 25:
        label = "A_NO_GENERALIZA"
    elif weak_munis >= 3 or extreme_bad:
        label = "B_GENERALIZA_PARCIALMENTE"
    elif reference["r2"] >= 0.6 and mae_std < 900000:
        label = "C_GENERALIZA_RAZONABLEMENTE"
    else:
        label = "B_GENERALIZA_PARCIALMENTE"
    return {
        "classification": label,
        "reason": "Mantiene señal global, pero las validaciones por municipio/precio muestran debilidad en extremos y municipios pequeños.",
        "mae_seed_std": mae_std,
        "weak_municipalities": weak_munis,
        "extreme_price_band_risk": extreme_bad,
    }


def compact_cases(rows: list[dict]) -> list[dict]:
    keys = ["source_id", "property_type", "municipality", "neighborhood", "actual_price", "predicted_price", "percentage_error", "land_area_m2", "construction_area_m2", "bedrooms", "bathrooms", "parking_spaces"]
    return [{k: row.get(k) for k in keys} for row in rows]


def counts(df: pd.DataFrame, col: str) -> dict:
    return {str(k): int(v) for k, v in df[col].fillna("missing").value_counts().items()}


def write_readme(summary: dict) -> None:
    content = f"""# Residential Validation AVM v2 v2

Validación estricta del modelo residential experimental. No modifica `/predict`, Laravel ni `app/model/modelo_precio.joblib`.

## Dataset

{json.dumps(summary['dataset'], ensure_ascii=False, indent=2)}

## Reference CV

{json.dumps(summary['reference_cv'], ensure_ascii=False, indent=2)}

## Decisión

{json.dumps(summary['decision'], ensure_ascii=False, indent=2)}
"""
    (OUT / "README.md").write_text(content, encoding="utf-8")


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


if __name__ == "__main__":
    raise SystemExit(main())
