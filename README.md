# Grupo Mundo Patrimonial

Proyecto web Laravel para una inmobiliaria patrimonial con sitio publico, catalogo dinamico, solicitudes de contacto y panel administrativo.

## Requisitos

- PHP 8.3 o superior
- Composer 2
- Node.js compatible con Vite 6
- MySQL 8 recomendado para produccion
- Extension PHP para la base de datos elegida

## Instalacion

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
```

## Configuracion de entorno

En `.env` configura:

```env
APP_NAME="Grupo Mundo Patrimonial"
APP_LOCALE=es
APP_FALLBACK_LOCALE=es

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=grupo_mundo_patrimonial
DB_USERNAME=root
DB_PASSWORD=

WHATSAPP_NUMBER=5210000000000
CONTACT_EMAIL=contacto@grupomundopatrimonial.mx
```

Para desarrollo local rapido puede usarse SQLite cambiando `DB_CONNECTION=sqlite` y creando `database/database.sqlite`.

## Base de datos, seeders y storage

```bash
php artisan migrate --seed
php artisan storage:link
```

Los seeders crean amenidades, 12 propiedades de demostracion y un usuario administrador.

Credenciales exclusivamente para desarrollo:

- Correo: `admin@grupomundopatrimonial.test`
- Contrasena: `password`

Cambia estas credenciales antes de publicar el sistema.

## Assets y servidor local

```bash
npm run build
php artisan serve
```

Durante desarrollo:

```bash
npm run dev
```

## GitHub Pages

GitHub Pages no ejecuta PHP, por lo que el sitio publico se exporta como HTML estatico en `docs/`.

```bash
STATIC_BASE_PATH=/grupomundo bash scripts/export-static.sh
```

En GitHub configura Pages con:

- Source: `Deploy from a branch`
- Branch: `main`
- Folder: `/docs`

URL esperada:

`https://alexisortega236.github.io/grupomundo/`

## Pruebas

```bash
php artisan test
```

Las pruebas Feature cubren carga de inicio, catalogo, detalle publicado, bloqueo de borradores, filtros, solicitudes de contacto, acceso al panel y CRUD principal de propiedades.

## Estructura

- `app/Http/Controllers/Public`: sitio publico.
- `app/Http/Controllers/Admin`: panel administrativo.
- `app/Http/Requests`: validaciones de formularios.
- `app/Enums`: estados y tipos controlados.
- `app/Services/PropertyImageService.php`: administracion de imagenes.
- `resources/views/public`: vistas del sitio publico.
- `resources/views/admin`: vistas del panel.
- `resources/views/components`: componentes Blade reutilizables.

## Produccion

- Configura MySQL real y credenciales seguras.
- Cambia el usuario administrador inicial.
- Configura `APP_URL`, correo transaccional y backups.
- Usa `APP_DEBUG=false`.
- Ejecuta `php artisan optimize` despues del despliegue.
- Revisa permisos de `storage` y `bootstrap/cache`.

## Render

El repositorio incluye `Dockerfile` para Render. En el servicio selecciona `Docker` como runtime y deja que Render use el `Dockerfile` de la raiz.

Variables recomendadas:

```env
APP_NAME=Grupo Mundo Patrimonial
APP_ENV=production
APP_DEBUG=false
APP_URL=https://grupomundo.onrender.com
WHATSAPP_NUMBER=5210000000000
CONTACT_EMAIL=contacto@grupomundopatrimonial.mx
RUN_SEEDERS=true
```

Para una prueba rapida puede dejar SQLite, que es el valor predeterminado del contenedor. Para produccion usa una base externa y configura `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`.
