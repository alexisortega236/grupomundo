"""Runtime CDMX comparable engine used by the v2.1 reconciliation."""

from __future__ import annotations

import math
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
MIN_COMPARABLES = 3
STRATEGY_TIER = {
    "same_neighborhood": 0.92,
    "same_ageb": 0.82,
    "similar_1km": 0.70,
    "similar_2km": 0.55,
    "municipality_fallback": 0.30,
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

    def _score(self, target, candidates, distances):
        result = np.maximum(0, 25 * (1 - distances / 3000.0))
        target_area = max(float(target.get("construction_area_m2") or 0), 1e-9)
        areas = candidates.construction_area_m2.to_numpy(float)
        area_ratio = np.minimum(target_area, areas) / np.maximum(target_area, areas)
        result += np.clip((area_ratio - .70) / .30, 0, 1) * WEIGHTS["construction_similarity"]
        if target.get("property_type") == "casa" and float(target.get("land_area_m2") or 0) > 0:
            lands = candidates.land_area_m2.to_numpy(float)
            valid = np.isfinite(lands) & (lands > 0)
            land_ratio = np.zeros(len(candidates))
            land_ratio[valid] = np.minimum(float(target["land_area_m2"]), lands[valid]) / np.maximum(float(target["land_area_m2"]), lands[valid])
            result += np.clip((land_ratio - .50) / .50, 0, 1) * WEIGHTS["land_similarity_house"]
        else:
            result += WEIGHTS["land_similarity_house"]
        for field, weight in (("bedrooms", 10), ("bathrooms", 8), ("parking_spaces", 5)):
            target_value = float(target.get(field) or 0)
            values = pd.to_numeric(candidates[field], errors="coerce").fillna(0).to_numpy(float)
            result += np.clip(1 - np.abs(values - target_value) / 2, 0, 1) * weight
        result += (candidates.neighborhood.fillna("").to_numpy(object) == str(target.get("neighborhood") or "")) * WEIGHTS["same_neighborhood"]
        result += (candidates.inegi_cve_ageb.fillna("").to_numpy(object) == str(target.get("inegi_cve_ageb") or "")) * WEIGHTS["same_ageb"]
        return result

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
        scores = self._score(target, candidates, distances)
        same_neighborhood = candidates.neighborhood.fillna("").eq(str(target.get("neighborhood") or "")).to_numpy() & (scores >= SCORE_THRESHOLD)
        same_ageb = candidates.inegi_cve_ageb.fillna("").eq(str(target.get("inegi_cve_ageb") or "")).to_numpy() & (scores >= SCORE_THRESHOLD)
        within_1km = (distances <= 1000) & (scores >= SCORE_THRESHOLD)
        within_2km = (distances <= 2000) & (scores >= SCORE_THRESHOLD)
        same_municipality = candidates.municipality.fillna("").eq(str(target.get("municipality") or "")).to_numpy()
        choices = [("same_neighborhood", same_neighborhood), ("same_ageb", same_ageb), ("similar_1km", within_1km), ("similar_2km", within_2km), ("municipality_fallback", same_municipality)]
        strategy, mask = choices[-1]
        for candidate_strategy, candidate_mask in choices:
            if int(candidate_mask.sum()) >= MIN_COMPARABLES or candidate_strategy == "municipality_fallback":
                strategy, mask = candidate_strategy, candidate_mask
                break
        selected = candidates.loc[mask].copy()
        # Runtime queries are not training targets; all stored listings remain eligible.
        market = self._market(target, selected, scores[mask], distances[mask])
        strength = np.clip(.35 * STRATEGY_TIER.get(strategy, .20)
                           + .25 * min(market["count"] / 10, 1)
                           + .20 * min((market["mean_score"] or 0) / 100, 1)
                           + .20 * math.exp(-min(market["dispersion"] if market["dispersion"] is not None else 2, 3)), 0, 1)
        return {"strategy": strategy, "comparables": selected, "similarity_scores": scores[mask], "distance_m": distances[mask],
                "comparable_count": market["count"], "mean_similarity": market["mean_score"], "market_strength": float(strength),
                "p25": market["p25"], "p50": market["p50"], "p75": market["p75"], "market_base": market["market"],
                "range_low": market.get("range_low"), "range_high": market.get("range_high"), "dispersion": market["dispersion"]}

    @staticmethod
    def reconcile(ml_prediction: float, market: dict) -> dict:
        base = market.get("market_base")
        if base is None or not np.isfinite(base) or base <= 0:
            return {"estimated_value": float(ml_prediction), "market_weight": 0.0, "alignment_score": 0.0, "confidence": "LOW"}
        strength = float(market.get("market_strength") or 0)
        weight = (.75 + .20 * strength) if base < 1_500_000 else (.15 + .75 * strength)
        weight = float(np.clip(weight, .05, .95))
        ratio = float(ml_prediction / base) if base else None
        alignment = float(np.clip(np.exp(-abs(np.log(np.clip(ratio, .05, 20))) / math.log(2)), 0, 1)) if ratio else 0.0
        count = int(market.get("comparable_count") or 0)
        dispersion = market.get("dispersion")
        if strength >= .75 and count >= 10 and dispersion is not None and dispersion <= .40 and alignment >= .60:
            confidence = "HIGH"
        elif strength >= .50 and count >= 5 and dispersion is not None and dispersion <= .70 and alignment >= .35:
            confidence = "MEDIUM"
        else:
            confidence = "LOW"
        return {"estimated_value": float(weight * base + (1 - weight) * ml_prediction), "market_weight": weight,
                "alignment_score": alignment, "confidence": confidence, "ml_vs_market_ratio": ratio}
