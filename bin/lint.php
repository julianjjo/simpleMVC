<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Linter de sintaxis
|--------------------------------------------------------------------------
|
|   php bin/lint.php            # revisa src, app, config, public, tests…
|
| Equivalente a `find . -name '*.php' -exec php -l {} \;` pero portable
| (Windows incluido) y sin dependencias. Si el entorno no permite lanzar
| subprocesos (contenedores con `exec` deshabilitado, PHP incrustado en
| otros runtime), se usa `token_get_all()` con TOKEN_PARSE, que detecta los
| mismos errores de sintaxis.
|
 */

$root = dirname(__DIR__);
$directories = ['src', 'app', 'config', 'public', 'routes', 'database', 'templates', 'tests', 'bin'];

$disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
$canSpawn = function_exists('exec') && !in_array('exec', $disabled, true) && PHP_BINARY !== '';

/** @var array<int, SplFileInfo> $files */
$files = [];

foreach ($directories as $directory) {
    $absolute = $root.'/'.$directory;

    if (!is_dir($absolute)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file;
        }
    }
}

sort($files);

$checked = 0;
$failures = [];

foreach ($files as $file) {
    ++$checked;
    $relative = str_replace($root.'/', '', $file->getPathname());

    if ($canSpawn) {
        $output = [];
        $status = 0;

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $status);

        if ($status !== 0) {
            $failures[] = $relative."\n   ".implode("\n   ", $output);
        }

        continue;
    }

    $code = (string) file_get_contents($file->getPathname());

    try {
        // TOKEN_PARSE lanza ParseError ante cualquier error de sintaxis.
        token_get_all($code, TOKEN_PARSE);
    } catch (ParseError $e) {
        $failures[] = $relative."\n   ".$e->getMessage();
    }
}

if ($failures !== []) {
    echo "\n", count($failures), " archivo(s) con errores de sintaxis:\n\n";
    echo ' - ', implode("\n - ", $failures), "\n\n";
    exit(1);
}

printf(
    "%s: %d archivos revisados, 0 errores (%s)\n",
    $canSpawn ? 'php -l' : 'token_get_all',
    $checked,
    PHP_VERSION
);

exit(0);
