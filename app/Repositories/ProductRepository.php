<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Product;
use SimpleMvc\Core\Database;
use SimpleMvc\Core\Paginator;
use SimpleMvc\Core\QueryBuilder;
use SimpleMvc\Support\Str;

/**
 * Acceso a datos de `productos`.
 *
 * Toda consulta usa QueryBuilder (identificadores citados + valores como
 * bindings), de modo que la búsqueda del usuario nunca se concatena en el SQL:
 * el `SELECT * FROM productos` del ejemplo original no tenía ni WHERE, y la
 * versión "completa" de ese tutorial solía escribir `"... WHERE id = ".$_GET['id']`,
 * que es inyectable.
 */
final class ProductRepository
{
    public const TABLE = 'productos';

    /** @var string[] */
    public const SORTS = ['nombre', 'precio', 'stock', 'creado_en'];

    public function __construct(private Database $db)
    {
    }

    public function query(): QueryBuilder
    {
        return $this->db->table(self::TABLE);
    }

    /**
     * @return array<int, Product>
     */
    public function all(int $limit = 500): array
    {
        return $this->hydrate($this->query()->orderBy('nombre')->limit($limit)->get());
    }

    public function find(int $id): ?Product
    {
        $row = $this->query()->where('id', $id)->first();

        return $row === null ? null : Product::fromRow($row);
    }

    public function findBySlug(string $slug): ?Product
    {
        // Se acepta también la forma sin normalizar: los datos de la versión
        // antigua del tutorial tienen slugs con espacios.
        $candidates = array_values(array_unique(array_filter([
            $slug,
            \SimpleMvc\Support\Str::slug($slug),
        ], static fn (string $value): bool => $value !== '')));

        $row = $this->query()->whereIn('slug', $candidates)->first();

        return $row === null ? null : Product::fromRow($row);
    }

    public function findOrFail(int $id): Product
    {
        return $this->find($id) ?? throw new \SimpleMvc\Exceptions\NotFoundHttpException(
            "No hay ningún producto con id {$id}."
        );
    }

    /**
     * Listado con búsqueda, filtro por categoría, orden y paginación.
     *
     * @param  array<string, int|string>  $appends  query params a conservar en los enlaces
     */
    public function searchFiltered(
        ?string $term = null,
        ?string $category = null,
        ?string $sort = null,
        ?string $direction = null,
        int $page = 1,
        int $perPage = 9,
        bool $onlyAvailable = false,
        array $appends = []
    ): Paginator {
        $query = $this->query();

        if ($term !== null && $term !== '') {
            $query->whereAnyOf(['nombre', 'descripcion', 'categoria'], $term);
        }

        if ($category !== null && $category !== '') {
            $query->where('categoria', $category);
        }

        if ($onlyAvailable) {
            $query->where('stock', '>', 0);
        }

        $sort = in_array((string) $sort, self::SORTS, true) ? (string) $sort : 'nombre';
        $query->orderBy($sort, strtolower((string) $direction) === 'desc' ? 'desc' : 'asc');

        $total = $query->count();
        $items = $this->hydrate($query->forPage($page, $perPage)->get());

        return new Paginator($items, $total, $perPage, $page, $appends);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * @return array<int, Product>
     */
    public function featured(int $limit = 4): array
    {
        return $this->hydrate(
            $this->query()->where('destacado', 1)->orderBy('precio', 'desc')->limit($limit)->get()
        );
    }

    /**
     * @return array<string, int>  categoria => cantidad
     */
    public function categoriesWithCounts(): array
    {
        $rows = $this->query()
            ->selectRaw('categoria, COUNT(*) AS total')
            ->groupBy('categoria')
            ->orderByRaw('categoria ASC')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $key = (string) ($row['categoria'] ?? 'otros');
            $result[$key] = (int) ($row['total'] ?? 0);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        $values = $this->persistable($data);
        $values['slug'] = $this->uniqueSlug((string) ($values['slug'] ?? $values['nombre'] ?? ''));
        $values['creado_en'] = $values['creado_en'] ?? date('Y-m-d H:i:s');

        $this->query()->insert($values);
        $created = $this->find((int) $this->db->lastInsertId());

        if ($created === null) {
            throw new \RuntimeException('El producto se insertó pero no se pudo volver a leer.');
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ?Product
    {
        $values = $this->persistable($data);

        if ($values === []) {
            return $this->find($id);
        }

        if (isset($values['slug'])) {
            $values['slug'] = $this->uniqueSlug((string) $values['slug'], $id);
        }

        $this->query()->where('id', $id)->update($values);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        return $this->query()->where('id', $id)->delete() > 0;
    }

    /**
     * Solo columnas conocidas: evita que un campo extra del formulario
     * (p. ej. `id` o `destacado` inyectados) llegue al UPDATE.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function persistable(array $data): array
    {
        $allowed = ['nombre', 'slug', 'descripcion', 'precio', 'stock', 'categoria', 'destacado', 'creado_en'];
        $values = [];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $value = $data[$column];

            $values[$column] = match ($column) {
                'precio', 'stock' => (float) str_replace(',', '.', (string) $value),
                'destacado' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                default => is_string($value) ? trim($value) : $value,
            };
        }

        if (isset($values['stock'])) {
            $values['stock'] = (int) $values['stock'];
        }

        if (($values['slug'] ?? '') === '' && ($values['nombre'] ?? '') !== '') {
            $values['slug'] = Str::slug((string) $values['nombre']);
        }

        return $values;
    }

    /**
     * Slug único: si "teclado" existe, se genera "teclado-2", "teclado-3", ...
     */
    public function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base);

        if ($slug === '') {
            $slug = 'producto';
        }

        $candidate = $slug;
        $suffix = 2;

        while (true) {
            $query = $this->query()->where('slug', $candidate);

            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }

            if (!$query->exists()) {
                return $candidate;
            }

            $candidate = $slug.'-'.$suffix++;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, Product>
     */
    private function hydrate(array $rows): array
    {
        return array_map(static fn (array $row): Product => Product::fromRow($row), $rows);
    }
}
