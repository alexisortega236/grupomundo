from flask import Flask, request, jsonify, render_template
import pandas as pd
import numpy as np
import joblib
import os
import logging

from app.services import poi_cache_sqlite
from app.services.pois_overpass import PoiProviderUnavailable, fetch_pois_enriched
from app.listings.spatial.datasets import dataset_paths, validate_datasets
from app.listings.spatial.enrichment import CensoRepository, DenueIndex, InegiSpatialIndex

app = Flask(__name__)
logger = logging.getLogger(__name__)

MODEL_PATH = os.getenv("MODEL_PATH", "app/model/modelo_precio.joblib")
CATALOGO_PATH = os.getenv("CATALOGO_PATH", "app/data/catalogo_colonias.csv")
RESIDENTIAL_V2_MODEL_PATH = os.getenv("RESIDENTIAL_V2_MODEL_PATH", "experiments/avm_v2_v2/model_residential_experimental.joblib")
RESIDENTIAL_V2_PREDS_PATH = os.getenv("RESIDENTIAL_V2_PREDS_PATH", "experiments/avm_v2_v2/predictions_residential.csv")
AVM_V2_V1_MODEL_PATH = os.getenv("AVM_V2_V1_MODEL_PATH", "experiments/avm_v2_v1/model_experimental.joblib")

POI_RADIUS_M = int(os.getenv("POI_RADIUS_M", "1000"))
POI_CACHE_TTL_SECONDS = int(os.getenv("POI_CACHE_TTL_SECONDS", str(6 * 60 * 60)))  # 6h
POI_GRID_DECIMALS = int(os.getenv("POI_GRID_DECIMALS", "3"))  # ~111m

pipe = joblib.load(MODEL_PATH) if os.path.exists(MODEL_PATH) else None
residential_v2_pipe = joblib.load(RESIDENTIAL_V2_MODEL_PATH) if os.path.exists(RESIDENTIAL_V2_MODEL_PATH) else None
avm_v2_v1_pipe = joblib.load(AVM_V2_V1_MODEL_PATH) if os.path.exists(AVM_V2_V1_MODEL_PATH) else None
residential_v2_previous_predictions = pd.read_csv(RESIDENTIAL_V2_PREDS_PATH) if os.path.exists(RESIDENTIAL_V2_PREDS_PATH) else pd.DataFrame()
_spatial_index = None
_censo_repo = None
_denue_index = None

df_cat = pd.read_csv(CATALOGO_PATH)
df_cat["colonia"] = df_cat["colonia"].astype(str).str.strip()
lookup = df_cat.set_index("colonia")[["zona", "factor_colonia"]].to_dict(orient="index")

poi_cache_sqlite.init_db()

def _grid_key(lat: float, lng: float, radius_m: int) -> str:
    lat_r = round(float(lat), POI_GRID_DECIMALS)
    lng_r = round(float(lng), POI_GRID_DECIMALS)
    return f"v1:{lat_r}:{lng_r}:r{radius_m}"

def enrich_pois(lat: float, lng: float) -> dict:
    key = _grid_key(lat, lng, POI_RADIUS_M)
    cached = poi_cache_sqlite.get(key, POI_CACHE_TTL_SECONDS)
    if cached is not None:
        cached["cache_hit"] = True
        return cached

    try:
        enriched = fetch_pois_enriched(lat=lat, lng=lng, radius_m=POI_RADIUS_M, top_n=5)
    except PoiProviderUnavailable:
        stale = poi_cache_sqlite.get_stale(key)
        if stale is not None:
            stale["cache_hit"] = True
            stale["cache_stale"] = True
            return stale
        raise

    payload = {
        "radius_m": POI_RADIUS_M,
        "counts": enriched["counts"],
        "nearest_m": enriched["nearest_m"],
        "details": enriched["details"],
        "cache_hit": False
    }

    poi_cache_sqlite.set(key, payload)
    return payload


@app.get("/")
def index():
    return render_template("index.html")

@app.get("/health")
def health():
    return jsonify({
        "status": "ok",
        "model_loaded": pipe is not None,
        "legacy_model_loaded": pipe is not None,
        "residential_v2_model_loaded": residential_v2_pipe is not None,
        "avm_v2_v1_model_loaded": avm_v2_v1_pipe is not None,
    }), 200

def _spatial_services():
    global _spatial_index, _censo_repo, _denue_index
    if _spatial_index and _censo_repo and _denue_index:
        return _spatial_index, _censo_repo, _denue_index
    paths = dataset_paths()
    errors = [e for e in validate_datasets(paths) if not e.startswith("INEGI opcional")]
    if errors:
        raise RuntimeError("; ".join(errors))
    _spatial_index = InegiSpatialIndex(paths)
    _censo_repo = CensoRepository(paths.censo_ageb_csv)
    _denue_index = DenueIndex(paths.denue_csv)
    return _spatial_index, _censo_repo, _denue_index

def _residential_v2_eligibility(data: dict) -> tuple[bool, str | None]:
    property_type = data.get("property_type")
    if property_type not in ("house", "apartment"):
        return False, "unsupported_property_type"
    if data.get("latitude") is None or data.get("longitude") is None:
        return False, "missing_location"
    if float(data.get("construction_area_m2") or 0) <= 0:
        return False, "missing_construction_area"
    if property_type == "house" and float(data.get("land_area_m2") or 0) <= 0:
        return False, "missing_land_area"
    return True, None

def _avm_v2_v1_eligibility(data: dict) -> tuple[bool, str | None]:
    if data.get("property_type") not in ("house", "apartment", "land"):
        return False, "unsupported_property_type"
    if data.get("latitude") is None or data.get("longitude") is None:
        return False, "missing_location"
    return True, None

def _real_location_context(data: dict):
    lat = float(data.get("latitude"))
    lng = float(data.get("longitude"))
    spatial, censo, denue = _spatial_services()
    match = spatial.match(lat, lng)
    if not match.cve_ageb:
        return None
    return match, censo.features(match), denue.counts(lat, lng)

def _price_band(price: float) -> str:
    if price < 1_000_000:
        return "<1M"
    if price < 2_000_000:
        return "1M-2M"
    if price < 3_000_000:
        return "2M-3M"
    if price < 5_000_000:
        return "3M-5M"
    if price < 8_000_000:
        return "5M-8M"
    if price < 12_000_000:
        return "8M-12M"
    if price < 20_000_000:
        return "12M-20M"
    return ">20M"

def _interval_for(property_type: str, prediction: float) -> tuple[float, float, float]:
    if residential_v2_previous_predictions.empty:
        pct = 0.5394617
    else:
        subset = residential_v2_previous_predictions[residential_v2_previous_predictions["property_type"] == ("casa" if property_type == "house" else "departamento")]
        if len(subset) < 20:
            subset = residential_v2_previous_predictions
        pct = float(subset["percentage_error"].quantile(0.90)) / 100
    return prediction * (1 - pct), prediction * (1 + pct), 0.90

def _confidence(property_type: str, estimated_value: float, municipality: str | None, ageb: str | None, missing: list[str]) -> str:
    if missing or not ageb:
        return "LOW"
    if estimated_value < 1_000_000 or estimated_value > 12_000_000:
        return "LOW"
    weak_municipalities = {"Cuernavaca", "Yautepec", "Temixco", "Emiliano Zapata", "Ayala"}
    if municipality in weak_municipalities:
        return "LOW"
    if property_type == "apartment":
        return "MEDIUM"
    return "MEDIUM"

@app.post("/predict/v2/residential")
def predict_v2_residential():
    data = request.get_json(force=True) or {}
    eligible, reason = _residential_v2_eligibility(data)
    if not eligible:
        return jsonify({"eligible": False, "reason": reason}), 200
    if residential_v2_pipe is None:
        return jsonify({"eligible": False, "reason": "model_not_loaded"}), 503
    try:
        lat = float(data.get("latitude"))
        lng = float(data.get("longitude"))
    except Exception:
        return jsonify({"eligible": False, "reason": "invalid_coordinates"}), 200
    try:
        context = _real_location_context(data)
    except Exception:
        logger.exception("Residential v2 spatial enrichment failed")
        return jsonify({"eligible": False, "reason": "spatial_enrichment_failed"}), 503
    if context is None:
        return jsonify({"eligible": False, "reason": "outside_validated_location"}), 200
    match, censo_values, denue_values = context
    property_type = data.get("property_type")
    row = {
        "property_type": "casa" if property_type == "house" else "departamento",
        "municipality": match.municipality or data.get("municipality"),
        "inegi_cve_ageb": match.cve_ageb,
        "land_area_m2": data.get("land_area_m2"),
        "construction_area_m2": data.get("construction_area_m2"),
        "bedrooms": data.get("bedrooms"),
        "bathrooms": data.get("bathrooms"),
        "parking_spaces": data.get("parking_spaces"),
        **censo_values,
        **denue_values,
    }
    prediction = float(max(1, np.expm1(residential_v2_pipe.predict(pd.DataFrame([row]))[0])))
    low, high, coverage = _interval_for(property_type, prediction)
    missing = [key for key in ("construction_area_m2",) if row.get(key) in (None, "")]
    confidence = _confidence(property_type, prediction, row["municipality"], match.cve_ageb, missing)
    return jsonify({
        "eligible": True,
        "model": "avm_residential_v2_v2",
        "model_version": "avm_residential_v2_v2_experimental",
        "segment": "residential",
        "property_type": property_type,
        "estimated_value": round(prediction),
        "currency": "MXN",
        "range": {
            "low": round(low),
            "high": round(high),
            "nominal_coverage": coverage,
        },
        "confidence": confidence,
        "location": {
            "municipality": row["municipality"],
            "locality": match.locality,
            "ageb": match.cve_ageb,
        },
    }), 200

@app.post("/predict/v2/v1")
def predict_v2_v1():
    data = request.get_json(force=True) or {}
    eligible, reason = _avm_v2_v1_eligibility(data)
    if not eligible:
        return jsonify({"eligible": False, "reason": reason}), 200
    if avm_v2_v1_pipe is None:
        return jsonify({"eligible": False, "reason": "model_not_loaded"}), 503

    try:
        context = _real_location_context(data)
    except (TypeError, ValueError):
        return jsonify({"eligible": False, "reason": "invalid_coordinates"}), 200
    except Exception:
        logger.exception("AVM v2 v1 spatial enrichment failed")
        return jsonify({"eligible": False, "reason": "spatial_enrichment_failed"}), 503

    if context is None:
        return jsonify({"eligible": False, "reason": "outside_validated_location"}), 200

    match, censo_values, denue_values = context
    property_type = data["property_type"]
    coordinate_quality = data.get("coordinate_quality")
    if coordinate_quality not in ("high", "medium"):
        coordinate_quality = "high" if data.get("location_precision") == "device" else "medium"

    row = {
        "property_type": {"house": "casa", "apartment": "departamento", "land": "terreno"}[property_type],
        "municipality": match.municipality or data.get("municipality"),
        "inegi_cve_ageb": match.cve_ageb,
        "neighborhood": data.get("neighborhood"),
        "coordinate_quality": coordinate_quality,
        "land_area_m2": data.get("land_area_m2"),
        "construction_area_m2": data.get("construction_area_m2"),
        "bedrooms": data.get("bedrooms"),
        "bathrooms": data.get("bathrooms"),
        "parking_spaces": data.get("parking_spaces"),
        **censo_values,
        **denue_values,
    }

    try:
        prediction = float(max(1, avm_v2_v1_pipe.predict(pd.DataFrame([row]))[0]))
    except Exception:
        logger.exception("AVM v2 v1 prediction failed")
        return jsonify({"eligible": False, "reason": "prediction_failed"}), 503

    return jsonify({
        "eligible": True,
        "model": "avm_v2_v1",
        "model_version": "avm_v2_v1_experimental",
        "segment": "land" if property_type == "land" else "residential",
        "property_type": property_type,
        "estimated_value": round(prediction),
        "currency": "MXN",
        "location": {
            "municipality": row["municipality"],
            "locality": match.locality,
            "neighborhood": data.get("neighborhood"),
            "ageb": match.cve_ageb,
            "coordinate_quality": coordinate_quality,
        },
    }), 200

@app.post("/predict")
def predict():
    data = request.get_json(force=True) or {}

    if pipe is None:
        return jsonify({
            "error": "legacy_model_not_loaded",
            "message": "El modelo legacy no está disponible en este despliegue."
        }), 503

    colonia = str(data.get("colonia", "")).strip()
    if not colonia:
        return jsonify({"error": "Falta colonia"}), 400
    if colonia not in lookup:
        return jsonify({"error": f"Colonia '{colonia}' no existe en el catálogo"}), 400

    try:
        lat = float(data.get("lat"))
        lng = float(data.get("lng"))
    except Exception:
        return jsonify({"error": "invalid_coordinates", "message": "Faltan lat/lng o no son numéricos"}), 400

    if data.get("tipo") not in ("casa", "depa", "terreno"):
        return jsonify({"error": "invalid_property_type", "message": "Tipo de propiedad no soportado por el modelo legacy"}), 400

    zona = lookup[colonia]["zona"]
    factor_colonia = float(lookup[colonia]["factor_colonia"])

    try:
        pois = enrich_pois(lat, lng)
    except PoiProviderUnavailable:
        logger.exception("POI provider unavailable")
        return jsonify({
            "error": "poi_provider_unavailable",
            "message": "No fue posible obtener el contexto geográfico requerido."
        }), 503
    except Exception:
        logger.exception("Unexpected POI enrichment error")
        return jsonify({
            "error": "poi_provider_unavailable",
            "message": "No fue posible obtener el contexto geográfico requerido."
        }), 503
    c = pois["counts"]

    cerca_escuelas = 1 if c.get("schools", 0) > 0 else 0
    cerca_transporte = 1 if c.get("bus_stops", 0) > 0 else 0

    row = {
        "tipo": data.get("tipo"),
        "zona": zona,
        "colonia": colonia,
        "factor_colonia": factor_colonia,
        "cerca_transporte": cerca_transporte,
        "cerca_escuelas": cerca_escuelas,
        "m2_terreno": int(data.get("m2_terreno", 0)),
        "m2_construccion": int(data.get("m2_construccion", 0)),
        "recamaras": int(data.get("recamaras", 0)),
        "banos": int(data.get("banos", 0)),
        "estacionamientos": int(data.get("estacionamientos", 0)),
        "antiguedad_anios": int(data.get("antiguedad_anios", 0)),
    }

    X = pd.DataFrame([row])
    pred = float(pipe.predict(X)[0])

    return jsonify({
        "precio_estimado": round(pred),
        "moneda": "MXN",
        "zona_inferida": zona,
        "colonia": colonia,
        "features_derivadas": {
            "cerca_escuelas": cerca_escuelas,
            "cerca_transporte": cerca_transporte
        },
        "pois": pois
    })

if __name__ == "__main__":
    port = int(os.environ.get("PORT", "8080"))
    app.run(host="0.0.0.0", port=port)
