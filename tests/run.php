<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ejecutor de pruebas sin dependencias
|--------------------------------------------------------------------------
|
|   php tests/run.php              # usa PHPUnit si vendor/ existe
|   php tests/run.php --shim       # fuerza el runner propio (sin Composer)
|   php tests/run.php --filter=Router
|
| Los mismos archivos de test sirven para ambos modos: el shim de
| tests/Support/PhpUnitShim.php implementa la API de TestCase que se usa aquí.
|
 */

require __DIR__.'/bootstrap.php';

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTestError;
use PHPUnit\Framework\TestCase;

// Runtimes embebidos (incrustaciones de PHP, workers) no definen STDOUT/STDERR.
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://output', 'w'));
}

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

$argvLocal = $_SERVER['argv'] ?? [];
$forceShim = in_array('--shim', $argvLocal, true);
$filter = null;

foreach ($argvLocal as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    }
}

$root = dirname(__DIR__);
$phpunit = $root.'/vendor/bin/phpunit';

// Con PHPUnit instalado, delegar (salvo que se pida explícitamente el runner propio).
if (!$forceShim && is_file($phpunit) && function_exists('passthru')) {
    $color = str_contains(implode(' ', $argvLocal), '--no-colors') ? '' : '--colors=auto';
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($phpunit).' '.$color.($filter ? ' --filter='.escapeshellarg($filter) : '');

    fwrite(STDOUT, "Delegando en PHPUnit: php {$command}\n");
    passthru($command, $status);

    exit((int) $status);
}

/**
 * @return array<int, class-string<TestCase>>
 */
function discover_tests(string $directory, ?string $filter): array
{
    $classes = [];
    $files = glob($directory.'/*Test.php') ?: [];

    foreach ($files as $file) {
        require_once $file;
    }

    foreach (get_declared_classes() as $class) {
        if (!is_subclass_of($class, TestCase::class)) {
            continue;
        }

        if ($filter !== null && stripos($class, (string) $filter) === false) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

function find_test_methods(string $class): array
{
    $methods = [];

    foreach ((new ReflectionClass($class))->getMethods() as $method) {
        if ($method->isPublic() && !str_starts_with($method->getName(), '__') && (
            str_starts_with($method->getName(), 'test') || str_starts_with($method->getName(), 'test_')
        )) {
            $methods[] = $method->getName();
        }
    }

    return $methods;
}

function colorize(string $text, string $color, bool $enabled): string
{
    if (!$enabled) {
        return $text;
    }

    return "\033[{$color}m{$text}\033[0m";
}

$start = microtime(true);
$useColors = function_exists('stream_isatty') && defined('STDOUT') && @stream_isatty(STDOUT);

$directory = __DIR__;
$classes = discover_tests($directory, $filter);

$passed = 0;
$failed = 0;
$skipped = 0;
$failures = [];
$skippedList = [];
$currentAssertions = 0;

fwrite(STDOUT, "\nsimpleMVC — suite de pruebas\n");
fwrite(STDOUT, colorize('PHP '.PHP_VERSION.' · '.count($classes).' clases de prueba', '0;90', $useColors)."\n\n");

foreach ($classes as $class) {
    $shortName = (new ReflectionClass($class))->getShortName();
    fwrite(STDOUT, colorize($shortName, '1', $useColors).' ');

    foreach (find_test_methods($class) as $method) {
        $instance = new $class();
        $instance->__setCurrentTest($method);

        if (method_exists($instance, 'setAssertCount')) {
            $instance->setAssertCount(0);
        }

        $before = TestCase::$assertionCount ?? 0;
        $status = 'pass';
        $error = null;

        try {
            $instance->__setUp();
            $instance->{$method}();

            $expected = $instance->__expectedException();

            if ($expected !== null) {
                $status = 'fail';
                $error = new AssertionFailedError("Se esperaba la excepción {$expected} y no fue lanzada.");
            }
        } catch (SkippedTestError $e) {
            $status = 'skipped';
            $error = $e;
        } catch (Throwable $e) {
            $expected = $instance->__expectedException();
            $matches = $expected !== null && ($e instanceof $expected);

            if ($matches) {
                $message = $instance->__expectedExceptionMessage();
                $regex = $instance->__expectedExceptionMessageRegex();

                if ($message !== null && !str_contains($e->getMessage(), $message)) {
                    $status = 'fail';
                    $error = new AssertionFailedError(
                        "El mensaje de la excepción no contiene «{$message}» (obtenido: «{$e->getMessage()}»)."
                    );
                } elseif ($regex !== null && preg_match($regex, $e->getMessage()) !== 1) {
                    $status = 'fail';
                    $error = new AssertionFailedError(
                        "El mensaje de la excepción no coincide con {$regex} (obtenido: «{$e->getMessage()}»)."
                    );
                } else {
                    $status = 'pass';
                }
            } else {
                $status = 'fail';
                $error = $e;
            }
        }

        try {
            $instance->__tearDown();
        } catch (Throwable $e) {
            if ($status === 'pass') {
                $status = 'fail';
                $error = $e;
            }
        }

        $currentAssertions += max(0, (TestCase::$assertionCount ?? 0) - $before);

        if ($status === 'pass') {
            ++$passed;
            fwrite(STDOUT, colorize('.', '0;32', $useColors));
        } elseif ($status === 'skipped') {
            ++$skipped;
            $skippedList[] = $shortName.'::'.$method.' — '.$error->getMessage();
            fwrite(STDOUT, colorize('S', '0;33', $useColors));
        } else {
            ++$failed;
            $failures[] = [
                'test' => $shortName.'::'.$method,
                'type' => $error::class,
                'message' => $error->getMessage(),
                'file' => $error->getFile().':'.$error->getLine(),
                'trace' => $error instanceof AssertionFailedError ? [] : array_slice(explode("\n", $error->getTraceAsString()), 0, 6),
            ];
            fwrite(STDOUT, colorize('F', '0;31', $useColors));
        }
    }

    fwrite(STDOUT, "\n");
}

$elapsed = round((microtime(true) - $start) * 1000);

if ($failures !== []) {
    fwrite(STDOUT, "\n".colorize('Fallos', '0;31;1', $useColors)."\n\n");

    foreach ($failures as $i => $failure) {
        fwrite(STDOUT, sprintf("  %d) %s\n     %s: %s\n     en %s\n", $i + 1, $failure['test'], $failure['type'], $failure['message'], $failure['file']));

        foreach ($failure['trace'] as $frame) {
            fwrite(STDOUT, '     '.trim($frame)."\n");
        }

        fwrite(STDOUT, "\n");
    }
}

if ($skippedList !== []) {
    fwrite(STDOUT, colorize('Omitidas', '0;33;1', $useColors)."\n\n");

    foreach ($skippedList as $line) {
        fwrite(STDOUT, '  '.$line."\n");
    }

    fwrite(STDOUT, "\n");
}

$total = $passed + $failed + $skipped;
$summary = sprintf(
    'Tests: %d, Aserciones: %d, Fallos: %d, Omitidas: %d (%s s)',
    $total,
    $currentAssertions,
    $failed,
    $skipped,
    number_format($elapsed / 1000, 3)
);

fwrite(STDOUT, str_repeat('─', 78)."\n");
fwrite(STDOUT, colorize($summary, $failed === 0 ? '0;32;1' : '0;31;1', $useColors)."\n");
fwrite(STDOUT, $failed === 0 ? colorize("OK ({$passed} pruebas en verde)\n", '0;32', $useColors) : colorize("FAILURES\n", '0;31', $useColors));

exit($failed === 0 ? 0 : 1);
