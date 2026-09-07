<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shim mínimo compatible con PHPUnit
|--------------------------------------------------------------------------
|
| Define `PHPUnit\Framework\TestCase` con las aserciones que usa esta suite
| para que los tests se puedan ejecutar sin descargar dependencias (útil en
| CI sin red, contenedores embebidos o para probar el proyecto tal cual).
|
| Cuando PHPUnit está instalado (vendor/autoload.php), este archivo no hace
| nada y los tests corren con el PHPUnit de verdad.
|
 */

namespace PHPUnit\Framework;

use Throwable;

if (class_exists(TestCase::class, false) || class_exists(TestCase::class)) {
    return;
}

class AssertionFailedError extends \Exception
{
}

class SkippedTestError extends \Exception
{
}

abstract class TestCase
{
    public static int $assertionCount = 0;

    private ?string $expectedException = null;

    private ?string $expectedExceptionMessage = null;

    private ?string $expectedExceptionMessageRegex = null;

    private string $currentTest = '';

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    // -----------------------------------------------------------------
    // Soporte del runner
    // -----------------------------------------------------------------

    public function __getCurrentTest(): string
    {
        return $this->currentTest;
    }

    public function __setCurrentTest(string $name): void
    {
        $this->currentTest = $name;
    }

    public function __expectedException(): ?string
    {
        return $this->expectedException;
    }

    public function __expectedExceptionMessage(): ?string
    {
        return $this->expectedExceptionMessage;
    }

    public function __expectedExceptionMessageRegex(): ?string
    {
        return $this->expectedExceptionMessageRegex;
    }

    public function __setUp(): void
    {
        $this->setUp();
    }

    public function __tearDown(): void
    {
        $this->tearDown();
    }

    // -----------------------------------------------------------------
    // Esperar excepciones
    // -----------------------------------------------------------------

    public function expectException(string $exception): void
    {
        $this->expectedException = $exception;
    }

    public function expectExceptionMessage(string $message): void
    {
        $this->expectedExceptionMessage = $message;
    }

    public function expectExceptionMessageMatches(string $regex): void
    {
        $this->expectedExceptionMessageRegex = $regex;
    }

    // -----------------------------------------------------------------
    // Aserciones
    // -----------------------------------------------------------------

    public static function assertTrue(mixed $condition, string $message = ''): void
    {
        self::record();

        if ($condition !== true) {
            self::failWith($message ?: 'El valor esperado era true, se obtuvo '.self::export($condition).'.');
        }
    }

    public static function assertFalse(mixed $condition, string $message = ''): void
    {
        self::record();

        if ($condition !== false) {
            self::failWith($message ?: 'El valor esperado era false, se obtuvo '.self::export($condition).'.');
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::record();

        if ($expected !== $actual) {
            self::failWith($message ?: sprintf(
                'assertSame falló: esperado %s, obtenido %s.',
                self::export($expected),
                self::export($actual)
            ));
        }
    }

    public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::record();

        if ($expected === $actual) {
            self::failWith($message ?: 'assertNotSame falló: ambos valores son '.self::export($actual).'.');
        }
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::record();

        if ($expected != $actual) {
            self::failWith($message ?: sprintf(
                'assertEquals falló: esperado %s, obtenido %s.',
                self::export($expected),
                self::export($actual)
            ));
        }
    }

    public static function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::record();

        if ($expected == $actual) {
            self::failWith($message ?: 'assertNotEquals falló: ambos valores son iguales.');
        }
    }

    public static function assertNull(mixed $actual, string $message = ''): void
    {
        self::record();

        if ($actual !== null) {
            self::failWith($message ?: 'Se esperaba null, se obtuvo '.self::export($actual).'.');
        }
    }

    public static function assertNotNull(mixed $actual, string $message = ''): void
    {
        self::record();

        if ($actual === null) {
            self::failWith($message ?: 'No se esperaba null.');
        }
    }

    public static function assertCount(int $expected, mixed $haystack, string $message = ''): void
    {
        self::record();

        $actual = is_countable($haystack) ? count($haystack) : -1;

        if ($actual !== $expected) {
            self::failWith($message ?: "Se esperaban {$expected} elementos, se obtuvieron {$actual}.");
        }
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::record();

        if (!str_contains($haystack, $needle)) {
            self::failWith($message ?: "La cadena no contiene «{$needle}».\nObtenido: ".self::limitOutput($haystack));
        }
    }

    public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::record();

        if (str_contains($haystack, $needle)) {
            self::failWith($message ?: "La cadena no debería contener «{$needle}».\nObtenido: ".self::limitOutput($haystack));
        }
    }

    public static function assertMatchesRegularExpression(string $pattern, string $actual, string $message = ''): void
    {
        self::record();

        if (preg_match($pattern, $actual) !== 1) {
            self::failWith($message ?: "El valor no coincide con el patrón {$pattern}.\nObtenido: ".self::limitOutput($actual));
        }
    }

    public static function assertIsBool(mixed $actual, string $message = ''): void
    {
        self::assertSame(true, is_bool($actual), $message === '' ? 'Se esperaba un booleano.' : $message);
    }

    public static function assertIsInt(mixed $actual, string $message = ''): void
    {
        self::assertSame(true, is_int($actual), $message === '' ? 'Se esperaba un entero.' : $message);
    }

    public static function assertIsFloat(mixed $actual, string $message = ''): void
    {
        self::assertSame(true, is_float($actual), $message === '' ? 'Se esperaba un float.' : $message);
    }

    public static function assertIsString(mixed $actual, string $message = ''): void
    {
        self::assertSame(true, is_string($actual), $message === '' ? 'Se esperaba una cadena.' : $message);
    }

    public static function assertIsArray(mixed $actual, string $message = ''): void
    {
        self::assertSame(true, is_array($actual), $message === '' ? 'Se esperaba un arreglo.' : $message);
    }

    public static function assertIsObject(mixed $actual, string $message = ''): void
    {
        self::assertSame(true, is_object($actual), $message === '' ? 'Se esperaba un objeto.' : $message);
    }

    public static function assertIsCallable(mixed $actual, string $message = ''): void
    {
        self::assertSame(true, is_callable($actual), $message === '' ? 'Se esperaba un callable.' : $message);
    }

    public static function assertStringStartsWith(string $prefix, string $actual, string $message = ''): void
    {
        self::assertSame(true, str_starts_with($actual, $prefix), ($message === '' ? "La cadena no empieza por «{$prefix}»." : $message)."\nObtenido: {$actual}");
    }

    public static function assertStringEndsWith(string $suffix, string $actual, string $message = ''): void
    {
        self::assertSame(true, str_ends_with($actual, $suffix), ($message === '' ? "La cadena no termina en «{$suffix}»." : $message)."\nObtenido: {$actual}");
    }

    public static function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        self::record();

        if (!$actual instanceof $expected) {
            self::failWith($message ?: "Se esperaba una instancia de {$expected}, se obtuvo ".get_debug_type($actual).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $array
     */
    public static function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        self::record();

        if (!array_key_exists($key, $array)) {
            self::failWith($message ?: "La clave «{$key}» no existe. Claves disponibles: ".implode(', ', array_keys($array)).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $array
     */
    public static function assertArrayNotHasKey(string|int $key, array $array, string $message = ''): void
    {
        self::record();

        if (array_key_exists($key, $array)) {
            self::failWith($message ?: "La clave «{$key}» no debería existir.");
        }
    }

    public static function assertEmpty(mixed $actual, string $message = ''): void
    {
        self::record();

        if (!empty($actual)) {
            self::failWith($message ?: 'Se esperaba un valor vacío, se obtuvo '.self::export($actual).'.');
        }
    }

    public static function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        self::record();

        if (empty($actual)) {
            self::failWith($message ?: 'No se esperaba un valor vacío.');
        }
    }

    public static function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::record();

        if (!($actual > $expected)) {
            self::failWith($message ?: self::export($actual).' no es mayor que '.self::export($expected).'.');
        }
    }

    public static function assertLessThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::record();

        if (!($actual < $expected)) {
            self::failWith($message ?: self::export($actual).' no es menor que '.self::export($expected).'.');
        }
    }

    public static function assertFileExists(string $path, string $message = ''): void
    {
        self::record();

        if (!is_file($path)) {
            self::failWith($message ?: "El archivo no existe: {$path}");
        }
    }

    public static function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        self::record();

        foreach ($haystack as $item) {
            if ($item === $needle) {
                return;
            }
        }

        self::failWith($message ?: 'El arreglo no contiene '.self::export($needle).'.');
    }

    public static function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        self::record();

        foreach ($haystack as $item) {
            if ($item === $needle) {
                self::failWith($message ?: 'El arreglo no debería contener '.self::export($needle).'.');
            }
        }
    }

    public static function fail(string $message = ''): never
    {
        throw new AssertionFailedError($message === '' ? 'Fallo forzado (fail()).' : $message);
    }

    public function markTestSkipped(string $message = ''): never
    {
        throw new SkippedTestError($message === '' ? 'Prueba omitida.' : $message);
    }

    private static function record(): void
    {
        ++self::$assertionCount;
    }

    private static function failWith(string $message): never
    {
        throw new AssertionFailedError($message);
    }

    private static function export(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => self::limitOutput($value, 120),
            is_scalar($value) => (string) $value,
            is_array($value) => 'array('.count($value).')',
            is_object($value) => $value::class,
            default => get_debug_type($value),
        };
    }

    private static function limitOutput(string $value, int $max = 400): string
    {
        $value = str_replace(["\n", "\r"], ['\n', ''], $value);

        return strlen($value) <= $max ? $value : substr($value, 0, $max).'…';
    }
}
