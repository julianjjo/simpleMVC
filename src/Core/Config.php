<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use SimpleMvc\Support\Env;

/**
 * Configuración de la aplicación con acceso por notación de puntos.
 *
 * Fuente de valores: config/*.php, que a su vez lee variables de entorno.
 */
final class Config
{
    /**
     * @param  array<string, mixed>  $items
     */
    public function __construct(private array $items = [])
    {
    }

    public static function load(string $basePath, ?string $envFile = null): self
    {
        Env::load($envFile ?? $basePath.'/.env');

        $items = [];

        foreach (glob($basePath.'/config/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }

        return new self($items);
    }

    /**
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];

                continue;
            }

            return $default;
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $target[$segment] = $value;

                return;
            }

            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__missing__') !== '__missing__';
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function isDebug(): bool
    {
        return (bool) $this->get('app.debug', false);
    }

    public function environment(): string
    {
        return (string) $this->get('app.env', 'dev');
    }
}
