<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use Closure;
use ReflectionNamedType;
use RuntimeException;

/**
 * Contenedor de servicios con autowiring por reflexión.
 *
 * Reemplaza al antiguo singleton `Model::getModel()`: en lugar de que las
 * clases busquen sus dependencias, las reciben por el constructor, lo que
 * permite inyectar dobles/fakes en los tests.
 */
final class Container
{
    /** @var array<string, Closure> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /**
     * Registra una fábrica para `id`. Si `$shared` es true, se resuelve una sola vez.
     */
    public function bind(string $id, Closure $factory, bool $shared = false): void
    {
        $this->bindings[$id] = $factory;
        $this->shared[$id] = $shared;
        unset($this->instances[$id]);
    }

    /**
     * Registra una instancia ya construida (equivalente a `instance()` de Laravel).
     */
    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
        $this->shared[$id] = true;
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->bind($id, $factory, true);
    }

    /**
     * ¿Hay un binding o instancia explícita? A diferencia de has(), no cuenta
     * "la clase existe" como disponible.
     */
    public function bound(string $id): bool
    {
        $id = ltrim($id, '\\');

        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $id
     * @return T
     */
    public function make(string $id, array $parameters = []): mixed
    {
        $normalized = ltrim($id, '\\');

        if (isset($this->instances[$normalized])) {
            return $this->instances[$normalized];
        }

        // Guarda anti-ciclo: cubre también los bindings definidos con closures,
        // que se saltan build().
        if (isset($this->resolving[$normalized])) {
            throw new RuntimeException("Dependencia circular al resolver [{$normalized}].");
        }

        $this->resolving[$normalized] = true;

        try {
            if (isset($this->bindings[$normalized])) {
                $object = ($this->bindings[$normalized])($this, $parameters);

                if ($this->shared[$normalized] ?? false) {
                    $this->instances[$normalized] = $object;
                }

                return $object;
            }

            return $this->build($normalized, $parameters);
        } finally {
            unset($this->resolving[$normalized]);
        }
    }

    /**
     * Construye una clase resolviendo las dependencias de su constructor.
     */
    public function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("No se puede resolver [{$class}]: la clase no existe.");
        }

        $reflector = new \ReflectionClass($class);
        $constructor = $reflector->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            $object = new $class();
        } else {
            $object = $reflector->newInstanceArgs($this->resolveArguments($constructor, $parameters, $class));
        }

        if ($this->shared[$class] ?? false) {
            $this->instances[$class] = $object;
        }

        return $object;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<int, mixed>
     */
    private function resolveArguments(\ReflectionMethod $constructor, array $parameters, string $class): array
    {
        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($parameter, $parameters, $class);
        }

        return $arguments;
    }

    /**
     * Resuelve un parámetro por nombre, por tipo (autowiring) o con su default.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function resolveParameter(\ReflectionParameter $parameter, array $parameters, string $context): mixed
    {
        $name = $parameter->getName();

        if (array_key_exists($name, $parameters)) {
            return $parameters[$name];
        }

        if ($this->autowireable($parameter->getType())) {
            /** @var \ReflectionNamedType $type */
            $type = $parameter->getType();

            return $this->make($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new RuntimeException(
            "No se puede resolver el parámetro \${$name} de [{$context}]: ".
            'registre el servicio en el contenedor o agregue un valor por defecto.'
        );
    }

    /**
     * Solo se puede inyectar automáticamente lo que el contenedor sabe construir:
     * clases concretas, o interfaces que estén registradas.
     */
    private function autowireable(?\ReflectionType $type): bool
    {
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        $class = $type->getName();

        if (in_array($class, ['Closure', 'Generator'], true)) {
            return false;
        }

        if (interface_exists($class)) {
            return $this->bound($class);
        }

        if (!class_exists($class)) {
            return false;
        }

        return (new \ReflectionClass($class))->isInstantiable();
    }

    /**
     * Ejecuta un closure inyectándole servicios por tipo de parámetro.
     */
    public function call(Closure|string|array $callable, array $parameters = []): mixed
    {
        if (is_string($callable) && str_contains($callable, '::')) {
            [$class, $method] = explode('::', $callable, 2);
            $callable = [$this->make($class), $method];
        }

        if (is_array($callable) && is_string($callable[0])) {
            $callable = [$this->make($callable[0]), $callable[1]];
        }

        $reflector = is_array($callable)
            ? new \ReflectionMethod($callable[0], $callable[1])
            : new \ReflectionFunction($callable);

        $arguments = [];

        foreach ($reflector->getParameters() as $index => $parameter) {
            // Los parámetros posicionales (rutas con :id) se mapean por índice
            // cuando no coinciden por nombre.
            if (!array_key_exists($parameter->getName(), $parameters) && array_key_exists($index, $parameters)) {
                $parameters[$parameter->getName()] = $parameters[$index];
            }

            $arguments[] = $this->resolveParameter($parameter, $parameters, $this->describe($callable));
        }

        return $callable(...$arguments);
    }

    private function describe(Closure|array $callable): string
    {
        if (is_array($callable)) {
            return (is_object($callable[0]) ? $callable[0]::class : (string) $callable[0]).'::'.$callable[1];
        }

        return 'closure';
    }

    /**
     * Elimina todas las instancias compartidas. Útil en tests.
     */
    public function flush(): void
    {
        $this->instances = [];
    }
}
