# AVM v2 Experimental v1

Este experimento fue generado con datos reales de `data/avm_training_candidates.csv`.

No sustituye el modelo productivo `app/model/modelo_precio.joblib` y no modifica `/predict`.

## Resultado principal

- Registros iniciales: 168
- Registros después de deduplicación: 155
- Mejor modelo: GradientBoosting
- MAE: 2271378.96
- MedianAE: 1049960.95
- RMSE: 4782468.97
- R2: 0.2232
- MAPE: 55.93%
- Dentro de ±20%: 41.29%

## Comparación legacy

No se hizo comparación directa porque el modelo legacy requiere `COL_XX` y un contrato de features no equivalente al dataset real actual.
