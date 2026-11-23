# Despliegue de BEPARTNERS en Render

El Dockerfile de la raíz instala las dependencias PHP de producción desde
`composer.lock` y compila Vite desde `package-lock.json`. La imagen final
sirve `public/` con Apache. Escucha en `0.0.0.0:$PORT` y usa 10000 por
defecto. Normalmente el contenedor inicia Apache directamente. El endpoint `/up` comprueba que
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
| `BEPARTNERS_INIT_DB` | Solo para el primer despliegue. Usa el nombre exacto de la base académica que aparece en Render, sin comillas. Si la variable está ausente o vacía, el arranque omite la inicialización. |
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

## Primer despliegue con PostgreSQL vacío

1. Confirma en el panel que `DB_URL` contiene la **URL interna del Postgres
   académico dedicado**, que el servicio está en la misma región y que la
   base está vacía. Comprueba que la credencial puede crear objetos en
   `public`. Reserva la base para esta inicialización y detén cualquier
   otro proceso que pueda escribir en ella. El nombre por sí solo no
   distingue dos servidores con bases homónimas.
2. Con el cambio ya publicado en `develop`, conserva **Auto-Deploy** en
   **Off**, el comando Docker predeterminado y la ruta de salud `/up`.
   Revisa `DB_CONNECTION=pgsql`, `DB_URL`, `APP_KEY` y `APP_URL`. Agrega
   `BEPARTNERS_INIT_DB` con el nombre exacto de la base y crea el servicio.
   Si el servicio ya existe, usa **Save only** y después
   **Manual Deploy → Deploy latest commit** para construir la imagen nueva.
   [Variables de entorno de Render](https://render.com/docs/configure-environment-variables) · [Despliegues en Render](https://render.com/docs/deploys)
3. En los logs, espera `BEPARTNERS INIT OK: migraciones y RoleSeeder confirmados.`
   El mensaje se emite después de confirmar la transacción. Comprueba que
   Render marque el despliegue como activo y que `/up`, `/login` y los
   recursos de Vite carguen por HTTPS.
4. Retira `BEPARTNERS_INIT_DB` en **Environment** y elige **Save and deploy**
   para aplicar el cambio a la imagen ya construida. Si esa opción no está
   disponible, guarda y lanza el despliegue manual. Repite las comprobaciones
   de `/up`, `/login` y Vite. En los logs del nuevo arranque debe aparecer
   Apache sin un nuevo mensaje `BEPARTNERS INIT OK`. Quitar la variable
   inmediatamente evita que un reinicio con la configuración anterior
   rechace el arranque al encontrar la base ya inicializada.

Con la variable activa, el contenedor verifica el controlador PostgreSQL,
el nombre de la base y el esquema activo `public`. Revisa los catálogos
para rechazar una base con objetos de usuario, esquemas adicionales o
extensiones adicionales a `plpgsql`. El bloqueo de PostgreSQL coordina
arranques que usan este mismo inicializador. La comprobación, las 13
migraciones actuales y `RoleSeeder` comparten una sola transacción.
`RoleSeeder` usa `firstOrCreate` y crea `admin`, `lider`,
`desarrollador` y `qa`. La creación del primer usuario administrador
es un paso separado que requiere autorización.

Si aparece `BEPARTNERS INIT ERROR`, Apache queda detenido y Render no
puede marcar ese arranque como saludable. Revisa en privado la URL
interna, el nombre, el controlador, los permisos y si otro proceso está
inicializando. Si el error ocurre antes de confirmar, PostgreSQL revierte
las operaciones de la transacción. Si se interrumpió el despliegue y
desconoces el resultado, confirma el estado por un acceso administrativo
autorizado antes de reintentar. Retira la variable y redespliega si
confirmas que la base ya quedó inicializada. Si encuentras datos o el
estado es incierto, detén el procedimiento y revisa el historial.

La migración de checklist elimina `activity_checklist_items`. En una base
vacía solo elimina la tabla que el propio lote acaba de crear. Este
procedimiento está diseñado para el esquema inicial. Revisa por separado
las migraciones futuras, especialmente cualquier operación no transaccional
o que use otra conexión. El bloqueo del inicializador no coordina cambios
hechos con otros clientes. [Bloqueos de PostgreSQL](https://www.postgresql.org/docs/17/functions-admin.html)

## Alta temporal del primer administrador

Con PostgreSQL ya inicializado, conserva `BEPARTNERS_INIT_DB` retirada,
`DB_CONNECTION=pgsql` y `DB_URL` con la URL interna de la base académica.
Verifica el servidor y el nombre en Render. Dos servidores pueden tener
bases con el mismo nombre.

1. Publica en `develop` y despliega manualmente la imagen nueva con el
   comando Docker predeterminado y las variables temporales aún ausentes.
   Confirma que la nueva versión esté **Live** y conserve `/up` como ruta
   de salud.
2. En **Environment** de `bepartners-pruebas`, agrega las cuatro variables
   siguientes y elige **Save and deploy** para reutilizar la imagen ya
   construida. [Variables de entorno de Render](https://render.com/docs/configure-environment-variables)

   | Variable | Valor |
   | --- | --- |
   | `BEPARTNERS_INIT_ADMIN_DB` | Nombre exacto de la base PostgreSQL, sin comillas. Activa el alta. |
   | `BEPARTNERS_INIT_ADMIN_NAME` | Nombre de la persona responsable de la cuenta, máximo 255 caracteres. |
   | `BEPARTNERS_INIT_ADMIN_EMAIL` | Correo válido en minúsculas, máximo 255 caracteres. |
   | `BEPARTNERS_INIT_ADMIN_PASSWORD` | Contraseña única y fuerte, con al menos ocho caracteres, una mayúscula, un número y un símbolo. Guárdala en el gestor de contraseñas del equipo. |

3. Espera `BEPARTNERS ADMIN OK: primera cuenta administrativa creada.`
   en los logs y verifica que el despliegue quede **Live**. Abre `/login`
   por HTTPS, inicia sesión con la cuenta y confirma el acceso a `/users`.
4. Elimina las cuatro variables temporales y aplica **Save and deploy**
   de nuevo. Verifica `/up` y repite el ingreso a `/users`. El nuevo
   arranque debe mostrar los mensajes normales de Apache. La cuenta sigue
   almacenada en PostgreSQL.

El script comprueba PostgreSQL, el nombre exacto y el esquema `public`.
Verifica las tablas `users` y `roles`, bloquea las escrituras concurrentes
en `users` durante la transacción y crea la cuenta solo si la tabla está
vacía. Busca el rol `admin` por nombre y usa el modelo `User` para guardar
el hash. Si ya hay usuarios, registra `BEPARTNERS ADMIN OMITIDO` y arranca
Apache sin cambiar cuentas. [Bloqueos de PostgreSQL](https://www.postgresql.org/docs/17/explicit-locking.html)

Si aparece `BEPARTNERS ADMIN ERROR`, ese arranque termina antes de Apache.
Revisa en privado las variables, el destino, el rol y los permisos de
lectura, escritura y bloqueo de la credencial de PostgreSQL. Si se agotó
la espera del bloqueo, espera a que termine la operación en curso antes de
reintentar. Para volver al arranque normal, retira las cuatro variables y
redespliega. Si el alta se omitió o el resultado es incierto, verifica la
cuenta por un acceso autorizado antes de intentar de nuevo.

Mantén la contraseña fuera de Git, los argumentos de compilación, el
historial de terminal y las capturas de pantalla. Retira las variables
después del alta. Si la tabla `users` se vacía en el futuro, volver a
habilitar el mecanismo permitiría crear otra primera cuenta.

## Despliegues automáticos desde GitHub Actions

El workflow **CI BEPARTNERS** valida Laravel, ejecuta las pruebas y compila
Vite en pushes a `develop` o `main` y en pull requests dirigidos a esas
ramas. Publica los artefactos `bepartners-frontend` y
`bepartners-pruebas`. Los dos jobs de despliegue dependen de `validate`
y se habilitan solo tras un push exitosamente validado a su rama.

| Rama | Job | Entorno de GitHub | Servicio de Render | Secreto del hook |
| --- | --- | --- | --- | --- |
| `develop` | `deploy-test` | `pruebas` | `bepartners-pruebas` | `RENDER_DEPLOY_HOOK_TEST` |
| `main` | `deploy-preprod` | `preproduccion` | `bepartners-preprod` | `RENDER_DEPLOY_HOOK_PREPROD` |

Cada secreto contiene la URL privada del Deploy Hook de su servicio.
Mantén **Auto-Deploy** desactivado en ambos servicios y, si se configuran
reglas de ramas en los entornos de GitHub, permite la rama correspondiente.
Cada job verifica que el SHA validado siga siendo la punta de su rama
remota y lo solicita mediante un POST HTTPS. Render construye la imagen
desde el repositorio y los artefactos de CI quedan en GitHub. Los jobs
de despliegue no ejecutan migraciones ni seeders.
[Deploy Hooks de Render](https://render.com/docs/deploy-hooks) · [Entornos de GitHub Actions](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments)

Para promover una versión, confirma que el SHA de `develop` pasó CI y
quedó **Live** en `bepartners-pruebas`. Promueve ese commit a `main`
mediante fast-forward cuando el historial lo permita, o mediante el
pull request que exijan las reglas del repositorio. Si la fusión genera
un SHA nuevo, comprueba que su árbol corresponda a la versión revisada.
El push resultante a `main` vuelve a pasar CI y solicita el despliegue
del SHA validado en esa rama. Los pull requests solo ejecutan CI.

1. En **GitHub Actions → CI BEPARTNERS**, abre la ejecución del push y
   anota su SHA. Revisa `validate`, los artefactos y el job de la rama.
   HTTP `200` indica que Render inició el despliegue. HTTP `202` indica
   que quedó en espera. El mensaje de omisión indica que la rama ya
   apuntaba a otro commit al comprobarla.
2. En **Render → servicio correspondiente → Deploys**, localiza ese SHA
   y confirma que figure como el despliegue **Live**. El éxito del job en
   GitHub solo confirma la solicitud. Si Render falla, revisa los logs
   de construcción y arranque de ese despliegue.
3. Comprueba que `/up` y `/login` devuelvan HTTP `200` por HTTPS. Estas
   rutas comprueban el servicio activo. Usa también el SHA en Render
   para distinguir el despliegue nuevo de una versión anterior que aún
   esté respondiendo.

El job falla con un mensaje claro si falta el secreto o si Render
rechaza la solicitud. Ante un `401` o `404`, verifica en privado que el
hook corresponda al servicio y siga vigente. Ante un timeout o una
conexión interrumpida, revisa **Deploys** antes de reintentar. Render
podría haber recibido el POST. GitHub serializa los jobs de solicitud
a cada entorno por separado y el cliente no reintenta automáticamente. Un rerun
manual todavía puede generar otra solicitud para el mismo commit.

La API de Render permitiría consultar el estado final sin consola
interactiva. Requeriría una API key adicional con acceso de lectura a
la información de despliegue y el ID del servicio. La respuesta `200`
del hook trae un ID de despliegue. Un `202` no lo trae y requeriría listar
despliegues y correlacionarlos por SHA y hora. Esta integración solo
confirma la solicitud. La verificación final se hace en el panel de
Render. [API keys de Render](https://render.com/docs/api-keys) · [Consultar un despliegue](https://api-docs.render.com/reference/retrieve-deploy) · [Listar despliegues](https://api-docs.render.com/reference/list-deploys)

- **Integración continua (CI)** valida cada cambio con instalación,
  compilación y pruebas automáticas.
- **Entrega continua** deja el cambio validado y listo para desplegar,
  con la decisión de promoción en manos del equipo.
- **Despliegue continuo** automatiza esa promoción. Aquí se solicita
  automáticamente hacia **pruebas** desde `develop` y hacia
  **preproducción** desde `main`, después de CI.

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
  'touch "$DB_DATABASE" &&
   php artisan migrate --force --no-interaction &&
   php artisan db:seed --force --class=RoleSeeder --no-interaction'
curl -fsS http://127.0.0.1:10000/up
curl -fsS http://127.0.0.1:10000/login
curl -fsS http://127.0.0.1:10000/build/manifest.json
docker stop bepartners-smoke
unset APP_KEY
```
