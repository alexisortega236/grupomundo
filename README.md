# Grupo Mundo Patrimonial

Proyecto web Laravel para una inmobiliaria patrimonial con sitio público, catálogo dinámico, detalle de propiedades, formularios de contacto, autenticación y panel administrativo.

Documentación completa del proyecto: [`DOCUMENTACION.md`](DOCUMENTACION.md).

## Requisitos

- PHP 8.3 o superior
- Composer
- Node.js y npm
- MySQL para producción o desarrollo compartido

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
```

## Configuración

```dotenv
APP_NAME="Grupo Mundo Patrimonial"
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
WHATSAPP_NUMBER=5210000000000
CONTACT_EMAIL=contacto@grupomundopatrimonial.mx
```

Para MySQL:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=grupo_mundo_patrimonial
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password
```

## Base de datos, storage y assets

```bash
php artisan migrate --seed
php artisan storage:link
npm run build
php artisan serve
```

## Pruebas

```bash
php artisan test
```

## Credenciales de desarrollo

- Correo: `admin@grupomundopatrimonial.test`
- Contraseña: `password`

Estas credenciales son exclusivamente para desarrollo y deben cambiarse antes de producción.

## Estructura

- `app/Http/Controllers/Site`: sitio público.
- `app/Http/Controllers/Admin`: panel administrativo.
- `app/Http/Requests`: validaciones con Form Requests.
- `app/Enums`: tipos de operación y estados.
- `app/Services/PropertyImageService.php`: administración de imágenes.
- `resources/views/public`: vistas públicas.
- `resources/views/admin`: vistas del panel.
- `resources/views/components`: componentes Blade reutilizables.

## Producción

Configura `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, MySQL, storage persistente y cambia las credenciales sembradas.
