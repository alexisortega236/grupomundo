"""Runtime CDMX comparable engine used by the v2.1 reconciliation."""

from __future__ import annotations

import math
import unicodedata
from pathlib import Path

import numpy as np
import pandas as pd


WEIGHTS = {
    "distance": 25,
    "construction_similarity": 20,
    "land_similarity_house": 15,
    "bedrooms": 10,
    "bathrooms": 8,
    "parking_spaces": 5,
    "same_neighborhood": 10,
    "same_ageb": 7,
}
SCORE_THRESHOLD = 35.0
PHYSICAL_ACCEPTANCE_TOLERANCE = 0.30
STRONG_PHYSICAL_TOLERANCE = 0.20
MIN_COMPARABLES = 3
MUNICIPALITY_TOP_N = 10
STRATEGY_TIER = {
    "same_neighborhood": 1.00,
    "same_ageb": 0.85,
    "similar_1km": 0.70,
    "similar_2km": 0.55,
    "municipality_filtered": 0.25,
    "insufficient_market_evidence": 0.0,
}


def normalize_location(value: object) -> str:
    """Normalize spelling conservatively; no fuzzy matching is performed."""
    if value is None or (isinstance(value, float) and np.isnan(value)):
        return ""
    text = unicodedata.normalize("NFKC", str(value)).casefold().strip()
    text = "".join(char for char in unicodedata.normalize("NFKD", text) if not unicodedata.combining(char))
    text = "".join(" " if unicodedata.category(char).startswith("Z") else char for char in text)
    return " ".join(text.split())


def relative_similarity(target_value: object, comparable_value: object) -> float:
    """Return 1 for equal values and decrease linearly with relative difference."""
    try:
        target = float(target_value)
        comparable = float(comparable_value)
    except (TypeError, ValueError):
        return 0.0
    if not np.isfinite(target) or target <= 0 or not np.isfinite(comparable) or comparable <= 0:
        return 0.0
    return float(np.clip(1.0 - abs(comparable - target) / target, 0.0, 1.0))


def physical_metrics(target: dict, candidates: pd.DataFrame) -> dict[str, np.ndarray]:
    construction = candidates.construction_area_m2.to_numpy(float)
    construction_similarity = np.array([relative_similarity(target.get("construction_area_m2"), value) for value in construction])
    construction_within_20 = construction_similarity >= 1 - STRONG_PHYSICAL_TOLERANCE
    construction_within_30 = construction_similarity >= 1 - PHYSICAL_ACCEPTANCE_TOLERANCE
    is_house = target.get("property_type") == "casa"
    land = pd.to_numeric(candidates.land_area_m2, errors="coerce").to_numpy(float)
    if is_house:
        land_similarity = np.array([relative_similarity(target.get("land_area_m2"), value) for value in land])
        land_within_20 = land_similarity >= 1 - STRONG_PHYSICAL_TOLERANCE
        land_within_30 = land_similarity >= 1 - PHYSICAL_ACCEPTANCE_TOLERANCE
    else:
        land_similarity = np.ones(len(candidates))
        land_within_20 = np.ones(len(candidates), dtype=bool)
        land_within_30 = np.ones(len(candidates), dtype=bool)
    return {
        "construction_similarity": construction_similarity,
        "land_similarity": land_similarity,
        "physical_similarity": construction_similarity if not is_house else (construction_similarity + land_similarity) / 2,
        "construction_within_20pct": construction_within_20,
        "land_within_20pct": land_within_20,
        "both_areas_within_20pct": construction_within_20 & land_within_20,
        "construction_within_30pct": construction_within_30,
        "land_within_30pct": land_within_30,
        "both_areas_within_30pct": construction_within_30 & land_within_30,
    }


def weighted_quantile(values, weights, quantile):
    values = np.asarray(values, dtype=float)
    weights = np.asarray(weights, dtype=float)
    valid = np.isfinite(values) & np.isfinite(weights) & (weights > 0)
    if not valid.any():
        return None
    values, weights = values[valid], weights[valid]
    order = np.argsort(values)
    values, weights = values[order], weights[order]
    return float(values[np.searchsorted(np.cumsum(weights), quantile * weights.sum(), side="left")])


def haversine_m(latitude, longitude, latitudes, longitudes):
    lat1 = math.radians(float(latitude))
    lon1 = math.radians(float(longitude))
    lat2 = np.radians(np.asarray(latitudes, dtype=float))
    lon2 = np.radians(np.asarray(longitudes, dtype=float))
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = np.sin(dlat / 2) ** 2 + math.cos(lat1) * np.cos(lat2) * np.sin(dlon / 2) ** 2
    return 6371000 * 2 * np.arcsin(np.sqrt(np.clip(a, 0, 1)))


class ComparableEngine:
    def __init__(self, csv_path: Path, config: dict | None = None):
        self.csv_path = Path(csv_path)
        self.frame = pd.read_csv(self.csv_path)
        self.config = config or {}
        numeric = ["latitude", "longitude", "price", "construction_area_m2", "land_area_m2", "bedrooms", "bathrooms", "parking_spaces"]
        for column in numeric:
            self.frame[column] = pd.to_numeric(self.frame[column], errors="coerce")
        self.frame = self.frame.dropna(subset=["latitude", "longitude", "price", "construction_area_m2"]).reset_index(drop=True)
        self.frame["_neighborhood_key"] = self.frame.neighborhood.map(normalize_location)
        self.frame["_municipality_key"] = self.frame.municipality.map(normalize_location)
        self.frame["_ageb_key"] = self.frame.inegi_cve_ageb.map(normalize_location)

    def _score(self, target, candidates, distances, physical=None):
        physical = physical or physical_metrics(target, candidates)
        result = np.maximum(0, 1 - distances / 3000.0) * WEIGHTS["distance"]
        result += physical["construction_similarity"] * WEIGHTS["construction_similarity"]
        result += physical["land_similarity"] * WEIGHTS["land_similarity_house"]
        for field, weight in (("bedrooms", 10), ("bathrooms", 8), ("parking_spaces", 5)):
            target_value = float(target.get(field) or 0)
            values = pd.to_numeric(candidates[field], errors="coerce").fillna(0).to_numpy(float)
            result += np.clip(1 - np.abs(values - target_value) / 2, 0, 1) * weight
        result += (candidates["_neighborhood_key"].to_numpy(object) == normalize_location(target.get("neighborhood"))) * WEIGHTS["same_neighborhood"]
        result += (candidates["_ageb_key"].to_numpy(object) == normalize_location(target.get("inegi_cve_ageb"))) * WEIGHTS["same_ageb"]
        return np.asarray(result, dtype=float)

    def _accepted(self, target, scores, physical):
        accepted = scores >= SCORE_THRESHOLD
        if target.get("property_type") == "casa":
            accepted &= physical["both_areas_within_30pct"]
        else:
            accepted &= physical["construction_within_30pct"]
        return accepted

    def _market(self, target, selected, scores, distances):
        if selected.empty:
            return {"count": 0, "p25": None, "p50": None, "p75": None, "market": None, "dispersion": None, "mean_score": None}
        construction = selected.construction_area_m2.to_numpy(float)
        is_house = target.get("property_type") == "casa"
        land = selected.land_area_m2.to_numpy(float)
        target_land = float(target.get("land_area_m2") or 0)
        if is_house and target_land > 0:
            denominator = construction + .50 * land
            valid = np.isfinite(land) & (land > 0) & np.isfinite(denominator) & (denominator > 0)
            unit = selected.price.to_numpy(float)[valid] / denominator[valid]
            target_denominator = float(target["construction_area_m2"]) + .50 * target_land
        else:
            valid = np.isfinite(construction) & (construction > 0)
            unit = selected.price.to_numpy(float)[valid] / construction[valid]
            target_denominator = float(target["construction_area_m2"])
        scores, distances = np.asarray(scores)[valid], np.asarray(distances)[valid]
        if not len(unit) or target_denominator <= 0:
            return {"count": 0, "p25": None, "p50": None, "p75": None, "market": None, "dispersion": None, "mean_score": None}
        weights = np.exp(np.clip(scores / 50, 0, 3)) / (1 + distances / 1000)
        p25 = weighted_quantile(unit, weights, .25)
        p50 = weighted_quantile(unit, weights, .50)
        p75 = weighted_quantile(unit, weights, .75)
        return {"count": int(len(unit)), "p25": p25, "p50": p50, "p75": p75,
                "market": p50 * target_denominator, "range_low": p25 * target_denominator,
                "range_high": p75 * target_denominator,
                "dispersion": float((p75 - p25) / p50) if p50 and p50 > 0 else None,
                "mean_score": float(scores.mean())}

    def find(self, target: dict) -> dict:
        candidates = self.frame[self.frame.property_type.eq(target.get("property_type"))].copy()
        distances = haversine_m(target["latitude"], target["longitude"], candidates.latitude, candidates.longitude)
        physical = physical_metrics(target, candidates)
        same_neighborhood = candidates["_neighborhood_key"].eq(normalize_location(target.get("neighborhood"))).to_numpy()
        same_ageb = candidates["_ageb_key"].eq(normalize_location(target.get("inegi_cve_ageb"))).to_numpy()
        scores = self._score(target, candidates, distances, physical)
        accepted = self._accepted(target, scores, physical)
        localized = {
            "same_neighborhood": same_neighborhood & accepted,
            "same_ageb": same_ageb & accepted,
            "similar_1km": (distances <= 1000) & accepted,
            "similar_2km": (distances <= 2000) & accepted,
        }
        municipality = candidates["_municipality_key"].eq(normalize_location(target.get("municipality"))).to_numpy()
        strategy = "insufficient_market_evidence"
        mask = municipality & accepted
        for candidate_strategy, candidate_mask in localized.items():
            if int(candidate_mask.sum()) >= MIN_COMPARABLES:
                strategy, mask = candidate_strategy, candidate_mask
                break
        else:
            order = np.lexsort((distances, -scores))
            allowed = order[municipality[order] & accepted[order]][:MUNICIPALITY_TOP_N]
            mask = np.zeros(len(candidates), dtype=bool)
            mask[allowed] = True
            if len(allowed) >= MIN_COMPARABLES:
                strategy = "municipality_filtered"
        selected = candidates.loc[mask].copy()
        selected["comparable_quality_score"] = scores[mask]
        selected["construction_similarity"] = physical["construction_similarity"][mask]
        selected["land_similarity"] = physical["land_similarity"][mask]
        selected["physical_similarity"] = physical["physical_similarity"][mask]
        selected["construction_within_20pct"] = physical["construction_within_20pct"][mask]
        selected["land_within_20pct"] = physical["land_within_20pct"][mask]
        selected["both_areas_within_20pct"] = physical["both_areas_within_20pct"][mask]
        selected["construction_within_30pct"] = physical["construction_within_30pct"][mask]
        selected["land_within_30pct"] = physical["land_within_30pct"][mask]
        selected["both_areas_within_30pct"] = physical["both_areas_within_30pct"][mask]
        market = self._market(target, selected, scores[mask], distances[mask])
        selected_scores = scores[mask]
        selected_distances = distances[mask]
        same_neighborhood_selected = same_neighborhood[mask]
        same_ageb_selected = same_ageb[mask]
        strong_physical = physical["both_areas_within_20pct"][mask] if target.get("property_type") == "casa" else physical["construction_within_20pct"][mask]
        acceptable_physical = physical["both_areas_within_30pct"][mask] if target.get("property_type") == "casa" else physical["construction_within_30pct"][mask]
        high = (selected_scores >= 70) & strong_physical
        medium = (selected_scores >= 50) & acceptable_physical & ~high
        evidence = {
            "candidate_count": int(len(candidates)),
            "municipality_candidate_count": int(municipality.sum()),
            "score_threshold_count": int((scores >= SCORE_THRESHOLD).sum()),
            "municipality_score_threshold_count": int((municipality & (scores >= SCORE_THRESHOLD)).sum()),
            "accepted_candidate_count": int(accepted.sum()),
            "municipality_accepted_count": int((accepted & municipality).sum()),
            "municipality_both_areas_within_20pct_count": int((municipality & physical["both_areas_within_20pct"]).sum()),
            "municipality_both_areas_within_30pct_count": int((municipality & physical["both_areas_within_30pct"]).sum()),
            "comparable_count": int(market["count"]),
            "qualified_count": int(accepted[mask].sum()),
            "high_quality_count": int(high.sum()),
            "medium_quality_count": int(medium.sum()),
            "mean_quality_score": float(selected_scores.mean()) if len(selected_scores) else None,
            "median_quality_score": float(np.median(selected_scores)) if len(selected_scores) else None,
            "max_quality_score": float(selected_scores.max()) if len(selected_scores) else None,
            "same_neighborhood_count": int(same_neighborhood_selected.sum()),
            "same_ageb_count": int(same_ageb_selected.sum()),
            "within_1km_count": int((selected_distances <= 1000).sum()),
            "within_2km_count": int((selected_distances <= 2000).sum()),
            "both_areas_within_20pct_count": int(physical["both_areas_within_20pct"][mask].sum()),
            "both_areas_within_30pct_count": int(physical["both_areas_within_30pct"][mask].sum()),
        }
        strategy_factor = STRATEGY_TIER[strategy]
        count_factor = min(evidence["comparable_count"] / 5, 1)
        quality_factor = (evidence["mean_quality_score"] or 0) / 100
        strong_factor = min(evidence["high_quality_count"] / 3, 1)
        location_factor = ((evidence["same_neighborhood_count"] + evidence["same_ageb_count"] + evidence["within_1km_count"]) / max(evidence["comparable_count"], 1))
        dispersion_factor = math.exp(-min(market["dispersion"] if market["dispersion"] is not None else 2, 3))
        strength = float(np.clip(.25 * strategy_factor + .20 * count_factor + .25 * quality_factor + .15 * strong_factor + .10 * min(location_factor, 1) + .05 * dispersion_factor, 0, 1))
        if strategy == "insufficient_market_evidence":
            market = {**market, "market": None, "range_low": None, "range_high": None}
        return {"strategy": strategy, "comparables": selected, "similarity_scores": selected_scores,
                "quality_scores": selected_scores, "distance_m": selected_distances,
                **evidence, "mean_similarity": market["mean_score"], "market_strength": strength,
                "p25": market["p25"] if market["market"] is not None else None,
                "p50": market["p50"] if market["market"] is not None else None,
                "p75": market["p75"] if market["market"] is not None else None,
                "market_base": market["market"], "range_low": market.get("range_low"),
                "range_high": market.get("range_high"), "dispersion": market["dispersion"],
                "physical_metrics": {key: value[mask] for key, value in physical.items()}}

    @staticmethod
    def reconcile(ml_prediction: float, market: dict) -> dict:
        base = market.get("market_base")
        strength = float(market.get("market_strength") or 0)
        if base is None or not np.isfinite(base) or base <= 0:
            return {"estimated_value": float(ml_prediction), "market_weight": 0.0, "alignment_score": 0.0, "confidence": "LOW", "ml_vs_market_ratio": None}
        weight = float(np.clip((.75 + .20 * strength) if base < 1_500_000 else (.15 + .75 * strength), .05, .95))
        ratio = float(ml_prediction / base) if base else None
        alignment = float(np.clip(np.exp(-abs(np.log(np.clip(ratio, .05, 20))) / math.log(2)), 0, 1)) if ratio else 0.0
        count = int(market.get("comparable_count") or 0)
        high = int(market.get("high_quality_count") or 0)
        dispersion = market.get("dispersion")
        localized = market.get("strategy") in {"same_neighborhood", "same_ageb", "similar_1km", "similar_2km"}
        if strength >= .75 and localized and high >= 3 and count >= 5 and dispersion is not None and dispersion <= .40 and alignment >= .60:
            confidence = "HIGH"
        elif strength >= .60 and localized and high >= 2 and count >= 5 and dispersion is not None and dispersion <= .70 and alignment >= .35:
            confidence = "MEDIUM"
        else:
            confidence = "LOW"
        return {"estimated_value": float(weight * base + (1 - weight) * ml_prediction), "market_weight": weight,
                "alignment_score": alignment, "confidence": confidence, "ml_vs_market_ratio": ratio}
