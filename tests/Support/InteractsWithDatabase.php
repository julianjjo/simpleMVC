<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use SimpleMvc\Core\Config;
use SimpleMvc\Core\Database;

/**
 * Base de datos SQLite en memoria con el esquema del proyecto.
 */
trait InteractsWithDatabase
{
    protected function sqliteConfig(array $extra = []): Config
    {
        return new Config([
            'app' => [
                'debug' => true,
                'env' => 'testing',
                'url' => 'http://localhost',
                'base_path' => '',
                'public_prefix' => '',
                'views' => ['layout' => 'layout'],
                'paths' => ['views' => dirname(__DIR__, 2).'/templates'],
            ],
            'database' => [
                'driver' => 'sqlite',
                'sqlite' => ['path' => ':memory:'],
                'mysql' => [],
            ],
            'logging' => ['path' => '', 'level' => 'debug'],
            'session' => ['name' => 'testing', 'lifetime' => 60],
        ] + $extra);
    }

    protected function sqliteDatabase(): Database
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('Esta prueba necesita la extensión pdo_sqlite.');
        }

        $db = new Database($this->sqliteConfig());
        $db->runSqlFile(dirname(__DIR__, 2).'/database/schema.sqlite.sql');

        return $db;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: float, 3: int, 4: string}>  $rows
     */
    protected function seedProducts(Database $db, array $rows): void
    {
        foreach ($rows as $index => [$nombre, $categoria, $precio, $stock, $destacado]) {
            $db->table('productos')->insert([
                'nombre' => $nombre,
                'slug' => \SimpleMvc\Support\Str::slug($nombre) ?: 'producto-'.$index,
                'descripcion' => 'Descripción de '.$nombre,
                'precio' => $precio,
                'stock' => $stock,
                'categoria' => $categoria,
                'destacado' => $destacado === 'si' ? 1 : 0,
                'creado_en' => date('Y-m-d H:i:s', strtotime('-'.($index + 1).' days')),
            ]);
        }
    }
}
