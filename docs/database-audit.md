# Auditoría técnica de base de datos

Fecha de auditoría: 2026-08-28

## 1. Resumen ejecutivo

La aplicación está preparada para SQLite, MySQL, MariaDB, PostgreSQL y SQL Server. El entorno local materializado utiliza SQLite:

- 27 tablas físicas, incluyendo `migrations`.
- 18 tablas de dominio.
- 9 tablas de infraestructura/framework.
- 20 migraciones registradas.
- 33 properties locales: 13 comerciales publicadas, 1 comercial en draft y 19 técnicas de valuación.
- 19 valuaciones: 4 completadas y 15 fallidas.
- 19 `valuation_features`.
- 18 `valuation_model_predictions`.
- 3,404 asentamientos postales.

La separación comercial/técnica se realiza mediante `properties.origin`, con valores `commercial` y `valuation`. La relación `valuations.property_id` mantiene `ON DELETE CASCADE`, por lo que un borrado físico de una property puede destruir sus valuaciones relacionadas.

No se modificaron datos, migraciones ni código durante esta auditoría.

## 2. Motor y configuración

Archivo principal: `config/database.php`.

La conexión por defecto es:

```php
'default' => env('DB_CONNECTION', 'sqlite')
```

Conexiones configuradas:

- SQLite
- MySQL
- MariaDB
- PostgreSQL
- SQL Server

Testing utiliza SQLite en memoria, según `phpunit.xml`:

```text
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
```

El `Dockerfile` de Render fija SQLite dentro del contenedor:

```text
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

`.env.example` contiene valores orientados a MySQL, pero no representa necesariamente la configuración efectiva de producción.

No se documentaron secretos, passwords, tokens ni `APP_KEY`.

## 3. Inventario de migraciones

Total: 20 migraciones.

### Framework y autenticación

- `0001_01_01_000000_create_users_table.php`: crea `users`, `password_reset_tokens` y `sessions`.
- `0001_01_01_000001_create_cache_table.php`: crea `cache` y `cache_locks`.
- `0001_01_01_000002_create_jobs_table.php`: crea `jobs`, `job_batches` y `failed_jobs`.

### Propiedades e imágenes

- `2026_08_03_195629_create_properties_table.php`: crea `properties`, índices comerciales, FK `created_by → users SET NULL` y soft deletes.
- `2026_08_03_195630_create_property_images_table.php`: crea imágenes con FK `property_id → properties CASCADE`.
- `2026_08_03_234125_add_optimized_versions_to_property_images_table.php`: agrega `card_path`, `thumb_path`, nombre original, tamaño y dimensiones.
- `2026_08_14_000001_add_show_exact_location_to_properties_table.php`: agrega `show_exact_location`.
- `2026_08_14_010000_add_avm_fields_to_properties_table.php`: agrega UUID, usuario AVM, localidad, municipio, superficies AVM, antigüedad AVM e índice de coordenadas.
- `2026_08_17_000000_add_avm_legacy_colonia_to_properties_table.php`: agrega `avm_colonia` indexado.
- `2026_08_18_000001_add_location_provenance_to_properties_table.php`: agrega `location_source` y `location_precision`.
- `2026_08_27_000000_add_origin_to_properties_table.php`: agrega `origin`, default `commercial`, indexado, y clasifica históricas técnicas sólo con relación a `valuations` más una señal técnica inequívoca.
- `2026_08_27_010000_add_original_path_to_property_images_table.php`: agrega `original_path`.

### Amenidades y contacto

- `2026_08_03_195631_create_amenities_table.php`: crea `amenities` y pivot `amenity_property`, ambos con constraints e índices únicos apropiados.
- `2026_08_03_195632_create_contact_requests_table.php`: crea solicitudes de contacto; `property_id` usa `ON DELETE SET NULL`.

### AVM y datos territoriales

- `2026_08_14_010001_create_avm_reference_tables.php`: crea `data_sources`, `model_versions`, `locations`, `pois`, `socioeconomic_zones` y `market_indices`.
- `2026_08_14_010002_create_valuation_tables.php`: crea `valuations`, `valuation_features`, `comparables`, `valuation_comparables` e `import_runs`.
- `2026_08_17_000001_add_legacy_avm_response_to_valuations_table.php`: agrega moneda, zona inferida y respuesta JSON.
- `2026_08_17_000002_add_source_to_valuations_table.php`: agrega `source`, default `admin`, indexado.
- `2026_08_18_000000_create_valuation_model_predictions_table.php`: crea predicciones por modelo.
- `2026_08_19_000000_create_postal_settlements_table.php`: crea asentamientos postales SEPOMEX con identidad única compuesta.

## 4. Tablas por dominio

### Dominio inmobiliario

`properties`, `property_images`, `amenities`, `amenity_property`, `contact_requests`.

### Valuaciones y AVM persistido en Laravel

`valuations`, `valuation_features`, `valuation_model_predictions`, `comparables`, `valuation_comparables`, `model_versions`, `data_sources`, `import_runs`.

### Ubicación y contexto territorial

`locations`, `postal_settlements`, `pois`, `socioeconomic_zones`, `market_indices`.

### Framework

`users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`.

## 5. Diccionario de datos

Abreviaturas: `NN` = NOT NULL, `NULL` = nullable, `PK` = primary key, `UQ` = unique, `IDX` = index, `DEF` = default.

### `properties`

| Columna | Tipo / regla | Función |
|---|---|---|
| `id` | integer NN PK | Identidad interna |
| `uuid` | varchar NULL UQ | Identidad UUID AVM |
| `user_id` | integer NULL FK SET NULL | Usuario de valuación |
| `title`, `slug` | varchar NN; slug UQ | Título y URL |
| `short_description` | text NULL | Resumen |
| `description` | text NN | Descripción |
| `operation_type` | varchar NN IDX | Venta, renta, venta-renta o preventa |
| `property_type` | varchar NN IDX | Tipo de propiedad |
| `origin` | varchar NN DEF `commercial` IDX | `commercial` o `valuation` |
| `price` | numeric(14,2) NN IDX | Precio |
| `currency` | varchar(3) NN DEF `MXN` | Moneda |
| `rent_period` | varchar NULL | Periodicidad de renta |
| `street`, `exterior_number`, `interior_number` | varchar NULL | Dirección |
| `neighborhood`, `city`, `state` | varchar NN IDX | Ubicación textual |
| `locality`, `municipality`, `postal_code` | varchar NULL; municipality IDX | Ubicación AVM/CP |
| `bedrooms` | unsigned tinyint NULL IDX | Recámaras |
| `bathrooms` | decimal(4,1) NULL IDX | Baños |
| `parking_spaces` | unsigned tinyint NULL | Estacionamientos |
| `construction_area`, `land_area` | decimal(10,2) NULL | Superficies comerciales |
| `construction_area_m2`, `land_area_m2` | decimal(10,2) NULL | Superficies AVM |
| `age` | varchar NULL | Antigüedad textual |
| `property_age_years` | unsigned smallint NULL | Antigüedad AVM |
| `latitude`, `longitude` | decimal(10,7) NULL; índice compuesto | Coordenadas |
| `location_source`, `location_precision` | varchar NULL | Provenance y precisión |
| `avm_colonia` | varchar NULL IDX | Colonia legacy AVM |
| `show_exact_location` | boolean NN DEF false | Exposición de ubicación |
| `status` | varchar NN DEF `draft` IDX | Estado comercial |
| `is_featured` | boolean NN DEF false IDX | Destacada |
| `published_at` | datetime NULL IDX | Publicación |
| `created_by` | integer NULL FK SET NULL | Usuario creador |
| `created_at`, `updated_at`, `deleted_at` | timestamps; deleted_at soft delete | Ciclo de vida |

### `property_images`

`id` PK, `property_id` NN FK CASCADE, `path` NN, `original_path`, `card_path`, `thumb_path`, `alt_text`, `original_filename`, `size_kb`, `width`, `height` nullable, `position` NN default 0, `is_cover` NN default false, timestamps.

### `amenities` y `amenity_property`

`amenities`: `id` PK, `name` UQ, `slug` UQ, timestamps.

`amenity_property`: `property_id` y `amenity_id`, PK compuesta, ambas FKs CASCADE.

### `contact_requests`

`id` PK, `property_id` nullable FK SET NULL, `name` NN, `phone` NN, `email` nullable, `message` NN, `status` NN default `new` indexado, timestamps.

### `data_sources`

`id` PK, `name`, `type` NN, `provider`, `description`, `base_url`, `update_frequency` nullable, `is_active` default true indexado, timestamps.

### `model_versions`

`id` PK, `name`, `version` NN con unique compuesto, `algorithm`, fechas de entrenamiento, `training_rows`, métricas MAE/MAPE/RMSE/R², `artifact_path`, `features_json`, `status` default `draft` indexado, `notes`, timestamps.

### `locations`

`id` PK, códigos y nombres de estado/municipio/localidad, CP indexado, colonia, AGEB indexado, latitud, longitud, geometría y timestamps. No tiene FK hacia properties.

### `pois`

`id` PK, `source`, `external_id`, `name`, `category` indexada, `subcategory`, coordenadas obligatorias con índice compuesto, geometría, estado, municipio, metadata, `last_synced_at`, timestamps.

### `socioeconomic_zones`

`id` PK, códigos de estado/municipio/localidad, `ageb_code` NN indexado, geometría, población, hogares, densidades, ratios, índices socioeconómicos, `raw_data`, `data_year`, timestamps.

### `market_indices`

`id` PK, `source_id` nullable FK SET NULL, nivel geográfico, estado, municipio, tipo, periodo, año, trimestre, índice y variaciones, timestamps. Índice `(state, municipality, period)`.

### `import_runs`

`id` PK, `source_id` NN FK CASCADE, fechas de inicio/fin, `status` indexado, contadores de registros, error, metadata y timestamps.

### `valuations`

`id` PK, `uuid` UQ, `source` default `admin` indexado, `property_id` NN FK CASCADE, `model_version_id` FK SET NULL, valores estimados y límites, confianza, contador de comparables, status default `pending` indexado, fecha, errores, moneda, zona, `avm_response_json`, timestamps.

### `valuation_features`

`id` PK, `valuation_id` NN UQ FK CASCADE, ratio construcción/terreno, densidades, scores territoriales, distancias a POIs, accesibilidad, estadísticas de precios, tendencias, `features_json`, timestamps.

### `comparables`

`id` PK, `uuid` UQ, `external_id`, `source_id` FK SET NULL, tipo indexado, coordenadas obligatorias con índice compuesto, ubicación, superficies, características, antigüedad, precio y precio/m², fechas, estado indexado, URL, `raw_data`, timestamps.

### `valuation_comparables`

`id` PK, `valuation_id` FK CASCADE, `comparable_id` FK CASCADE, distancia, similarity score, peso, precios ajustados, ajustes JSON, timestamps. Unique `(valuation_id, comparable_id)`.

### `valuation_model_predictions`

`id` PK, `valuation_id` FK CASCADE, modelo, versión, estado indexado, elegibilidad, valor, rango, confianza, request/response JSON, error, tiempo de ejecución, timestamps. Índice `(valuation_id, model_name)`.

### `postal_settlements`

`id` PK, estado, municipio, asentamiento, tipo, CP, localidad, zona, fuente default `sepomex`, timestamps. Índices territoriales y unique `(source, state_code, municipality_code, settlement, postal_code)`.

## 6. Modelos Eloquent y relaciones

### `Property`

Tabla `properties`. Usa `HasFactory` y `SoftDeletes`, `$guarded=[]`, default `origin=commercial`, validación de origin en `saving` y origin inmutable en `updating`.

Casts: `OperationType`, `PropertyStatus`, decimales, booleanos y fechas.

Scopes: `commercial`, `valuationOrigin`, `publishable`, `published`, `featured`, `sale`, `rent`.

Relaciones:

- `creator()` → `User` por `created_by`.
- `user()` → `User` por `user_id`.
- `valuations()` → `hasMany`.
- `images()` y `coverImage()` → `hasMany`.
- `amenities()` → `belongsToMany`.
- `contactRequests()` → `hasMany`.

### `Valuation`

Tabla `valuations`. `property()` es `belongsTo` con `withTrashed()`. También tiene `modelVersion()`, `features()`, `valuationComparables()` y `modelPredictions()`.

### Otros modelos

- `ValuationFeature`: `belongsTo Valuation`.
- `ValuationModelPrediction`: `belongsTo Valuation`.
- `Comparable`: `belongsTo DataSource` y `hasMany ValuationComparable`.
- `ValuationComparable`: pertenece a `Valuation` y `Comparable`.
- `DataSource`: `hasMany ImportRun`.
- `ModelVersion`: `hasMany Valuation`.
- `ImportRun`: `belongsTo DataSource`.
- `MarketIndex`: `belongsTo DataSource`.
- `Amenity`: `belongsToMany Property`.
- `PropertyImage`: `belongsTo Property`.
- `ContactRequest`: `belongsTo Property`.
- `User`: `hasMany Property` por `created_by` y por `user_id`.
- `Location`, `Poi`, `PostalSettlement` y `SocioeconomicZone`: no declaran relaciones relevantes.

## 7. ERD textual

```text
users
  1 ─── N properties.created_by
  1 ─── N properties.user_id

properties
  1 ─── N property_images
  1 ─── N valuations
  1 ─── N contact_requests
  N ─── N amenities mediante amenity_property

valuations
  1 ─── 1 valuation_features
  1 ─── N valuation_model_predictions
  1 ─── N valuation_comparables

model_versions
  1 ─── N valuations

data_sources
  1 ─── N import_runs
  1 ─── N comparables
  1 ─── N market_indices

comparables
  1 ─── N valuation_comparables
```

Relaciones físicas sin relación Eloquent equivalente completa:

- `DataSource` no declara `comparables()` ni `marketIndices()`.
- `sessions.user_id` no tiene FK física.
- Las tablas geográficas no tienen FKs hacia properties.

## 8. Valuaciones y AVM

El flujo real es:

```text
Formulario público/admin
        ↓
ValuationService::createAndRun()
        ↓
Property(origin=valuation)
        ↓
Valuation pending
        ↓
ValuationFeature inicial
        ↓
LocationEnrichmentService
        ↓
ComparableService
        ↓
FeatureBuilder
        ↓
Selección de modelo AVM
        ↓
Persistencia de resultado y features
        ↓
Predicciones por modelo/shadow
        ↓
completed o failed
```

Los resultados principales se guardan en `valuations`. Las predicciones individuales se guardan en `valuation_model_predictions`. Las features y metadata se guardan en `valuation_features`.

En el código inspeccionado, `ComparableService::findForProperty()` retorna una colección vacía. Por tanto, aunque existen las tablas de comparables, no debe asumirse que el servicio Laravel actual está persistiendo comparables efectivos.

Los artefactos y datasets Python se encuentran bajo `services/avm/**`; no fueron modificados.

## 9. Integridad referencial

### Cascades

```text
properties → property_images
properties → amenity_property
properties → valuations
valuations → valuation_features
valuations → valuation_comparables
valuations → valuation_model_predictions
comparables → valuation_comparables
data_sources → import_runs
```

### SET NULL

```text
properties.created_by → users
properties.user_id → users
contact_requests.property_id → properties
valuations.model_version_id → model_versions
comparables.source_id → data_sources
market_indices.source_id → data_sources
```

### Protecciones de aplicación

`PropertyPolicy` bloquea update, publicación, delete, restore y force delete para properties de origin `valuation`. También impide force delete si existen valuaciones.

El cascade de la base sigue siendo destructivo ante SQL directo.

## 10. Ciclos de vida

### Property comercial

```text
Admin create
  ↓
Property(origin=commercial)
  ↓
draft o published
  ↓
amenidades e imágenes
  ↓
sitio público mediante Property::published()
  ↓
soft delete / restore
  ↓
force delete protegido
```

### Valuación

```text
Formulario
  ↓
Property técnica
  ↓
Valuation
  ↓
Features
  ↓
AVM
  ↓
Resultado/predicciones
  ↓
Persistencia
  ↓
Presentación pública/admin
```

## 11. Datos locales observados

```text
properties:
commercial / draft      1
commercial / published 13
valuation / draft      19

valuations:
admin  / completed 2
public / completed 2
public / failed    15

valuation_model_predictions:
avm_residential_v2 / completed 15
avm_residential_v2 / failed 1
avm_residential_v2 / ineligible 1
avm_v2_v1          / completed 1
```

## 12. Observaciones de diseño

Estas observaciones no representan cambios implementados:

- `properties` contiene pares de campos similares: `land_area`/`land_area_m2`, `construction_area`/`construction_area_m2`, `age`/`property_age_years` y `city`/`locality`/`municipality`.
- La ubicación se almacena principalmente como texto y coordenadas; las tablas geográficas no tienen integridad referencial hacia properties.
- Existen tablas persistentes de comparables, pero el servicio actual retorna una colección vacía.
- El cascade de `properties` hacia `valuations` puede destruir historial si se hace force delete fuera de las policies.
- El `Dockerfile` configura SQLite dentro del contenedor; la persistencia depende de la configuración del entorno de despliegue.
- No existen checks SQL para valores válidos de `origin`, `status`, `operation_type` o `property_type`.
- Los índices son principalmente individuales; no se observan índices compuestos específicos para combinaciones como `origin + status + published_at`.

## 13. Conteos finales

- Migraciones: **20**.
- Tablas físicas incluyendo `migrations`: **27**.
- Tablas de dominio: **18**.
- Tablas de framework/autenticación: **9**.

## 14. Archivos inspeccionados

- `config/database.php`
- `config/filesystems.php`
- `config/session.php`
- `config/cache.php`
- `config/queue.php`
- `.env.example`
- `Dockerfile`
- `phpunit.xml`
- Todos los archivos bajo `database/migrations/**`
- Todos los archivos bajo `app/Models/**`
- `app/Services/Valuation/ValuationService.php`
- `app/Services/Valuation/ComparableService.php`
- `app/Services/Valuation/FeatureBuilder.php`
- `app/Services/Valuation/LocationEnrichmentService.php`
- `app/Http/Controllers/Public/ValuationController.php`
- `app/Http/Controllers/Admin/ValuationController.php`
- `app/Http/Controllers/Admin/PropertyController.php`
- `app/Policies/PropertyPolicy.php`
- `app/Services/PropertyImageService.php`
- Seeders y factories bajo `database/seeders/**` y `database/factories/**`

## 15. Comandos utilizados

```text
rg --files database/migrations
rg --files app/Models
rg sobre migraciones, modelos, servicios y controladores
sqlite3 database/database.sqlite '.tables'
sqlite_master para obtener DDL
pragma table_info(...)
pragma index_list(...)
consultas read-only de conteos, status, source y origin
git status --short --branch
```

No se ejecutaron migraciones, seeders, updates, deletes, commits ni pushes.
