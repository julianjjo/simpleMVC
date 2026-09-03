<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Funciones auxiliares globales
|--------------------------------------------------------------------------
|
| Se definen en el espacio de nombres global y protegidas con function_exists
| para poder convivir con otros micro-frameworks. Los plantillas (.php de
| Templates/) no declaran namespace, así que las usan directamente.
|
 */

use SimpleMvc\Core\App;
use SimpleMvc\Core\Request;
use SimpleMvc\Core\Response;
use SimpleMvc\Support\Env;

if (!function_exists('app')) {
    /**
     * Devuelve la aplicación en marcha, o un servicio del contenedor.
     */
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $app = App::instance();

        if ($app === null) {
            throw new RuntimeException('La aplicación no está inicializada: llama a App::boot() primero.');
        }

        return $abstract === null ? $app : $app->make($abstract, $parameters);
    }
}

if (!function_exists('config')) {
    /**
     * Lee configuración con notación de puntos: config('database.mysql.charset').
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        $app = App::instance();

        if ($app === null) {
            return $default;
        }

        return $key === null ? $app->config()->all() : $app->config()->get($key, $default);
    }
}

if (!function_exists('request')) {
    function request(): Request
    {
        /** @var Request */
        return app(Request::class);
    }
}

if (!function_exists('view')) {
    /**
     * Renderiza una plantilla y devuelve su HTML (ya escapado por el uso de e()).
     */
    function view(string $template, array $data = [], ?string $layout = '__auto__'): string
    {
        return app(\SimpleMvc\Core\View::class)->render($template, $data, $layout);
    }
}

if (!function_exists('partial')) {
    /**
     * Subplantilla sin layout: la forma corta de `view($t, $datos, null)`.
     */
    function partial(string $template, array $data = []): string
    {
        return app(\SimpleMvc\Core\View::class)->partial($template, $data);
    }
}

if (!function_exists('response')) {
    /**
     * Crea una respuesta HTML. Acepta el mismo cuerpo que un controlador.
     */
    function response(mixed $body = '', int $status = 200): Response
    {
        return Response::make($body, $status);
    }
}

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirección segura: solo acepta rutas relativas al propio sitio, de
     * modo que un usuario no pueda forzar una redirección abierta.
     */
    function redirect(string $to, int $status = 302): Response
    {
        return Response::redirect($to, $status);
    }
}

if (!function_exists('back')) {
    /**
     * Vuelve a la página anterior (Header Referer saneado o /).
     */
    function back(int $status = 302): Response
    {
        $referer = request()->header('referer') ?? '/';

        return Response::redirect($referer, $status);
    }
}

if (!function_exists('e')) {
    /**
     * Escapa un valor para imprimirlo en HTML de forma segura.
     *
     * El proyecto original imprimía el nombre de cada producto sin escapar
     * (XSS) y el título del layout con una etiqueta de apertura sin `echo`.
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        } else {
            $value = (string) $value;
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true);
    }
}

if (!function_exists('url')) {
    /**
     * URL absoluta para una ruta interna, respetando el subdirectorio
     * donde esté instalado el proyecto.
     */
    function url(string $path = ''): string
    {
        return App::instance()?->url($path) ?? '/'.ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * URL de un archivo público (css, js, imágenes) bajo public/.
     */
    function asset(string $path): string
    {
        $base = config('app.public_url');

        if (is_string($base) && $base !== '') {
            return rtrim($base, '/').'/'.ltrim($path, '/');
        }

        return url($path);
    }
}

if (!function_exists('route')) {
    /**
     * Genera la URL de una ruta nombrada: route('products.show', ['id' => 3]).
     *
     * @param  array<string, int|string>  $parameters
     */
    function route(string $name, array $parameters = []): string
    {
        return app(\SimpleMvc\Core\Router::class)->url($name, $parameters);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app(\SimpleMvc\Core\Session::class)->token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="'.e(csrf_token()).'">';
    }
}

if (!function_exists('old')) {
    /**
     * Valor previo de un campo tras un envío fallido.
     */
    function old(string $key, mixed $default = ''): string
    {
        $old = app(\SimpleMvc\Core\Session::class)->oldInput();

        return (string) ($old[$key] ?? $default);
    }
}

if (!function_exists('flash')) {
    /**
     * Guarda un mensaje de un solo uso (se consume en la siguiente petición).
     */
    function flash(string $key, mixed $value): void
    {
        app(\SimpleMvc\Core\Session::class)->flash($key, $value);
    }
}

if (!function_exists('flashed')) {
    /**
     * Lee un mensaje flash desde la plantilla.
     */
    function flashed(string $key, mixed $default = null): mixed
    {
        return app(\SimpleMvc\Core\View::class)->sharedFlash($key, $default);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        return Env::bool($key, $default);
    }
}

if (!function_exists('env_int')) {
    function env_int(string $key, int $default = 0): int
    {
        return Env::int($key, $default);
    }
}

if (!function_exists('str_limit')) {
    function str_limit(string $value, int $limit = 120, string $end = '…'): string
    {
        return \SimpleMvc\Support\Str::limit($value, $limit, $end);
    }
}

if (!function_exists('now')) {
    function now(string $timezone = 'UTC'): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($timezone));
    }
}
