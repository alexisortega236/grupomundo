# Residential Validation AVM v2 v2

Validación estricta del modelo residential experimental. No modifica `/predict`, Laravel ni `app/model/modelo_precio.joblib`.

## Dataset

{
  "n": 188,
  "property_type": {
    "casa": 118,
    "departamento": 70
  },
  "municipality": {
    "Cuernavaca": 44,
    "Yautepec": 31,
    "Atlatlahucan": 24,
    "Cuautla": 20,
    "Jiutepec": 19,
    "Temixco": 18,
    "Emiliano Zapata": 15,
    "Xochitepec": 12,
    "Ayala": 5
  },
  "price_band": {
    "1M-2M": 50,
    "2M-3M": 39,
    "5M-8M": 34,
    "3M-5M": 27,
    "8M-12M": 19,
    "<1M": 10,
    "12M-20M": 5,
    ">20M": 4
  },
  "source": {
    "mercadolibre": 183,
    "icasas": 5
  }
}

## Reference CV

{
  "label": "REFERENCE_CV",
  "n": 188,
  "mae": 1460673.4719746816,
  "median_ae": 642304.8758198156,
  "rmse": 3091570.094636622,
  "r2": 0.7037420628675256,
  "mape": 28.790510871934774,
  "median_absolute_percentage_error": 22.411241904467268,
  "within_10_pct": 25.53191489361702,
  "within_20_pct": 44.680851063829785,
  "within_30_pct": 63.829787234042556,
  "bias_mean": -246854.87492822704,
  "bias_median": 592.3336895947577,
  "median_prediction_ratio": 1.0035923397879933
}

## Decisión

{
  "classification": "B_GENERALIZA_PARCIALMENTE",
  "reason": "Mantiene señal global, pero las validaciones por municipio/precio muestran debilidad en extremos y municipios pequeños.",
  "mae_seed_std": 375149.00542709907,
  "weak_municipalities": 4,
  "extreme_price_band_risk": true
}
