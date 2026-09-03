<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Arranque de las pruebas
|--------------------------------------------------------------------------
|
| 1. Si existe vendor/autoload.php, se usa Composer (y PHPUnit real).
| 2. Si no, se activa el autoloader propio y el shim compatible con PHPUnit,
|    de modo que `php tests/run.php` funcione en cualquier PHP 8.2+ sin
|    instalar nada. Los mismos archivos de test corren en los dos modos.
|
 */

$root = dirname(__DIR__);

$composer = $root.'/vendor/autoload.php';

if (is_file($composer)) {
    require $composer;
} else {
    require $root.'/src/Support/autoload.php';

    \SimpleMvc\Core\Autoloader::addNamespace('Tests', $root.'/tests');
}

// El shim SOLO se carga cuando PHPUnit no está disponible: si no, sus clases
// (una versión incompleta de TestCase) ganarían la partida y PHPUnit reventaría
// al llamar a métodos que no existen (setBackupGlobals()).
if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    require_once $root.'/tests/Support/PhpUnitShim.php';
}

// Las pruebas no deben escribir en el log real del proyecto.
putenv('LOG_PATH=');
$_ENV['LOG_PATH'] = '';
$_SERVER['LOG_PATH'] = '';
