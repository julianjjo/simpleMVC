<?php

declare(strict_types=1);

namespace App\Models;

use SimpleMvc\Core\Record;

/**
 * Registro inmutable de un producto.
 *
 * Antes el `Model` devolvía objetos `stdClass` crudos de mysqli: cualquier
 * errata en `->nombre` pasaba sin avisar. Con un Record tipado, un campo
 * inexistente es un error al hidratar y el IDE puede autocompletar.
 */
final class Product extends Record
{
    public const CATEGORIES = ['perifericos', 'audio', 'monitores', 'componentes', 'almacenamiento'];

    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        'perifericos' => 'Periféricos',
        'audio' => 'Audio',
        'monitores' => 'Monitores',
        'componentes' => 'Componentes',
        'almacenamiento' => 'Almacenamiento',
        'otros' => 'Otros',
    ];

    public function __construct(
        public readonly int $id = 0,
        public readonly string $nombre = '',
        public readonly string $slug = '',
        public readonly string $descripcion = '',
        public readonly float $precio = 0.0,
        public readonly int $stock = 0,
        public readonly string $categoria = 'otros',
        public readonly bool $destacado = false,
        public readonly ?\DateTimeImmutable $creadoEn = null,
    ) {
    }

    public function categoriaEtiqueta(): string
    {
        return self::CATEGORY_LABELS[$this->categoria] ?? self::CATEGORY_LABELS['otros'];
    }

    public function hayStock(): bool
    {
        return $this->stock > 0;
    }

    public function precioFormateado(): string
    {
        return number_format($this->precio, 2, ',', '.').' COP';
    }

    /**
     * Resumen para las listas: corta en 120 caracteres sin partir palabras.
     */
    public function resumen(int $length = 120): string
    {
        return str_limit($this->descripcion, $length);
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return array_values(array_map(
            static fn (string $key): string => self::CATEGORY_LABELS[$key] ?? $key,
            self::CATEGORIES
        ));
    }
}
