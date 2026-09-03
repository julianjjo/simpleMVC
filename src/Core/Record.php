<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use RuntimeException;

/**
 * Base para registros inmutables (DTOs) hidratados desde una fila.
 *
 * El ejemplo original devolvía `stdClass` crudos desde mysqli, de modo que una
 * errata en el nombre de una columna pasaba desapercibida hasta runtime. Aquí
 * cada modelo declara sus tipos y `fromRow()` falla pronto si falta un campo
 * obligatorio.
 */
abstract class Record implements JsonSerializable
{
    /**
     * Construye el registro a partir de una fila de la base de datos.
     * Acepta claves snake_case para propiedades camelCase (creado_en => creadoEn).
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): static
    {
        $reflection = new \ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new static();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $key = self::matchKey($row, $name);

            if ($key === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'El registro %s requiere el campo "%s" y la fila no lo trae (%s).',
                    static::class,
                    $name,
                    implode(', ', array_keys($row))
                ));
            }

            $arguments[$name] = self::castValue(
                $row[$key],
                $parameter->getType(),
                $parameter->allowsNull(),
                $name,
                static::class
            );
        }

        /** @var static */
        return new static(...$arguments);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function matchKey(array $row, string $property): ?string
    {
        if (array_key_exists($property, $row)) {
            return $property;
        }

        $snake = strtolower(preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $property) ?? $property);

        return array_key_exists($snake, $row) ? $snake : null;
    }

    private static function castValue(
        mixed $value,
        ?\ReflectionType $type,
        bool $nullable,
        string $property,
        string $class
    ): mixed {
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        if ($value === null || (is_string($value) && $value === '')) {
            if ($nullable || $type->allowsNull()) {
                return null;
            }

            return match ($typeName) {
                'string' => '',
                'int' => 0,
                'float' => 0.0,
                'bool' => false,
                'array' => [],
                default => throw new RuntimeException(sprintf(
                    '%s::$%s no admite null y la base de datos devolvió vacío.',
                    $class,
                    $property
                )),
            };
        }

        return match ($typeName) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: ''),
            'array' => is_array($value) ? $value : (array) (json_decode((string) $value, true) ?? []),
            DateTimeImmutable::class, DateTimeInterface::class => $value instanceof DateTimeInterface
                ? ($typeName === DateTimeImmutable::class ? DateTimeImmutable::createFromInterface($value) : $value)
                : (self::parseDate((string) $value) ?? throw new RuntimeException(sprintf(
                    '%s::$%s no es una fecha válida: %s',
                    $class,
                    $property,
                    (string) $value
                ))),
            default => $value,
        };
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ((new \ReflectionObject($this))->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            $result[$property->getName()] = self::export($property->getValue($this));
        }

        return $result;
    }

    private static function export(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof self) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::export($item), $value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Acceso tipo arreglo, útil en vistas que todavía usan `$producto['nombre']`.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return property_exists($this, $key) ? $this->{$key} : $default;
    }

    public function has(string $key): bool
    {
        return property_exists($this, $key);
    }
}
