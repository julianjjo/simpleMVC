<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Router para el servidor de desarrollo de PHP
|--------------------------------------------------------------------------
|
|   php -S 127.0.0.1:8000 -t public public/router.php
|
| Sustituye a mod_rewrite: sirve los archivos que existen en public/ y manda
| el resto al frente de control, para que las rutas amigables funcionen sin
| Apache.
|
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);
$file = __DIR__.('/'.ltrim($path, '/'));

// Devolver false le dice a `php -S` que sirva el archivo tal cual (css, js, imágenes).
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}

// Evita salir de public/ con rutas tipo ../.
if ($path !== '/' && str_contains($path, '..')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 — Página no encontrada\n";

    return true;
}

require __DIR__.'/index.php';
