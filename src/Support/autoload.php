<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Autoloader sin Composer
|--------------------------------------------------------------------------
|
| included desde public/index.php (y tests/bootstrap.php) cuando no existe
| vendor/autoload.php. Registra los espacios de nombres PSR-4 del proyecto
| y carga las funciones auxiliares globales.
|
 */

use SimpleMvc\Core\Autoloader;

$projectRoot = dirname(__DIR__, 2);

require_once $projectRoot.'/src/Core/Autoloader.php';

Autoloader::register([
    'SimpleMvc' => $projectRoot.'/src',
    'App' => $projectRoot.'/app',
]);

require_once $projectRoot.'/src/Support/helpers.php';

unset($projectRoot);
