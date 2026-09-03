# Changelog

Formato aproximado a [Keep a Changelog](https://keepachangelog.com/es/1.1.0/) y versionado [semántico](https://semver.org/lang/es/).

## [2.0.0] — 2026-09-03

Reescritura completa del tutorial de 2014 (12 archivos, 367 líneas) manteniendo la idea original: un MVC mínimo que se entiende leyendo. Ahora son ~11 800 líneas en 75 archivos PHP, con namespaces, Composer, PDO, verbos HTTP, middleware, validación, CSRF, consola y 185 pruebas automáticas.

### Añadido
- **Composer y PSR-4**: `SimpleMvc\` → `src/` (el micro-framework), `App\` → `app/` (la demo). Sigue funcionando **sin Composer**: `src/Support/autoload.php` es un autoloader propio equivalente.
- **Estructura `public/` + `src/` + `config/` + `routes/` + `templates/` + `storage/`**: el docroot ya no apunta al código.
- **Frente de control** `public/index.php` con manejo de errores global, página 404/500 y `ErrorException` para avisos y notices.
- **Contenedor con autowiring** (`Container`): `bind`, `singleton`, `instance`, `make`, `call`, detección de dependencias circulares.
- **Router con verbos HTTP** (`get/post/put/patch/delete/options/any/match`), `HEAD` implícito en `GET`, `405` con cabecera `Allow`, grupos con prefijo/nombre/middleware/`where`, `fallback()`, rutas nombradas y `route()` para generar URLs.
- **Parámetros con restricciones en la propia ruta**: `/productos/:id(\d+)` y `->where('slug', '[a-z-]+')`.
- **Middleware** con firma `handle(Request $request, Closure $next): Response`, global, de grupo y de ruta; puede ser clase (resuelta por el contenedor) o closure. Ejemplo incluido: `App\Middleware\RequestId`.
- **Protección CSRF** (`CsrfMiddleware` + `csrf_field()`/`csrf_token()`), con `419` al fallar, rotación de token y posibilidad de eximir rutas.
- **`Request` inmutable** desacoplada de los superglobales: `input/query/body/int/bool/string/has/filled/wantsJson/isAjax/header/cookie`, cuerpo JSON, y `_method` para PUT/PATCH/DELETE desde formularios.
- **`Response`** con estados, cabeceras, cookies, `send()`, y protección contra *open redirects* (`Response::redirect('//evil.example')` no saca del sitio).
- **PDO** (`Database`) con `sqlite` o `mysql`/`mariadb` elegido en `.env`, consultas preparadas siempre, `select/selectOne/scalar/insert/update/delete`, transacciones, log de consultas y `QueryBuilder` (`where`, `orWhere`, `whereIn`, `whereBetween`, `whereNull`, `whereAnyOf`, `orderBy`, `limit`, `paginate`, `pluck`, agregados).
- **`Record`**: DTO inmutable con mapeo `snake_case` ↔ `camelCase`, *casts* por tipo (`int`, `float`, `bool`, `DateTimeImmutable`) y `toArray()`/`jsonSerialize()`.
- **Validación** (`Validator`) con 18 reglas y mensajes en español, `:attribute` sustituido, etiquetas legibles y errores por campo.
- **Vistas** con layout, `partial()`, `addNamespace()`, datos compartidos, notación de puntos (`products/index` o `products.index`) y *output escaping* con `e()`.
- **Flashes y *old input*** en `Session` (envoltorio de `$_SESSION` que también funciona en memoria para pruebas), `Paginator` con ventana de páginas y `?q=…&page=N` conservando filtros.
- **Contexto de log por petición**: `Logger::pushContext()`/`clearContext()`; `App` lo limpia al terminar, así que un runtime persistente no arrastra el id de correlación de una petición a la siguiente.
- **`Router::exceptionHandler()`**: lo que lanza la acción de una ruta se convierte en respuesta *dentro* del pipeline, de modo que los middleware que la envuelven decoran también la respuesta de error (un 404 de `findOrFail()` sale con `X-Request-Id` y `X-Response-Time`).
- Política de avisos en `ErrorHandler`: deprecaciones solo con `APP_DEBUG`, nada de convertir avisos nacidos en `vendor/`, `unregister()` para tests y `error_reporting` intacto bajo PHPUnit.
- **Consola de desarrollo** (`bin/console.php`): `setup`, `migrate`, `seed [--force]`, `routes`, `ping`, `serve`.
- **Linter portable** `bin/lint.php` (usa `php -l` y cae a `token_get_all()` si el entorno no permite lanzar subprocesos).
- **Suite de pruebas**: 185 pruebas / 558 aserciones. Corren con PHPUnit 11 o, si no hay `vendor/`, con `tests/run.php` (runner propio de cero dependencias con un *shim* de `TestCase`).
- **Integración continua** en `.github/workflows/ci.yml`: matriz PHP 8.2 / 8.3 / 8.4 (más una corrida con dependencias mínimas), `composer validate`, `php bin/lint.php`, la suite, la consola y un humo HTTP contra `php -S`.
- **Demo visual** en `templates/` + `public/assets/app.css` (350 líneas, CSS puro, claro/oscuro, CSP restrictiva) con listado, filtros, búsqueda, orden, paginación, alta/edición/borrado y API JSON en `/api/v1/productos`.
- `Makefile`, `.editorconfig`, `.gitignore`, `.env.example`, `LICENSE` (MIT) y este `CHANGELOG.md`.

### Cambiado
- `Config.php` con `define()` de credenciales → `config/*.php` leídos por `SimpleMvc\Core\Config` (notación de puntos) y valores desde `.env` vía `SimpleMvc\Support\Env`.
- Todo el código usa `declare(strict_types=1)`, tipos en parámetros y retornos,.promoted properties, `match`, operador null-safe y `finally` donde toca.
- Las rutas ya no se declaran con barra final obligatoria: el router tolera `/productos` y `/productos/` por igual.
- `getResult()` de `Model` → repositorios (`ProductRepository`) que devuelven objetos `Record`; nada de matrices crudos por la capa de controladores.
- La vista `Templates/Productos.php` → `templates/products/index.php`; el `TemplateBase` que hacían `include` los controladores → `layout.php` gestionado por `View`.
- El HTML se escapa en la plantilla con `e()`; `url()`/`asset()`/`route()` generan las URLs según el prefijo de instalación.

### Corregido (errores del código original)
- `index.php` hacía `require 'core/Loader.php'` en minúsculas: en sistemas con sufijo de caja sensible (Linux) el proyecto **reventaba nada más abrirlo**. Ahora el autoloader está en `src/Support/` y se resuelve por Composer o por el propio.
- `Model::__construct()` consultaba `$mysqli->connect_error` sobre una variable inexistente: cualquier fallo de conexión producía un error de variable indefinida en lugar del mensaje real.
- `Model::getResult()` usaba `$resultArray` sin inicializar y devolvía `false`/fatal cuando la consulta fallaba; además se quedaba con el resultado abierto.
- `ProductsController::index()` construía SQL concatenando el `q` de la URL: **inyección SQL**. Ahora viaja como *binding*.
- `ProductsController::show()` imprimía `$id` sin escapar en la plantilla: **XSS reflejado**. Todo lo dinámico pasa por `e()`.
- No se fijaba el juego de caracteres de `mysqli` (`set_charset('utf8mb4')`): los acentos salían como `Ã¡`. Con PDO se usa el charset de la DSN y las vistas declaran `charset=utf-8`.
- `Router::sendResponse()` comprobaba `instanceof Response` cuando la clase `Response` no existía en el proyecto: la comprobación nunca era cierta y los arreglos devueltos por un controlador se imprimían con `Array to string conversion`.
- `View::getTemplate()` dejaba `ob_start()` abierto si la plantilla lanzaba un error: el buffer se comía la salida de peticiones posteriores. `View::capture()` ahora limpia el buffer en `finally`.
- `TemplateBase.php` usaba `<?php $title ?>` (sin `echo`), así que el `<title>` salía vacío.
- `call_user_func_array($closure, $params)` con claves de cadena lanzaba `Unknown named parameter` en PHP 8; los parámetros de ruta se mapean explícitamente.
- El `.htaccess` redirigía quitando la barra final mientras las rutas estaban declaradas **con** barra final: la portada (`/`) no casaba y devolvía 404 en Apache.
- Credenciales de base de datos escritas a fuego en `Config.php` y `define()` globales; ahora viven en `.env` (ignorado) con `.env.example` versionado.
- No había manejo de errores: cualquier aviso o excepción terminaba en una página en blanco. Ahora hay `ErrorHandler` con páginas 404/500, trazas solo en `APP_DEBUG=true` y registro en `storage/logs/app.log`.
- Rutas sin verbos HTTP: un `DELETE` se aceptaba igual que un `GET`. Ahora hay 405 con `Allow` y verificación de método.
- Sin autoloading, sin pruebas, sin CI, sin licencia escrita.

### Corregido en esta misma tanda (encontrado por la suite)
- `Database::update()` ensamblaba los *bindings* con el WHERE delante del SET: los UPDATE no fallaban pero **no cambiaban ninguna fila**. Orden corregido y `compileUpdate()`/`compileDelete()` expuestos.
- `Router::middleware()` guardaba el middleware global pero `register()` solo aplicaba el de los grupos: `$router->middleware(…)` no llegaba nunca a las rutas.
- `Route::build()` no compilaba la ruta antes de leer los parámetros y usaba `'$1'.$replacement`, que con un `id` numérico se leía como el grupo 11: `route('products.show', ['id' => 1])` devolvía el patrón crudo o la ruta vacía.
- `Response::json()` estaba dentro de un docblock sin cerrar (el método no existía en tiempo de ejecución).
- `View` no aceptaba nombres con puntos (`errors.404`) y las páginas de error caían al texto plano; el guardado del `ob_start()` del layout y las variables del contracto (`current_path`, `debug`…) tampoco estaban garantizados fuera de una petición.
- `View::path()` normalizaba los puntos *antes* de comprobar `..`, con lo que la guarda de traversal se saltaba sola.
- `Container` intentaba instanciar `Closure` al autowirear `CsrfMiddleware` y la guarda anti-ciclos no cubría los bindings definidos con closures (bucle infinito hasta agotar la memoria).
- Los datos de ejemplo guardaban slugs con espacios: ninguna URL canónica coincidía (`findBySlug()` ahora normaliza y `database/seeds.php` cita `Str::slug()`).
- `Request` solo aplicaba `_method` en `capture()`: las sub-peticiones construidas a mano se quedaban en POST y daban 405.
- `QueryBuilder::pluck()` citaba dos veces el identificador; `Paginator::firstItem()` decía 1 en una página vacía.
- Los flashes se envejecían en `Session::start()` (que es idempotente), así que con un runtime persistente no llegaban nunca a la siguiente petición.
- `bin/lint.php` colgaba en entornos sin `exec`: ahora cae a `token_get_all(TOKEN_PARSE)`.
- `templates/layout.php` llamaba a una variable `$e` inexistente: todas las páginas daban 500.

### Retirado
- `Core/Loader.php` (el `spl_autoload_register` artesanal con `str_replace`), `Core/Model.php` acoplado a `mysqli`, `Config.php` con constantes y `TemplateBase.php`.
- La extensión `mysqli` como única opción: se usa PDO.

### Compatibilidad
- `index.php` en la raíz se conserva como *shim*: carga `public/index.php` y ajusta `SCRIPT_FILENAME` para que la autodetección del prefijo funcione si el docroot apunta a la raíz del repositorio. Para despliegues nuevos, apunta el docroot a `public/`.
- `Router::add($uri, $action)` sigue existiendo (acepta GET y POST, como antes), pero se recomienda `get()`/`post()`.
- El directorio `Templates/` con T mayúscula pasó a `templates/`; si conservas plantillas propias, muévelas y añade `e()` a las variables.
