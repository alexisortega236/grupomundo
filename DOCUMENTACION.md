# Documentacion del proyecto

## Proyecto

Nombre: Grupo Mundo Patrimonial

Ruta local:

```bash
/Users/alexisortega/Desktop/GM/grupo-mundo-patrimonial
```

Aplicacion web desarrollada en Laravel para administrar y publicar propiedades inmobiliarias. El proyecto incluye sitio publico, catalogo dinamico, detalle de propiedad, formularios de contacto, autenticacion y panel administrativo.

## Stack tecnico

- Laravel 13
- PHP 8.3+
- Blade
- Tailwind CSS
- Vite
- Alpine.js para interacciones sencillas
- Laravel Breeze para autenticacion
- Eloquent ORM
- Storage publico de Laravel para imagenes
- SQLite local por defecto en el entorno actual
- MySQL preparado mediante `.env`

## Referencia visual

El diseno se inspiro en `referencia-diseno.pdf`, ubicado en la carpeta superior del proyecto:

```bash
/Users/alexisortega/Desktop/GM/referencia-diseno.pdf
```

Lineamientos aplicados:

- Header blanco minimalista.
- Logo/nombre a la izquierda.
- Menu principal con Propiedades, Servicios, Nosotros y Contacto.
- Boton redondeado "Hablar con un asesor".
- Hero azul marino.
- Buscador dentro del hero.
- Ilustracion inmobiliaria al lado derecho.
- Tarjetas con bordes redondeados, imagen superior, capsula de precio y sombra suave.
- Colores principales: azul marino, dorado, blanco, beige claro y grises suaves.
- Interfaz completamente en espanol.

## Variables de entorno

Variables agregadas o relevantes:

```dotenv
APP_NAME="Grupo Mundo Patrimonial"
APP_LOCALE=es
APP_FALLBACK_LOCALE=es

WHATSAPP_NUMBER=5210000000000
CONTACT_EMAIL=contacto@grupomundopatrimonial.mx
```

Estas variables se leen desde `config/company.php`. No se llama `env()` directamente desde vistas o controladores.

Para MySQL:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=grupo_mundo_patrimonial
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password
```

## Comandos principales

Instalacion:

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
```

Base de datos y datos demo:

```bash
php artisan migrate --seed
```

Storage publico:

```bash
php artisan storage:link
```

Compilacion de assets:

```bash
npm run build
```

Servidor local:

```bash
php artisan serve
```

Pruebas:

```bash
php artisan test
```

## Credenciales de desarrollo

Usuario administrador sembrado por `DatabaseSeeder`:

- Nombre: `Administrador`
- Correo: `admin@grupomundopatrimonial.test`
- Contrasena: `password`
- Rol: `admin`

Estas credenciales son solo para desarrollo. Deben cambiarse antes de produccion.

## Rutas publicas

| Metodo | Ruta | Nombre | Funcion |
| --- | --- | --- | --- |
| GET | `/` | `home` | Inicio con hero, buscador, propiedades destacadas y servicios |
| GET | `/propiedades` | `properties.index` | Catalogo publico con filtros y paginacion |
| GET | `/propiedades/{property:slug}` | `properties.show` | Detalle publico de propiedad publicada |
| GET | `/servicios` | `services` | Pagina de servicios |
| GET | `/nosotros` | `about` | Pagina institucional |
| GET | `/contacto` | `contact` | Formulario general de contacto |
| POST | `/solicitudes` | `contact.requests.store` | Guarda solicitudes de contacto |

## Rutas del panel

Todas las rutas del panel usan autenticacion y middleware `admin.access`.

| Ruta base | Funcion |
| --- | --- |
| `/admin` | Dashboard |
| `/admin/properties` | CRUD de propiedades |
| `/admin/amenities` | CRUD de amenidades |
| `/admin/contact-requests` | Gestion de solicitudes |
| `/admin/users` | Gestion de usuarios, solo admin |

Acciones especiales de propiedades:

- Publicar/despublicar: `admin.properties.toggle-published`
- Archivar: `admin.properties.archive`
- Restaurar: `admin.properties.restore`
- Eliminacion definitiva: `admin.properties.force-delete`, solo admin

## Estructura principal

```text
app/
  Enums/
  Http/
    Controllers/
      Admin/
      Site/
    Middleware/
    Requests/
      Admin/
      Site/
  Models/
  Services/
config/
database/
  migrations/
  seeders/
resources/
  css/
  js/
  views/
    admin/
    auth/
    components/
    public/
tests/
  Feature/
```

## Modelos

### User

Archivo: `app/Models/User.php`

Campos principales:

- `name`
- `email`
- `password`
- `role`

Roles:

- `admin`
- `editor`

Relaciones:

- `hasMany(Property::class, created_by)`

Metodos:

- `isAdmin()`
- `canAccessAdmin()`

### Property

Archivo: `app/Models/Property.php`

Representa una propiedad inmobiliaria.

Relaciones:

- `belongsTo(User::class, created_by)` como `creator`
- `hasMany(PropertyImage::class)` como `images`
- `belongsToMany(Amenity::class)` como `amenities`
- `hasMany(ContactRequest::class)` como `contactRequests`

Route model binding:

- Usa `slug` como llave publica.

Scopes:

- `published()`
- `featured()`
- `sale()`
- `rent()`

Accessors:

- `cover_url`
- `location_label`

### PropertyImage

Archivo: `app/Models/PropertyImage.php`

Guarda imagenes asociadas a propiedades.

Campos:

- `property_id`
- `path`
- `alt_text`
- `position`
- `is_cover`

### Amenity

Archivo: `app/Models/Amenity.php`

Amenidades asignables a muchas propiedades.

Relacion:

- `belongsToMany(Property::class)`

### ContactRequest

Archivo: `app/Models/ContactRequest.php`

Solicitudes enviadas desde formularios publicos.

Relacion:

- `belongsTo(Property::class)`, opcional

## Enums

Archivos:

- `app/Enums/OperationType.php`
- `app/Enums/PropertyStatus.php`
- `app/Enums/ContactRequestStatus.php`

Valores de operacion:

- `sale`: Venta
- `rent`: Renta

Estados de propiedad:

- `draft`: Borrador
- `published`: Publicada
- `sold`: Vendida
- `rented`: Rentada
- `archived`: Archivada

Estados de solicitud:

- `new`: Nueva
- `contacted`: Contactada
- `closed`: Cerrada

## Base de datos

Tablas principales:

- `users`
- `properties`
- `property_images`
- `amenities`
- `amenity_property`
- `contact_requests`

La tabla `properties` incluye indices para filtros frecuentes:

- `operation_type`
- `property_type`
- `state`
- `city`
- `neighborhood`
- `price`
- `bedrooms`
- `bathrooms`
- `status`
- `is_featured`
- `published_at`

Las propiedades usan soft deletes.

## Sitio publico

### Inicio

Vista:

```text
resources/views/public/home.blade.php
```

Incluye:

- Header publico
- Hero azul marino
- Texto principal
- Buscador de propiedades
- Ilustracion inmobiliaria
- Propiedades destacadas
- Filtros rapidos
- Servicios
- Banner de llamada a la accion

### Catalogo de propiedades

Vista:

```text
resources/views/public/properties/index.blade.php
```

Filtros soportados:

- Operacion
- Tipo de propiedad
- Estado
- Ciudad
- Zona o colonia
- Precio minimo
- Precio maximo
- Recamaras
- Banos
- Palabra clave

Ordenamiento:

- Mas recientes
- Precio menor a mayor
- Precio mayor a menor
- Destacadas

### Detalle de propiedad

Vista:

```text
resources/views/public/properties/show.blade.php
```

Incluye:

- Galeria de imagenes
- Precio
- Ubicacion
- Caracteristicas
- Amenidades
- Espacio preparado para mapa
- Boton de WhatsApp con mensaje automatico
- Formulario para solicitar informacion
- Propiedades relacionadas

## Panel administrativo

Layout:

```text
resources/views/components/admin-layout.blade.php
```

### Dashboard

Archivo:

```text
app/Http/Controllers/Admin/DashboardController.php
resources/views/admin/dashboard.blade.php
```

Muestra:

- Total de propiedades
- Publicadas
- Borradores
- En venta
- En renta
- Vendidas
- Rentadas
- Solicitudes nuevas
- Ultimas propiedades
- Ultimas solicitudes

### Propiedades

Controlador:

```text
app/Http/Controllers/Admin/PropertyController.php
```

Vistas:

```text
resources/views/admin/properties/index.blade.php
resources/views/admin/properties/form.blade.php
resources/views/admin/properties/show.blade.php
```

Funcionalidad:

- Listar
- Crear
- Ver
- Editar
- Publicar/despublicar
- Archivar
- Soft delete
- Restaurar
- Eliminar definitivamente como admin
- Subir multiples imagenes
- Elegir portada
- Editar texto alternativo
- Editar orden
- Eliminar imagenes marcadas

### Amenidades

Controlador:

```text
app/Http/Controllers/Admin/AmenityController.php
```

Vistas:

```text
resources/views/admin/amenities/index.blade.php
resources/views/admin/amenities/form.blade.php
```

### Solicitudes

Controlador:

```text
app/Http/Controllers/Admin/ContactRequestController.php
```

Vistas:

```text
resources/views/admin/contact-requests/index.blade.php
resources/views/admin/contact-requests/show.blade.php
```

Funcionalidad:

- Listar
- Filtrar por estado
- Ver detalle
- Cambiar estado
- Eliminar
- Abrir WhatsApp para responder
- Ver propiedad relacionada cuando exista

### Usuarios

Controlador:

```text
app/Http/Controllers/Admin/UserController.php
```

Vistas:

```text
resources/views/admin/users/index.blade.php
resources/views/admin/users/form.blade.php
```

Solo usuarios con rol `admin` pueden administrar usuarios.

## Imagenes de propiedades

Servicio:

```text
app/Services/PropertyImageService.php
```

Ruta de almacenamiento:

```text
storage/app/public/properties/{property_id}
```

URL publica mediante:

```bash
php artisan storage:link
```

Formatos permitidos:

- jpg
- jpeg
- png
- webp

Tamano maximo:

- 5 MB por imagen

Las imagenes eliminadas desde el panel se eliminan fisicamente del disco.

## Validaciones

Form Requests:

```text
app/Http/Requests/Admin/StorePropertyRequest.php
app/Http/Requests/Admin/UpdatePropertyRequest.php
app/Http/Requests/Admin/StoreAmenityRequest.php
app/Http/Requests/Site/StoreContactRequest.php
```

Las propiedades requieren:

- Titulo
- Operacion
- Tipo
- Precio
- Colonia
- Ciudad
- Estado
- Descripcion
- Estado de publicacion

## Seguridad

Medidas implementadas:

- Laravel Breeze para autenticacion.
- Middleware `admin.access`.
- Gate `admin-only` para gestion de usuarios.
- CSRF en formularios.
- Rate limiting en solicitudes publicas.
- Honeypot `website` en formularios de contacto.
- Validaciones del servidor.
- Soft deletes para propiedades.

## Componentes Blade

Componentes principales:

- `x-public-layout`
- `x-admin-layout`
- `x-header`
- `x-footer`
- `x-property-card`
- `x-service-card`
- `x-alert`
- `x-empty-state`

Componentes de Breeze conservados:

- `x-input-label`
- `x-text-input`
- `x-input-error`
- `x-primary-button`
- `x-dropdown`
- `x-modal`

## Datos demo

Seeder:

```text
database/seeders/DatabaseSeeder.php
```

Incluye propiedades como:

- Departamento moderno en Del Valle
- Departamento premium en Xoco
- Casa residencial en Coyoacan
- Oficina corporativa cerca de Mitikah
- Departamento nuevo en Narvarte
- Casa remodelada en Portales

Tambien crea amenidades base:

- Alberca
- Seguridad 24 horas
- Elevador
- Gimnasio
- Terraza
- Jardin
- Balcon
- Cocina equipada
- Area de lavado
- Pet friendly
- Bodega
- Roof garden

## Pruebas

Archivos principales:

```text
tests/Feature/PublicSiteTest.php
tests/Feature/AdminPropertyTest.php
```

Cobertura incluida:

- La pagina principal carga.
- El catalogo publico carga.
- Una propiedad publicada puede verse.
- Una propiedad en borrador no puede verse publicamente.
- Los filtros funcionan.
- Un visitante puede enviar una solicitud valida.
- Un visitante no puede entrar al panel.
- Un usuario autorizado puede entrar al panel.
- Un administrador puede crear una propiedad.
- Un administrador puede editar una propiedad.
- Un administrador puede eliminar una propiedad.
- Las validaciones rechazan informacion incompleta.

Resultado verificado:

```text
31 pruebas pasadas
85 assertions
```

## Verificacion ejecutada

Comandos ejecutados al cierre:

```bash
php artisan route:list
php artisan migrate:status
php artisan test
npm run build
```

Resultados:

- `route:list`: 57 rutas registradas.
- `migrate:status`: todas las migraciones ejecutadas.
- `php artisan test`: 31 pruebas pasadas.
- `npm run build`: compilacion completada.

Nota: Vite mostro una advertencia porque el entorno usa Node `22.11.0`. Vite recomienda Node `20.19+` o `22.12+`. La compilacion termino correctamente.

## Consideraciones para produccion

Antes de publicar:

- Cambiar credenciales de desarrollo.
- Configurar MySQL real.
- Configurar `APP_ENV=production`.
- Configurar `APP_DEBUG=false`.
- Ejecutar `php artisan key:generate` con una clave propia.
- Usar HTTPS.
- Configurar backups de base de datos.
- Configurar storage persistente.
- Revisar permisos de escritura de `storage` y `bootstrap/cache`.
- Configurar correo real si se agregan notificaciones.

## Estado actual

El proyecto quedo funcional localmente con:

- Sitio publico navegable.
- Panel administrativo operativo.
- Base de datos migrada y sembrada.
- Usuario administrador creado.
- Assets compilados.
- Pruebas automatizadas pasando.

