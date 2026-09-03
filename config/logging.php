<?php

declare(strict_types=1);

use SimpleMvc\Support\Str;

return [
    // null / '' desactiva el archivo y deja solo los registros en memoria.
    'path' => Str::basePath((string) env('LOG_PATH', 'storage/logs/app.log')),
    'level' => env('LOG_LEVEL', 'debug'),
];
