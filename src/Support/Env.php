<?php

declare(strict_types=1);

namespace SimpleMvc\Support;

/**
 * Lector mínimo de archivos .env.
 *
 * Reglas:
 *  - `Clave=valor`, líneas vacías y comentarios (`#`) se ignoran.
 *  - Los valores pueden ir entre comillas simples o dobles.
 *  - No se pisan variables de entorno ya definidas: el entorno real del
 *    servidor siempre gana sobre el archivo .env.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $repository = [];

    private static bool $loaded = false;

    private function __construct()
    {
        // Solo métodos estáticos.
    }

    /**
     * Carga un archivo .env. Devuelve la cantidad de variables incorporadas.
     */
    public static function load(?string $file, bool $override = false): int
    {
        self::initRepository();

        if ($file === null || !is_file($file) || !is_readable($file)) {
            self::$loaded = true;

            return 0;
        }

        $added = 0;

        foreach (self::parse((string) file_get_contents($file)) as $key => $value) {
            self::$repository[$key] = $value;

            if ($override || !self::hasRealEnvVariable($key)) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }

            ++$added;
        }

        self::$loaded = true;

        return $added;
    }

    /**
     * Convierte el contenido de un .env en un arreglo clave => valor.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $result = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            $line = preg_replace('/^export\s+/', '', $line) ?? $line;

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if ($key === '' || preg_match('/^[A-Z0-9_.]+$/i', $key) !== 1) {
                continue;
            }

            $result[$key] = self::readValue(trim($value));
        }

        return $result;
    }

    private static function readValue(string $value): string
    {
        // Quita comentarios de línea solo fuera de comillas.
        if (!str_starts_with($value, '"') && !str_starts_with($value, "'")) {
            $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
        }

        $quote = $value[0] ?? '';

        if(($quote === '"' || $quote === "'") && str_ends_with($value, $quote) && strlen($value) > 1) {
            $inner = substr($value, 1, -1);

            if ($quote === '"') {
                $inner = str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $inner);
            }

            return $inner;
        }

        return $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::initRepository();

        if (isset($_ENV[$key])) {
            return self::cast($_ENV[$key]);
        }

        if (isset($_SERVER[$key])) {
            return self::cast($_SERVER[$key]);
        }

        $value = getenv($key);

        return $value === false ? $default : self::cast($value);
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return $value === null ? $default : (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower((string) $value), ['false', 'off', 'no', '0', ''], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Normaliza las cadenas "true"/"false"/"null" vacías típicas de un .env.
     */
    public static function cast(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            '' => null,
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty' => '',
            default => $value,
        };
    }

    /**
     * Reinicia el estado interno. Solo para pruebas.
     */
    public static function reset(): void
    {
        self::$repository = [];
        self::$loaded = false;
    }

    private static function initRepository(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;
    }

    private static function hasRealEnvVariable(string $key): bool
    {
        return getenv($key) !== false || isset($_SERVER[$key]) || isset($_ENV[$key]);
    }
}
