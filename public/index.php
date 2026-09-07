<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Frente de control
|--------------------------------------------------------------------------
|
| Único archivo accesible por HTTP (docroot = public/). Todo lo demás está
| fuera del alcance del navegador: src/, app/, config/, storage/, .env.
|
 */

(static function (): void {
    $basePath = dirname(__DIR__);

    $composer = $basePath.'/vendor/autoload.php';
    $fallback = $basePath.'/src/Support/autoload.php';

    if (is_file($composer)) {
        require $composer;
    } elseif (is_file($fallback)) {
        // Sin Composer: el proyecto registra su propio autoloader PSR-4.
        require $fallback;
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');

        echo "No se encontró el autoloader.\n";
        echo "Ejecuta `composer install`, o verifica que exista src/Support/autoload.php\n";

        return;
    }

    \SimpleMvc\Core\App::boot($basePath)->run();
})();
