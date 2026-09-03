# simpleMVC — micro-framework MVC en PHP 8, sin dependencias

> El mismo ejercicio de 2014 (rutas amigables en PHP sin framework), llevado a
> lo que se escribiría hoy: namespaces y PSR-4, Composer *(opcional)*, PDO con
> consultas preparadas, router con verbos HTTP y middleware, vistas con layout,
> validación, CSRF, consola, 185 pruebas y CI.

![CI](https://github.com/julianjjo/simpleMVC/actions/workflows/ci.yml/badge.svg)
[![PHP](https://img.shields.io/badge/PHP-8.2%2C%208.3%2C%208.4-777bb3)](https://www.php.net/supported-versions.php)
[![Licencia: MIT](https://img.shields.io/badge/licencia-MIT-blue)](LICENSE)

Lo interesante de este repo no es que tenga un framework — hay muchos— sino que
**se puede leer entero**: `src/` son ~6 400 líneas de PHP con tipos, sin
magia, y cada pieza (`Router`, `Route`, `View`, `Database`, `Container`,
`Validator`, `Session`) cabe en una sentada.

---

## Demo en 60 segundos

```bash
git clone https://github.com/julianjjo/simpleMVC.git && cd simpleMVC

# 1) Base de datos: SQLite en storage/db/app.sqlite (no hace falta servidor)
php bin/console.php setup

# 2) Correr la demo
php bin/console.php serve            # o: php -S 127.0.0.1:8000 -t public public/router.php
```

Abre <http://127.0.0.1:8000> y verás el listado de productos con búsqueda,
filtro por categoría, orden, paginación, alta/edición/borrado (con CSRF) y una
API JSON en `/api/v1/productos`.

No hace falta `composer install` para nada de esto. Si quieres PHPUnit:

```bash
composer install && composer test
```

### Requisitos

- PHP **8.2 o superior** con `pdo` y `json`; `pdo_sqlite` para la demo (o `pdo_mysql` si prefieres MySQL).
- Composer *solo* para las dependencias de desarrollo (PHPUnit).

---

## Estructura

```
app/                     La aplicación de ejemplo (lo que el lector copiaría)
├── Controllers/         HomeController, ProductsController, Api/ProductsController
├── Middleware/          RequestId (ejemplo de middleware con tiempos e id de correlación)
├── Models/              Product (Record: tipos, casts, helpers de presentación)
└── Repositories/        ProductRepository (QueryBuilder + paginación)
bin/
├── console.php          setup | migrate | seed | routes | ping | serve
└── lint.php             php -l sobre todo el proyecto (con alternativa sin subprocesos)
config/
├── app.php              nombre, env, debug, url, zona horaria, vistas
├── database.php         driver (sqlite|mysql) y credenciales
├── session.php          nombre y duración de la cookie de sesión
└── logging.php          archivo y nivel
database/
├── schema.sqlite.sql    esquema (CREATE TABLE IF NOT EXISTS, idempotente)
├── schema.mysql.sql     el mismo esquema para MySQL/MariaDB
└── seeds.php            14 productos de ejemplo
public/                  docroot: lo único accesible por HTTP
├── index.php            frente de control
├── router.php           router para `php -S` (sirve estáticos + index.php)
├── .htaccess            reescritura para Apache
└── assets/app.css       la demo visual (CSS puro, claro/oscuro)
routes/web.php           todas las rutas del proyecto
src/                     el micro-framework
├── Core/                App, Router, Route, Request, Response, View, Container,
│                        Config, Database, QueryBuilder, Session, CsrfMiddleware,
│                        Validator, Paginator, Record, Logger, Controller, ErrorHandler
├── Exceptions/          HttpException, NotFoundHttpException, ValidationException…
└── Support/           helpers globales, Env, Str, autoload.php (sin Composer)
storage/                 BD, logs (ignorado en git)
templates/               layout.php, home.php, products/, params.php, errors/
tests/                   185 pruebas (PHPUnit o el runner propio)
```

---

## Configuración

`config/*.php` devuelven arreglos; la clave de primer nivel es el nombre del
archivo (`app.name`, `database.driver`, `logging.level`…). Los valores salen del
entorno con `env()`, así que **no hay credenciales en el código**:

```php
// config/database.php
return [
    'driver' => env('DB_DRIVER', 'sqlite'),
    'sqlite' => ['path' => env('DB_SQLITE_PATH', 'storage/db/app.sqlite')],
    'mysql'  => [
        'host' => env('DB_HOST', '127.0.0.1'),
        // ...
    ],
];
```

```bash
cp .env.example .env     # .env está en .gitignore
```

```ini
APP_ENV=prod
APP_DEBUG=false          # oculta trazas y muestra la página de error genérica
DB_DRIVER=mysql
DB_DATABASE=mvc
DB_USERNAME=app
DB_PASSWORD=…
LOG_PATH=storage/logs/app.log
```

En las plantillas y los controladores: `config('app.debug')`, `config('database.driver', 'sqlite')`.

---

## Rutas

`routes/web.php` devuelve un closure que recibe el `Router`. Los tres ejemplos
del README original, en la sintaxis de hoy:

```php
use SimpleMvc\Core\Router;

return function (Router $router): void {
    // 1) closure
    $router->get('/', fn () => 'Home')->name('home');

    // 2) una función del proyecto
    $router->get('/inicio', 'App\\Controllers\\HomeController::index');

    // 3) método de controlador (se resuelve por el contenedor: inyección real)
    $router->get('/productos', [ProductsController::class, 'index']);

    // Parámetro con restricción: /productos/42 entra, /productos/42x no.
    $router->get('/productos/:id(\d+)', [ProductsController::class, 'show']);

    // Verbos: un DELETE ya no se traga un GET.
    $router->delete('/productos/:id(\d+)', [ProductsController::class, 'destroy']);

    // Grupos con prefijo, nombre y middleware.
    $router->group(['prefix' => 'api/v1', 'as' => 'api.'], function (Router $router): void {
        $router->get('/productos', [Api\ProductsController::class, 'index']);
    });

    // 404 propio para todo lo que no case.
    $router->fallback(fn (Request $request) => response("Nada por {$request->path()}", 404));
};
```

Detalles que importan:

- `GET` acepta `HEAD` automáticamente; un método válido pero no declarado
  responde **405 con cabecera `Allow`**.
- La barra final da igual: `/productos` y `/productos/` casan con la misma ruta
  (en el código de 2014 esto devolvía 404 en la portada).
- Los parámetros llegan al handler por nombre *y* por posición.
- `php bin/console.php routes` imprime la tabla de rutas con sus restricciones.
- URLs: `url('/productos')`, `asset('assets/app.css')` y `route('products.show', ['id' => 3])`
  (el generador respeta el prefijo de instalación y sustituye los parámetros;
  los que sobran se convierten en *query string*).

---

## Controladores y contenedor

`Container` resuelve por tipos del constructor; si algo no está registrado y es
construible, se construye solo.

```php
namespace App\Controllers;

use SimpleMvc\Core\Controller;

final class ProductsController extends Controller
{
    public function __construct(
        private ProductRepository $products,   // <- inyectado
        \SimpleMvc\Core\View $view,
        \SimpleMvc\Core\Request $request,
        \SimpleMvc\Core\Session $session,
    ) {
        parent::__construct($view, $request, $session);
    }

    public function index(): Response
    {
        $paginator = $this->products->searchFiltered(
            term: (string) $this->request->query('q', ''),
            page: max(1, $this->request->int('page', 1)),
        );

        return $this->render('products/index', ['paginator' => $paginator]);
    }
}
```

Devolver una cadena pinta HTML; un arreglo, JSON; `null` responde 204. También
hay `json()`, `text()`, `redirect()`, `redirectToRoute()`, `back()`, `flash()`
y `validateOrFail()`.

Para registrar tus propios servicios (o sustituir los del framework en pruebas):

```php
$app = App::boot(dirname(__DIR__));

// Añadir un servicio
$app->container()->singleton(Mailer::class, fn (Container $c) => new Mailer($c->make(Config::class)));

// Sustituir uno existente (así se inyecta la BD en memoria en los tests)
$app->container()->instance(Database::class, $dbDePruebas);
$app->run();
```

`Config`, `Logger`, `Database`, `Session`, `View` y `Router` se registran solo si
no estaban ya bound: se puede montar la aplicación con piezas propias.

---

## Middleware

Firma: `handle(Request $request, Closure $next): Response`. Vale una clase
(resuelta por el contenedor) o un closure. Se puede aplicar globalmente, a un
grupo o a una ruta.

```php
$router->middleware(App\Middleware\RequestId::class);          // global
$router->group(['middleware' => CsrfMiddleware::class], …);     // grupo
$router->get('/salud', …)->middleware(fn ($r, $next) => $next($r)->withHeader('X-Cache', 'no-store'));
```

El orden es de cebolla: el global envuelve al de grupo y este al de ruta; en la
respuesta, el global tiene la última palabra.

### CSRF

`CsrfMiddleware` exige token en todo lo que no sea `GET`/`HEAD`/`OPTIONS`. En
los formularios basta con `<?= csrf_field() ?>`; para `fetch`/`axios`, manda la
cabecera `X-CSRF-Token` con `csrf_token()`. Un token inválido responde **419**
con mensaje legible y, si la petición pedía JSON, un `{"error":419}`.

```php
// Eximir rutas (por ejemplo, un webhook firmado):
$app->container()->instance(CsrfMiddleware::class, new CsrfMiddleware(
    $app->make(Session::class),
    static fn (Request $request): bool => str_starts_with($request->path(), '/webhooks'),
));
```

---

## Vistas

`View` hace `include` de la plantilla con los datos extraídos, captura el buffer
(sin importar qué lance la plantilla) y mete el resultado en el layout.

```php
return $this->render('products/index', [                    // templates/products/index.php
    'paginator' => $paginator,
], 'layout');                                                // null => sin layout
```

```php
<?php // templates/products/show.php
$title = $product->nombre;              // las variables que define la plantilla llegan al layout
?>
<h1><?= e($product->nombre) ?></h1>     <!-- e() escapa; todo lo dinámico pasa por ahí -->
<?= partial('products/card', ['product' => $product]) ?>   <!-- subplantilla, sin layout -->
```

- Nombres con puntos o barras: `products/index` ≡ `products.index`.
- Datos compartidos con todas las plantillas: `view()->share('app_name', 'Mi sitio')`.
- Funciones disponibles dentro de una plantilla: `e()`, `url()`, `asset()`, `route()`, `partial()`, `view()`, `config()`, `old()`, `flashed()`, `csrf_field()`.
- `errors`, `flashes`, `current_path`, `base_url` y `debug` siempre existen (valores neutros fuera de una petición).
- La ruta de plantilla se valida: nada de `../` ni de salirse de `templates/`.

---

## Validación

```php
$data = $this->validateOrFail(
    [
        'nombre'    => 'required|string|min:3|max:120',
        'slug'      => 'nullable|slug',          // si viene vacío lo genera el repositorio
        'precio'    => 'required|numeric|min:0',
        'stock'     => 'required|integer|min:0',
        'categoria' => 'required|in:'.implode(',', Product::CATEGORIES),
        'web'       => 'nullable|url',
    ],
    messages: ['precio.numeric' => 'El precio debe ser un número.'],
    attributes: ['nombre' => 'Nombre del producto'],
);
```

Reglas: `required`, `nullable`, `string`, `integer`, `numeric`, `boolean`,
`array`, `email`, `url`, `slug`, `date`, `regex`, `min`, `max`, `size`,
`between`, `in`, `confirmed`. La unicidad del slug la resuelve el repositorio
(`uniqueSlug()`), no una regla que haga una consulta por campo. Los mensajes están en español y `:attribute` se sustituye
por la etiqueta (`nombre` → «Nombre» o la que pases). Si la validación falla en
una petición de formulario, se redirige **303** hacia atrás con los errores y lo
escrito (`old('nombre')`); si pedía JSON, responde **422** con `{"errors":{…}}`.

---

## Base de datos

PDO perezoso (la conexión se abre al primer uso), siempre con consultas
preparadas:

```php
$db = $app->db();

$db->select('SELECT * FROM productos WHERE stock > ? ORDER BY nombre', [5]);
$db->selectOne('SELECT * FROM productos WHERE slug = ?', [$slug]);
$db->scalar('SELECT COUNT(*) FROM productos');

$db->table('productos')                       // QueryBuilder
   ->where('categoria', 'audio')
   ->whereAnyOf(['nombre', 'descripcion'], $termino)     // LIKE escapado
   ->orderBy('precio', 'desc')
   ->limit(10)
   ->get();

$id = $db->insert('productos', ['nombre' => 'Teclado', 'precio' => 189900]);
$db->update('productos', ['stock' => 0], ['id' => $id]);
$db->transaction(function (Database $db) { /* … rollback si algo lanza */ });
```

`QueryBuilder` cita identificadores, valida los operadores y **rechaza cadenas
que parezcan SQL pegado** (`guardRaw()`): los datos del usuario viajan como
*bindings*, nunca concatenados. `where([])` con `[]` vacío produce `1=0` en lugar
de un `IN ()` inválido.

Con `APP_DEBUG=true` cada consulta queda en `Database::queryLog()` (y en el log
de la aplicación si falla).

### Registros y repositorios

`Record` es un DTO inmutable con mapeo `snake_case` ↔ `camelCase`, *casts* según
el tipo declarado y `toArray()`/`JsonSerializable`:

```php
final class Product extends Record
{
    public int $id;
    public string $nombre;
    public ?float $precio = null;
    public bool $destacado = false;
    public ?DateTimeImmutable $creadoEn = null;

    public function precioFormateado(): string { /* … */ }
}
```

Si una fecha llega ilegible, `Record` lanza en vez de convertirla en `null` en
silencio (los datos viejos del tutorial traían cualquier cosa).

---

## Paginación, sesiones y flashes

```php
$paginator = $repo->searchFiltered(perPage: 9, page: $page, appends: $filtros);
// $paginator->items(), total(), lastPage(), window(5), url(3, '/productos'), queryString(2)
```

`Paginator` conserva los filtros en cada enlace (`?q=monitor&page=2`) y no
pone `&page=1` en la primera página.

`Session` envuelve `$_SESSION` —pero se puede construir con un arreglo, lo que
la hace utilizable en pruebas— y ofrece `get/put/pull/forget`,
`flash/getFlash/flashes`, `setOldInput/oldInput` y el token CSRF. El envejecimiento
de los flashes ocurre **al terminar** cada petición, así que funciona igual con
PHP-FPM y con runtime persistentes (RoadRunner, FrankenPHP).

---

## Consola

```
php bin/console.php setup          Crea el esquema y carga los datos de ejemplo
php bin/console.php migrate        Aplica database/schema.<driver>.sql
php bin/console.php seed           Inserta los productos (--force para resembrar)
php bin/console.php routes         Lista las rutas registradas
php bin/console.php ping           Comprueba la conexión a la base de datos
php bin/console.php serve          php -S en public/ (--host --port --php)
```

Con Composer son `composer setup`, `composer routes`, `composer dev`, etc. Con
Make: `make help`, `make demo`, `make test`.

---

## Pruebas

```bash
composer test          # PHPUnit si hay vendor/, runner propio si no
php tests/run.php      # 185 pruebas, cero dependencias
php tests/run.php --filter=Router
php vendor/bin/phpunit --testdox
```

La suite cubre router y rutas (verbos, restricciones, 404/405, grupos,
middleware, CSRF), `Request`/`Response`, contenedor, configuración y `Env`,
vistas (layout, *partials*, plantillas rotas, traversal), validador, paginador,
sesión, `Database`/`QueryBuilder` sobre SQLite en memoria (incluidos intentos de
inyección y escapado de `LIKE`), el repositorio y una prueba de extremo a
extremo que arranca la aplicación y comprueba portada, listado filtrado, ficha
por slug, redirecciones canónicas, API JSON, alta/edición/borrado con CSRF y
las páginas de error.

Los `.php` se revisan con `php bin/lint.php` (usa `php -l` y, si el entorno no
deja lanzar subprocesos, `token_get_all()` con `TOKEN_PARSE`).

---

## Despliegue

Apunta el docroot a `public/`. Con ese montaje, `public/.htaccess` hace el
resto; también hay un `.htaccess` en la raíz para quien no pueda tocar el
docroot (bloquea `.env`, `.git` y sirve desde `public/index.php`).

```nginx
server {
    root /var/www/simpleMVC/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

**Instalado en un subdirectorio** (`http://localhost/rutas_amigables/`): el
prefijo se autodetecta comparando `DOCUMENT_ROOT` con la ruta física del script,
así que las rutas y los assets salen correctos sin tocar nada. Si el montaje es
raro (symlinks, phar), fíjalo a mano: `APP_BASE_PATH=/rutas_amigables`.

En producción: `APP_ENV=prod`, `APP_DEBUG=false`, `composer dump-autoload -o` y
`storage/` escribible por el usuario de PHP-FPM.

---

## API JSON de la demo

| Método | Ruta | Qué devuelve |
| --- | --- | --- |
| GET | `/api/v1/productos?q=&categoria=&orden=&pagina=&solo_disponibles=1` | `{data, meta}` paginado |
| GET | `/api/v1/productos/:idOrSlug` | un producto o `404` en JSON |
| GET | `/salud` | estado, PHP, driver de BD y tiempo |

Cualquier ruta responde en JSON si la petición pide `Accept: application/json`
o `?format=json` — incluidos los errores, con `{"error":404}` en vez de HTML.

---

## Migrar desde la versión de 2014

| Antes | Ahora |
| --- | --- |
| `require 'core/Loader.php'` | `require 'vendor/autoload.php'` o `src/Support/autoload.php` |
| `new Router($_SERVER['REQUEST_URI'])` | `App::boot($basePath)->run()` |
| `$router->add('/ruta', $closure)` | `$router->get('/ruta', $closure)` (`add()` sigue existiendo) |
| `Config.php` con `define()` | `config/*.php` + `.env` |
| `Model::getModel()` | controlador con `Database`/repositorio inyectados |
| `Templates/Productos.php` | `templates/products/index.php` + `layout.php` |
| `TemplateBase` | `SimpleMvc\Core\View` |

El detalle de qué estaba roto y cómo se arregló está en
[CHANGELOG.md](CHANGELOG.md).

---

## Licencia

MIT — ver [LICENSE](LICENSE).
