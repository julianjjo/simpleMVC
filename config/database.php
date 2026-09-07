<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Base de datos
|--------------------------------------------------------------------------
|
| sqlite -> cero configuración, ideal para probar la demo y correr los tests.
| mysql  -> requiere servidor; el charset utf8mb4 se fija en el DSN.
|
 */

use SimpleMvc\Support\Str;

return [
    'driver' => env('DB_DRIVER', 'sqlite'),

    'sqlite' => [
        'path' => Str::basePath((string) env('DB_SQLITE_PATH', 'storage/db/app.sqlite')),
    ],

    'mysql' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env_int('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'mvc'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'options' => [],
    ],
];
