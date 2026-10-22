# GrupoMundoP API v1

## Alcance

La Entrega 1 expone únicamente propiedades comerciales publicadas para consumo máquina-a-máquina por SDG.

No incluye revisiones, outbox, feed de cambios, snapshots, jobs, webhooks ni sincronización incremental.

## Autenticación

Passport utiliza OAuth 2.0 Client Credentials.

Token endpoint:

```text
POST /oauth/token
```

Solicitud `application/json` o `application/x-www-form-urlencoded`:

```json
{
  "grant_type": "client_credentials",
  "client_id": "oauth-client-uuid",
  "client_secret": "secret-no-publicar",
  "scope": "properties:read"
}
```

Las respuestas de la API requieren:

```http
Authorization: Bearer <access_token>
Accept: application/json
```

El cliente debe ser confidencial y tener únicamente el scope `properties:read`. Los secretos no se almacenan en el repositorio ni se escriben en logs.

Los tokens Client Credentials tienen una duración limitada. Revocar el cliente impide emitir nuevos tokens; los tokens ya emitidos expiran según la duración configurada por Passport.

## Endpoints

### Listado

```text
GET /api/v1/properties
```

Parámetros:

| Parámetro | Tipo | Reglas |
|---|---|---|
| `per_page` | integer | 1 a 100; default 50 |
| `cursor` | string | Cursor opaco de Laravel |
| `operation_type` | string | `sale`, `rent`, `sale_rent`, `presale` |
| `property_type` | string | Nombre del tipo, máximo 120 caracteres |
| `state` | string | Estado, máximo 120 caracteres |

La consulta aplica:

```text
origin = commercial
status = published
published_at IS NOT NULL
deleted_at IS NULL
```

El orden es ascendente por `properties.id` y utiliza cursor pagination, no offset.

Esta paginación no congela el catálogo. Si una propiedad se publica, retira o modifica entre páginas, la respuesta puede no representar una fotografía consistente. Los snapshots pertenecen a una entrega posterior.

### Detalle

```text
GET /api/v1/properties/{id}
```

`id` corresponde a `properties.id`.

Una propiedad no publicada responde `404` con el mismo formato que un recurso inexistente; la respuesta no revela si el registro existe como borrador, archivado, vendido, rentado o eliminado.

## Contrato de respuesta

```json
{
  "data": {
    "external_id": 123,
    "slug": "casa-polanco-123",
    "title": "Casa en Polanco",
    "short_description": "Casa residencial.",
    "description": "Descripción comercial.",
    "property_type": {
      "name": "Casa",
      "slug": "casa",
      "classification": "residential"
    },
    "operation_type": "sale",
    "price": "8500000.00",
    "currency": "MXN",
    "rent_period": null,
    "status": "published",
    "published_at": "2026-10-20T16:30:00+00:00",
    "location": {
      "street": "Ejemplo",
      "exterior_number": "123",
      "interior_number": null,
      "neighborhood": "Polanco",
      "locality": null,
      "municipality": "Miguel Hidalgo",
      "city": "Ciudad de México",
      "state": "Ciudad de México",
      "postal_code": "11560",
      "latitude": "19.4326000",
      "longitude": "-99.1332000",
      "show_exact_location": false
    },
    "areas": {
      "land_m2": "240.00",
      "construction_m2": "310.00"
    },
    "features": {
      "bedrooms": 3,
      "full_bathrooms": 2,
      "half_bathrooms": 1,
      "bathrooms_total": "2.50",
      "parking_spaces": 2,
      "age_years": null
    },
    "amenities": [
      {
        "external_id": 7,
        "name": "Alberca",
        "slug": "alberca"
      }
    ],
    "images": [
      {
        "external_id": 456,
        "url": "https://example.com/storage/properties/123/image-card.webp",
        "position": 1,
        "is_cover": true,
        "alt_text": "Fachada principal",
        "width": 1000,
        "height": 750
      }
    ],
    "source_created_at": "2026-08-20T18:00:00+00:00",
    "source_updated_at": "2026-10-20T16:30:00+00:00"
  },
  "meta": {
    "api_version": "v1"
  }
}
```

Los precios, superficies y coordenadas se entregan sin formato de presentación. Los campos nullable conservan `null`.

### Privacidad territorial

Cuando `location.show_exact_location` es `false`, la API devuelve `null` para `street`, `exterior_number`, `interior_number`, `latitude` y `longitude`. Se conservan únicamente los datos territoriales generales. Cuando es `true`, se devuelven los datos exactos disponibles.

## Fotografías

La API devuelve únicamente URLs generadas desde el disco público y exige el esquema `https`. No expone rutas físicas, `original_path` ni credenciales de almacenamiento.

Las fotografías sin ruta utilizable o cuya URL no sea HTTPS se omiten sin provocar la caída de la propiedad. Se conserva el orden de las fotografías válidas. Una URL HTTPS válida sintácticamente no garantiza que el archivo exista o responda correctamente; la API no realiza comprobaciones HTTP remotas durante la serialización.

Si el disco público genera URLs HTTP, la respuesta falla cerrada hasta corregir la configuración HTTPS. Esta entrega no cambia infraestructura ni valores de producción.

## Errores

```json
{
  "error": {
    "code": "not_found",
    "message": "The requested resource was not found."
  }
}
```

Códigos esperados:

| HTTP | Código |
|---:|---|
| 401 | token ausente, inválido o expirado |
| 403 | scope insuficiente |
| 404 | propiedad no visible o inexistente |
| 422 | parámetros inválidos |
| 429 | límite por cliente OAuth excedido |

## Rate limiting

El listado y detalle comparten un límite de 60 solicitudes por minuto por `oauth_client_id`. La respuesta `429` incluye el comportamiento estándar de Laravel para reintento.

Los cursores deben ser generados por esta API. Un cursor vacío, corrupto o incompatible devuelve `422`; los cursores nativos no tienen firma criptográfica y su manipulación no puede detectarse más allá de la validación estructural.

La revocación de un cliente se comprueba en cada solicitud autenticada, por lo que un cliente revocado pierde acceso aunque sus tokens no hayan expirado. Un access token individual también puede revocarse y deja de ser aceptado inmediatamente. El procedimiento operativo debe revocar el cliente y, para una contención completa, revocar todos sus access tokens activos mediante los mecanismos administrativos autorizados de Passport.

## Exclusiones explícitas

No se exponen usuarios, contactos, valuaciones, propietarios, información financiera, credenciales, tokens, borradores ni propiedades de origen `valuation`.

La API no debe considerarse lista para una importación inicial definitiva hasta que se implementen snapshots y un mecanismo de revisiones/eventos.

Antes de producción aún deben provisionarse las claves Passport, aplicar las migraciones OAuth en una ventana controlada, validar `APP_URL` HTTPS y verificar el almacenamiento público de fotografías. No se incluyen credenciales reales en esta documentación.

## Feed incremental de cambios

```text
GET /api/v1/property-changes
```

Parámetros:

| Parámetro | Tipo | Reglas |
|---|---|---|
| `after` | string | Cursor opaco cifrado; opcional en la primera lectura |
| `limit` | integer | 1 a 100; default 100 |

El feed se alimenta desde un outbox persistente. Las revisiones, el outbox y la secuencia global se escriben dentro de la misma transacción que la mutación de negocio. El `feed_sequence` se asigna bajo bloqueo de una fila de secuencia; no es un ID autoincremental ni representa por sí mismo el timestamp físico de commit.

El publicador procesa eventos en orden de secuencia y solo expone al feed el prefijo contiguo marcado como `published`. Si existe un evento confirmado pero todavía pendiente, los eventos posteriores no se exponen hasta recuperar el hueco. El cursor es cifrado con la clave de aplicación, incluye versión y expiración, y no es intercambiable con el cursor de `/properties`.

Respuesta:

```json
{
  "data": [
    {
      "event_id": "01J...",
      "event_type": "property.updated",
      "external_id": "123",
      "revision": 18,
      "committed_at": "2026-10-20T16:30:00+00:00",
      "payload": {}
    }
  ],
  "links": {"next": null},
  "meta": {"api_version": "v1", "has_more": false, "safe_sequence": 18}
}
```

Los eventos soportados son `property.published`, `property.updated`, `property.unpublished`, `property.deleted`, `property.images_changed`, `property.amenities_changed` y `property.type_changed`. Las retiradas solo contienen `external_id`, `revision`, `event_type`, `reason` y `timestamp`.

Errores específicos del cursor:

| HTTP | Código | Acción |
|---:|---|---|
| 422 | `cursor_invalid` | Corregir el cursor o iniciar una lectura nueva |
| 410 | `cursor_expired` | Ejecutar reconciliación completa |

La retención predeterminada es de 90 días y se configura con `PROPERTY_CHANGES_RETENTION_DAYS`. La limpieza solo considera eventos publicados y debe ejecutarse en una ventana autorizada mediante el comando de mantenimiento con `--force`; nunca elimina eventos pendientes. Un cursor fuera de la ventana exige reconciliación completa.

Para publicar el outbox se utiliza `property-changes:publish`. La Entrega 2 no configura todavía una programación productiva de 15 minutos ni implementa snapshots.

## Integridad referencial y ejecución del publisher

`property_revisions` y `property_change_outbox` no declaran una clave foránea
hacia `properties`. El historial debe conservar el evento de retiro y el
payload público después de un `forceDelete` del catálogo, y su retención se
administra de forma independiente; una cascada hacia el historial rompería
ese requisito.

`property_change_sequences` es una tabla de control global y no tiene una
relación referencial. Las tablas OAuth conservan el esquema de Passport 13:
`oauth_clients.owner` es polimórfico y los identificadores OAuth se validan
por Passport, por lo que no se agregan claves foráneas que puedan impedir la
revocación o purga administrada por Passport.

El comando `property-changes:publish` usa un lock distribuido de base de datos
y reintentos transaccionales limitados para deadlocks MySQL 1213/40001. El
contenedor incluye un loop opcional controlado por
`RUN_PROPERTY_CHANGES_PUBLISHER`, desactivado por defecto. No se activa la
limpieza de eventos desde el arranque.
