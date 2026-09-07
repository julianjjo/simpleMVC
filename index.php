<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Compatibilidad con el despliegue antiguo
|--------------------------------------------------------------------------
|
| En la versión original el frente de control estaba en la raíz
| (`index.php` + `Core/Loader.php`). Ahora vive en public/index.php, que es lo
| que debería apuntar el docroot. Este archivo deja funcionando los
| despliegues viejos que apuntan a la raíz del repositorio.
|
| Se puede borrar cuando el servidor apunte a public/.
|
 */

// Para que la detección del prefijo de assets vea que el docroot es la raíz.
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/public/index.php';
$_SERVER['SCRIPT_NAME'] = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');

require __DIR__.'/public/index.php';
