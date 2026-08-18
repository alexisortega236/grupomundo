# AVM v2 Residential Shadow Mode

## Arquitectura

El endpoint legacy `POST /predict` sigue siendo el valuador público y productivo. El endpoint experimental `POST /predict/v2/residential` vive en el mismo microservicio Python, pero no reemplaza al legacy.

Laravel ejecuta AVM v2 sólo en shadow mode cuando las banderas lo permiten. El visitante sigue recibiendo el resultado legacy; la predicción v2 se guarda para análisis interno.

## Endpoint

`POST /predict/v2/residential`

Request:

```json
{
  "property_type": "house",
  "latitude": 18.8123,
  "longitude": -98.9556,
  "land_area_m2": 200,
  "construction_area_m2": 160,
  "bedrooms": 3,
  "bathrooms": 2,
  "parking_spaces": 2,
  "age_years": 8
}
```

El modelo consume `property_type`, municipio, AGEB, superficie, recámaras, baños, estacionamientos, Censo 2020 y DENUE 2026. `age_years` se acepta por compatibilidad de contrato, pero no se usa si el pipeline experimental no lo entrenó.

Response elegible:

```json
{
  "eligible": true,
  "model": "avm_residential_v2_v2",
  "model_version": "avm_residential_v2_v2_experimental",
  "segment": "residential",
  "property_type": "house",
  "estimated_value": 6250000,
  "currency": "MXN",
  "range": {
    "low": 5100000,
    "high": 7600000,
    "nominal_coverage": 0.9
  },
  "confidence": "MEDIUM",
  "location": {
    "municipality": "Cuautla",
    "locality": "Cuautla",
    "ageb": "001A"
  }
}
```

Response no elegible:

```json
{
  "eligible": false,
  "reason": "unsupported_property_type"
}
```

## Elegibilidad

Soportado:

- `house`
- `apartment`

No soportado:

- `land`
- otros tipos comerciales

Casa requiere ubicación, superficie de construcción y superficie de terreno. Departamento requiere ubicación y superficie de construcción. La ubicación debe poder resolverse a AGEB dentro del dominio validado.

## Confianza

La confianza es conservadora y categórica:

- `LOW`: datos faltantes, sin AGEB, precio estimado menor a 1M, mayor a 12M, o municipio con debilidad detectada.
- `MEDIUM`: caso residencial dentro del dominio validado con datos mínimos suficientes.
- `HIGH`: reservado para una fase posterior con validación más amplia.
- `OUT_OF_VALIDATED_DOMAIN`: reservado para casos fuera del dominio validado.

## Intervalos

El rango usa percentiles empíricos de errores previos del experimento residential. Primero intenta `property_type_pct`; si no hay muestra suficiente usa `global_pct`. No se recalibra con la propiedad actual.

## Feature Flags

Laravel:

```env
AVM_V2_ENABLED=false
AVM_V2_SHADOW_MODE=true
AVM_V2_TIMEOUT=20
```

Con `AVM_V2_ENABLED=false`, Laravel no llama v2. Con `AVM_V2_ENABLED=true` y `AVM_V2_SHADOW_MODE=true`, Laravel ejecuta legacy como siempre, luego intenta v2 y guarda el resultado en `valuation_model_predictions`.

## Fallback

El fallback siempre es legacy. Timeout, error HTTP, no elegibilidad o caída de v2 no deben afectar la valuación pública.

## Limitaciones

- Holdout externo sólo validó casas.
- Departamentos quedan como máximo en confianza `MEDIUM`.
- Land no está conectado.
- Propiedades mayores a 12M mantienen riesgo de subvaluación.
- El endpoint no debe usarse como resultado público sin una fase posterior de piloto controlado.
