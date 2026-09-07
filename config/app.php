<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración general de la aplicación
|--------------------------------------------------------------------------
|
| Cada archivo de `config/*.php` se indexa por su nombre: lo que devuelve
| este archivo se lee con `config('app.*')`. Los valores provienen de
| variables de entorno (.env) leídas con env(), con un default sensato para
| que el proyecto funcione sin configurar nada.
|
 */

use SimpleMvc\Support\Str;

return [
    'name' => env('APP_NAME', 'simpleMVC'),
    'env' => env('APP_ENV', 'dev'),

    // En producción APP_ENV=prod y APP_DEBUG=false: sin trazas en el HTML.
    'debug' => env_bool('APP_DEBUG', env('APP_ENV', 'dev') !== 'prod'),

    'url' => env('APP_URL', 'http://localhost:8000'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // Vacío = se autodetecta comparando DOCUMENT_ROOT con la ubicación real de
    // public/index.php. Define APP_BASE_PATH solo si el servidor no expone
    // DOCUMENT_ROOT (proxys raros, alias de Apache).
    'base_path' => (string) env('APP_BASE_PATH', ''),
    'public_prefix' => (string) env('APP_PUBLIC_PREFIX', ''),

    'paths' => [
        'views' => Str::basePath('templates'),
        'public' => Str::basePath('public'),
    ],

    'views' => [
        // Layout envuelto alrededor de cada plantilla. View::render($tpl, [], null)
        // lo desactiva (respuestas parciales, AJAX).
        'layout' => 'layout',
    ],
];
