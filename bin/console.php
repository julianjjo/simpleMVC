#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Consola
|--------------------------------------------------------------------------
|
|   php bin/console.php <comando> [opciones]
|
| Comandos: setup | migrate | seed | routes | serve | ping | help
|
 */

use SimpleMvc\Core\App;
use SimpleMvc\Core\Config;
use SimpleMvc\Core\Database;

$basePath = dirname(__DIR__);

(static function (string $basePath): void {
    $composer = $basePath.'/vendor/autoload.php';

    if (is_file($composer)) {
        require $composer;

        return;
    }

    require $basePath.'/src/Support/autoload.php';
})($basePath);

/**
 * @param  array<int, string>  $argv
 * @return array{0: ?string, 1: array<string, string|bool>}
 */
function parse_args(array $argv): array
{
    $command = null;
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
            $options[$key] = $value;

            continue;
        }

        $command ??= $arg;
    }

    return [$command, $options];
}

function is_tty(): bool
{
    if (!defined('STDOUT') || !function_exists('stream_isatty')) {
        return false;
    }

    return @stream_isatty(STDOUT);
}

function paint(string $text, string $color): string
{
    if (!is_tty()) {
        return $text;
    }

    $codes = ['gray' => '90', 'red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '36', 'bold' => '1'];

    return "\033[".($codes[$color] ?? '0').'m'.$text."\033[0m";
}

function say(string $text, string $color = ''): void
{
    echo ($color === '' ? $text : paint($text, $color)), "\n";
}

function heading(string $text): void
{
    echo "\n", paint($text, 'bold'), "\n", paint(str_repeat('─', max(strlen($text), 24)), 'gray'), "\n";
}

[$command, $options] = parse_args($_SERVER['argv'] ?? $argv ?? []);

$usage = <<<'TXT'
simpleMVC — consola de desarrollo

  php bin/console.php setup          Crea el esquema y carga los datos de ejemplo
  php bin/console.php migrate        Aplica database/schema.<driver>.sql
  php bin/console.php seed           Inserta los productos de demo (--force para resembrar)
  php bin/console.php routes         Lista las rutas registradas
  php bin/console.php ping           Comprueba la conexión a la base de datos
  php bin/console.php serve          Levanta php -S en public/ (--port=8000 --host=127.0.0.1)
  php bin/console.php help           Esta ayuda

  Configuración: copia .env.example a .env (por defecto se usa SQLite en storage/db/app.sqlite).
TXT;

if ($command === null || $command === 'help' || isset($options['help'])) {
    say($usage);
    exit(0);
}

if ($command === 'serve') {
    $host = (string) ($options['host'] ?? '127.0.0.1');
    $port = (string) ($options['port'] ?? '8000');
    $php = (string) ($options['php'] ?? PHP_BINARY);

    say("Servidor en http://{$host}:{$port} (Ctrl+C para detener)", 'blue');

    $commandLine = sprintf(
        '%s -S %s:%s -t %s %s',
        escapeshellarg($php),
        $host,
        $port,
        escapeshellarg($basePath.'/public'),
        escapeshellarg($basePath.'/public/router.php')
    );

    exit((int) @passthru($commandLine, $status));
}

$app = App::boot($basePath);

/** @var Config $config */
$config = $app->make(Config::class);
/** @var Database $db */
$db = $app->make(Database::class);

function schemaFile(Config $config, string $basePath): string
{
    $driver = $config->get('database.driver', 'sqlite');
    $name = in_array($driver, ['mysql', 'mariadb'], true) ? 'mysql' : 'sqlite';
    $path = $basePath.'/database/schema.'.$name.'.sql';

    if (!is_file($path)) {
        throw new RuntimeException("Falta el archivo de esquema: {$path}");
    }

    return $path;
}

try {
    switch ($command) {
        case 'ping':
            $version = $db->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            say('Conectado a '.$db->driver().' — servidor '.$version, 'green');
            say('Archivo/BD: '.(string) ($config->get('database.sqlite.path') ?? $config->get('database.mysql.database')), 'gray');
            break;

        case 'migrate':
            $path = schemaFile($config, $basePath);
            $db->runSqlFile($path);
            say('Esquema aplicado desde '.basename($path).' ('.$db->driver().')', 'green');
            break;

        case 'seed':
            $seeder = require $basePath.'/database/seeds.php';
            $inserted = $seeder($db, isset($options['force']));
            say($inserted > 0
                ? "Sembrados {$inserted} productos."
                : 'Ya había datos; usa --force para resembrar.', $inserted > 0 ? 'green' : 'yellow');
            break;

        case 'setup':
            $db->runSqlFile(schemaFile($config, $basePath));
            say('Esquema aplicado ('.$db->driver().')', 'green');

            $seeder = require $basePath.'/database/seeds.php';
            $inserted = $seeder($db, isset($options['force']));
            say($inserted > 0 ? "Datos de ejemplo: {$inserted} productos." : 'La tabla ya tenía datos (nada que sembrar).', 'green');

            say("\nAbre la demo:", 'bold');
            say('  composer dev    ó    php bin/console.php serve', 'blue');
            break;

        case 'routes':
            heading('Rutas registradas');
            printf("%-24s %-46s %-22s %s\n", paint('MÉTODO', 'gray'), paint('URI', 'gray'), paint('NOMBRE', 'gray'), paint('ACCIÓN', 'gray'));

            foreach ($app->router()->routes() as $route) {
                $action = $route->action();

                $description = match (true) {
                    is_array($action) => (is_object($action[0]) ? $action[0]::class : (string) $action[0]).'::'.$action[1],
                    is_string($action) => $action,
                    default => 'closure',
                };

                printf(
                    "%-24s %-46s %-22s %s\n",
                    implode('|', $route->allowedMethods()),
                    $route->uri(),
                    $route->getName() ?? '—',
                    $description
                );
            }

            echo "\n", paint('  Total: '.count($app->router()->routes()).' rutas', 'gray'), "\n";
            break;

        default:
            say("Comando desconocido: {$command}", 'red');
            say($usage);
            exit(1);
    }
} catch (Throwable $e) {
    say('  ✗ '.$e::class.': '.$e->getMessage(), 'red');

    if ($config->isDebug()) {
        say(paint($e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString(), 'gray'));
    } else {
        say(paint('Activa APP_DEBUG=true para ver la traza.', 'gray'));
    }

    exit(1);
}

exit(0);
