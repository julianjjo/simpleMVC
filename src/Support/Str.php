<?php

declare(strict_types=1);

namespace SimpleMvc\Support;

/**
 * Utilidades de cadenas y rutas. Sin dependencias externas.
 */
final class Str
{
    private function __construct()
    {
        // Clase estática: no se instancia.
    }

    /**
     * Resuelve una ruta relativa al directorio raíz del proyecto
     * (el padre de src/, public/, app/...).
     */
    public static function basePath(string $path = ''): string
    {
        $root = dirname(__DIR__, 2);

        if ($path === '') {
            return $root;
        }

        return $root.'/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Convierte una cadena en un slug seguro para URLs.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        $separator = $separator === '' ? '-' : $separator;

        // Transliteración básica de vocales acentuadas (el proyecto
        // original trabaja con datos en español).
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'A', 'E', 'I', 'O', 'U', 'U', 'N'],
            $value
        );

        $value = Str::lower($value);
        $value = preg_replace('/[^a-z0-9]+/', $separator, $value) ?? '';

        return trim($value, $separator);
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * Recorta una cadena en un límite de caracteres, sin cortar palabras.
     */
    public static function limit(string $value, int $limit = 100, string $end = '…'): string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        if ($length <= $limit) {
            return $value;
        }

        $cut = function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
        $lastSpace = strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > (int) ($limit * 0.6)) {
            $cut = substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r,.;:").$end;
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    public static function snake(string $value, string $delimiter = '_'): string
    {
        $value = preg_replace('/([a-z\d])([A-Z])/', '$1'.$delimiter.'$2', $value) ?? $value;

        return strtolower(str_replace(['-', ' '], $delimiter, $value));
    }

    /**
     * Normaliza separadores de directorio y elimina elslash final.
     */
    public static function normalizePath(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return $path === '' ? '/' : $path;
    }
}
