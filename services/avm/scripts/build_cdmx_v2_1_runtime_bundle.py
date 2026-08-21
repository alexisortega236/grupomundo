"""Build the isolated CDMX v2.1 comparable bundle; no model is copied."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

import pandas as pd


ROOT = Path(__file__).resolve().parents[3]
SOURCE = ROOT / "services/avm/data/experiments/avm_cdmx_v1_clean.csv"
OUTPUT = ROOT / "services/avm/runtime_data/cdmx_v2_1"
FIELDS = [
    "source_id", "property_type", "municipality", "neighborhood", "inegi_cve_ageb",
    "latitude", "longitude", "price", "construction_area_m2", "land_area_m2",
    "bedrooms", "bathrooms", "parking_spaces",
]


def main():
    OUTPUT.mkdir(parents=True, exist_ok=True)
    source = SOURCE.read_bytes()
    frame = pd.read_csv(SOURCE, usecols=FIELDS)
    frame = frame[frame.property_type.isin(["casa", "departamento"])].drop_duplicates("source_id").copy()
    frame.to_csv(OUTPUT / "comparables.csv", index=False)
    config = {
        "version": "avm_cdmx_v2_1",
        "entity_code": "09",
        "source_dataset": "avm_cdmx_v1_clean.csv",
        "fields": FIELDS,
        "cascade": ["same_neighborhood", "same_ageb", "similar_1km", "similar_2km", "municipality_fallback"],
        "score_threshold": 35.0,
        "minimum_comparables": 3,
        "score_weights": {"distance": 25, "construction_similarity": 20, "land_similarity_house": 15, "bedrooms": 10, "bathrooms": 8, "parking_spaces": 5, "same_neighborhood": 10, "same_ageb": 7},
        "market_formulas": {"department": "weighted_p50(price/construction_area_m2)*construction_area_m2", "house": "weighted_p50(price/(construction_area_m2+0.5*land_area_m2))*(construction_area_m2+0.5*land_area_m2)"},
        "range": "weighted_p25_p75",
        "reconciliation": "market_base<1500000: clip(0.75+0.20*market_strength); otherwise clip(0.15+0.75*market_strength)",
        "target_leakage_safe": True,
    }
    (OUTPUT / "config.json").write_text(json.dumps(config, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    metadata = {"version": "avm_cdmx_v2_1", "entity_code": "09", "rows": len(frame), "source_sha256": hashlib.sha256(source).hexdigest(), "model_reused_from": "runtime_data/cdmx_v1/model_best_experimental.joblib", "source_currency": "MXN", "generated_by": str(Path(__file__).relative_to(ROOT))}
    (OUTPUT / "metadata.json").write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"output": str(OUTPUT), "rows": len(frame), "bytes": (OUTPUT / "comparables.csv").stat().st_size}, ensure_ascii=False))


if __name__ == "__main__":
    main()
