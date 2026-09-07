<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use RuntimeException;

/**
 * Autoloader PSR-4 propio.
 *
 * El proyecto se puede usar de dos formas:
 *  1. Con Composer (`composer install`) -> se usa vendor/autoload.php.
 *  2. Sin Composer -> se incluye src/Support/autoload.php y este clase
 *     se encarga de cargar `SimpleMvc\*` desde src/ y `App\*` desde app/.
 *
 * El original hacía `require 'core/Loader.php'` (en minúsculas), lo que
 * revienta en sistemas de archivos case-sensitive como el de Linux. Aquí
 * las rutas se resuelven con __DIR__ y no dependen de mayúsculas/minúsculas
 * del sistema operativo.
 */
final class Autoloader
{
    /** @var array<string, string> prefijo de namespace => directorio base */
    private static array $prefixes = [];

    private static bool $registered = false;

    /**
     * @param  array<string, string>  $prefixes
     */
    public static function register(array $prefixes = []): void
    {
        foreach ($prefixes as $prefix => $directory) {
            self::addNamespace($prefix, $directory);
        }

        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register([self::class, 'load']);
    }

    /**
     * @param  string  $prefix  prefijo de namespace, con o sin barra final
     * @param  string  $directory  directorio absoluto
     */
    public static function addNamespace(string $prefix, string $directory): void
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        self::$prefixes[$prefix === '' ? '' : $prefix.'/'] = rtrim(str_replace('\\', '/', $directory), '/').'/';
    }

    public static function load(string $class): bool
    {
        $normalized = str_replace('\\', '/', $class);

        foreach (self::$prefixes as $prefix => $directory) {
            if ($prefix !== '' && !str_starts_with($normalized, $prefix)) {
                continue;
            }

            $relative = $prefix === '' ? $normalized : substr($normalized, strlen($prefix));
            $file = $directory.$relative.'.php';

            if (is_file($file)) {
                require $file;

                return true;
            }
        }

        return false;
    }

    /**
     * Carga un archivo solo si aún no fue cargado (equivalente a require_once
     * con paths absolutos resueltos).
     */
    public static function loadFile(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException("El archivo requerido no existe: {$file}");
        }

        require_once $file;
    }
}
