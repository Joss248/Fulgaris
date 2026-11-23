# Despliegue de BEPARTNERS en Render

El Dockerfile de la raíz instala las dependencias PHP de producción desde
`composer.lock` y compila Vite desde `package-lock.json`. La imagen final
sirve `public/` con Apache. Escucha en `0.0.0.0:$PORT` y usa 10000 por
defecto. El arranque se limita a Apache. El endpoint `/up` comprueba que
Laravel arranca. Para verificar sesiones, base de datos y Vite, abre
`/login` y comprueba también un recurso de `/build/assets/`.

## Base de datos

La propuesta es usar **Render Postgres en la misma región**, con una base
dedicada para la entrega académica y su URL interna. El sistema de archivos
del contenedor es efímero, así que SQLite se reserva para comprobaciones
locales desechables. Render Postgres Free expira a los 30 días y carece de
respaldos administrados. Confirma el motor, el plan y la retención antes de
crear recursos. [Conexión a Render Postgres](https://render.com/docs/postgresql-creating-connecting) · [Límites del plan gratuito](https://render.com/docs/free)

La imagen también admite MySQL y MariaDB externos. Si se elige PostgreSQL,
hay que validar en una base desechable las migraciones y las búsquedas. Las
consultas actuales usan `LIKE`, que distingue mayúsculas en PostgreSQL y
puede comportarse distinto según el motor y la colación de origen.

## Configuración al autorizar el despliegue

Selecciona el runtime **Docker**, la rama `develop`, el Dockerfile de la
raíz y `/up` como ruta de salud. Usa el comando predeterminado de la imagen.
Render termina TLS en el proxy. Apache interpreta `X-Forwarded-Proto` para
que Laravel genere URLs HTTPS. [Docker en Render](https://render.com/docs/docker) · [Puerto y HTTPS en Render](https://render.com/docs/web-services)

| Variable | Valor o acción |
| --- | --- |
| `APP_KEY` | Guarda como secreto una clave estable generada para este entorno. Con la imagen construida, puedes generarla en privado con `docker run --rm bepartners:local php artisan key:generate --show`. Conserva la clave al redesplegar. |
| `APP_URL` | URL pública HTTPS del servicio, sin barra final. |
| `APP_NAME` | Nombre visible del proyecto. |
| `APP_ENV`, `APP_DEBUG` | La imagen usa `production` y `false`. |
| `DB_CONNECTION`, `DB_URL` | Para Render Postgres, usa `pgsql` y pega la URL interna como secreto. Para MySQL o MariaDB, usa el driver correspondiente y las credenciales del proveedor. Laravel lee `DB_URL`. Configura TLS según el proveedor. |
| `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `CACHE_STORE` | La imagen usa `database`, `true` y `database`. Las tablas de sesiones y caché deben existir. |
| `QUEUE_CONNECTION` | La imagen usa `sync`. |
| `LOG_CHANNEL`, `LOG_LEVEL` | La imagen usa `stderr` e `info`. |
| `MAIL_MAILER` | La imagen usa `array` hasta configurar un proveedor. Los correos de recuperación y verificación requieren un servicio de correo autorizado. |
| `PORT` | Render lo suministra y la imagen usa 10000 por defecto. |

Configura los secretos en Render como variables de ejecución. Mantén las
credenciales fuera de Git y de las variables `VITE_*`, que pueden quedar
incluidas en el JavaScript público. El almacenamiento local se usa para
archivos temporales de Laravel. Los logos y demás recursos incluidos en el
repositorio se sirven desde la imagen. Si se incorporan archivos de usuario
persistentes, habrá que elegir dónde almacenarlos.

Inicializa únicamente la base académica dedicada tras confirmar el destino
y revisar las migraciones. En una base nueva y aprobada, ejecuta
`php artisan migrate --force` y después
`php artisan db:seed --force --class=RoleSeeder`. Para una base con datos,
revisa primero el historial y los respaldos. Una migración elimina la tabla
`activity_checklist_items`. Los despliegues de pago admiten un comando
previo al despliegue. El seeder crea roles y el primer administrador
requiere una inicialización aprobada. [Ejecución previa al despliegue](https://render.com/docs/deploys)

## Prueba local con Docker

Ejecuta estos comandos en una terminal compatible con Bash. Usan SQLite
dentro de un contenedor desechable y un puerto enlazado a loopback.

```sh
docker build --tag bepartners:local .
APP_KEY="$(docker run --rm bepartners:local php artisan key:generate --show)"
docker run --rm -d --name bepartners-smoke -p 127.0.0.1:10000:10000 \
  -e APP_KEY="$APP_KEY" -e APP_URL=http://127.0.0.1:10000 \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/bepartners-smoke.sqlite \
  -e SESSION_SECURE_COOKIE=false bepartners:local
docker exec --user www-data bepartners-smoke sh -ec \
  'touch "$DB_DATABASE" && php artisan migrate --seed --force --no-interaction'
curl -fsS http://127.0.0.1:10000/up
curl -fsS http://127.0.0.1:10000/login
curl -fsS http://127.0.0.1:10000/build/manifest.json
docker stop bepartners-smoke
unset APP_KEY
```
