<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use RuntimeException;
use SimpleMvc\Exceptions\NotFoundHttpException;

/**
 * Constructor de SELECT/UPDATE/DELETE con parámetros vinculados.
 *
 * Nunca interpola valores dentro del SQL: los identificadores se validan y
 * las variables viajan siempre como bindings.
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'ILIKE'];

    /** @var array<int, string> */
    private array $columns = ['*'];

    /** @var array<int, array{0: string, 1: string, 2: ?string}> */
    private array $wheres = [];

    /** @var array<int, mixed> */
    private array $bindings = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $orders = [];

    /** @var array<int, string> */
    private array $groups = [];

    private bool $rawColumns = false;

    private ?int $limit = null;

    private ?int $offset = null;

    /**
     * @param  string  $table  nombre de tabla (o "tabla alias" no soportado a propósito)
     */
    public function __construct(private Database $db, private string $table)
    {
    }

    /**
     * @return array<string, QueryBuilder>
     */
    public static function for(Database $db, string ...$tables): array
    {
        $result = [];

        foreach ($tables as $table) {
            $result[$table] = new self($db, $table);
        }

        return $result;
    }

    public function select(string ...$columns): self
    {
        $this->columns = $columns === [] ? ['*'] : $columns;

        return $this;
    }

    /**
     * Expresión SELECT escrita a mano (agregados, `categoria, COUNT(*) AS total`).
     * Solo admite caracteres seguros; los valores deben ir en $bindings.
     *
     * @param  array<int, mixed>  $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        self::guardRaw($expression);

        $this->columns = [$expression];
        $this->rawColumns = true;
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * Varias expresiones SELECT escritas a mano.
     *
     * @param  array<int, string>  $columns
     */
    public function selectRawColumns(array $columns): self
    {
        if ($columns === []) {
            throw new RuntimeException('selectRawColumns() recibió una lista vacía.');
        }

        foreach ($columns as $column) {
            self::guardRaw($column);
        }

        $this->columns = array_values($columns);
        $this->rawColumns = true;

        return $this;
    }

    /**
     * Las expresiones crudas no admiten comillas, backticks, punto y coma ni
     * barras: solo agregados y nombres de columna.
     */
    private static function guardRaw(string $expression): void
    {
        if (trim($expression) === '') {
            throw new RuntimeException('Expresión SQL vacía.');
        }

        if (strpbrk($expression, ";'\"`\\") !== false) {
            throw new RuntimeException('Expresión SQL no permitida: '.$expression);
        }
    }

    public function clone(): self
    {
        return clone $this;
    }

    // -----------------------------------------------------------------
    // WHERE
    // -----------------------------------------------------------------

    /**
     * where('stock', 0) | where('precio', '>=', 100) | where('precio', 'between', [10, 50])
     */
    public function where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): self
    {
        return $this->addWhere(func_num_args(), $column, $operator, $value, $boolean);
    }

    /**
     * @param  int  $argc  argumentos recibidos: 2 significa where(col, valor)
     */
    private function addWhere(int $argc, string $column, mixed $operator, mixed $value, string $boolean): self
    {
        if ($argc === 2) {
            $value = $operator;
            $operator = '=';
        }

        /** @var string $operator */
        $operator = strtoupper((string) $operator);

        if ($operator === 'BETWEEN' && is_array($value)) {
            return $this->whereBetween($column, $value, $boolean);
        }

        if ($value === null && $operator === '=') {
            return $this->whereNull($column, $boolean);
        }

        if ($value === null && $operator === '!=') {
            return $this->whereNotNull($column, $boolean);
        }

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new RuntimeException("Operador no permitido en where(): {$operator}");
        }

        $this->wheres[] = [$boolean, $this->db->quoteIdent($column).' '.$operator.' ?', $column];
        $this->bindings[] = $value;

        return $this;
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere(func_num_args(), $column, $operator, $value, 'OR');
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, $this->db->quoteIdent($column).' IS NULL', $column];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, $this->db->quoteIdent($column).' IS NOT NULL', $column];

        return $this;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    public function whereIn(string $column, array $values, bool $not = false, string $boolean = 'AND'): self
    {
        $values = array_values($values);

        if ($values === []) {
            // `IN ()` es sintaxis inválida en MySQL: se resuelve con una
            // condición constante (falsa, o verdadera si es NOT IN).
            $this->wheres[] = [$boolean, $not ? '1 = 1' : '1 = 0', $column];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = [
            $boolean,
            $this->db->quoteIdent($column).' '.($not ? 'NOT IN' : 'IN').' ('.$placeholders.')',
            $column,
        ];

        foreach ($values as $value) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    /**
     * @param  array<int, mixed>|array{0: mixed, 1: mixed}  $range
     */
    public function whereBetween(string $column, array $range, string $boolean = 'AND', bool $not = false): self
    {
        $range = array_values($range);

        if (count($range) !== 2) {
            throw new RuntimeException('whereBetween() espera [minimo, maximo].');
        }

        $this->wheres[] = [
            $boolean,
            $this->db->quoteIdent($column).' '.($not ? 'NOT BETWEEN' : 'BETWEEN').' ? AND ?',
            $column,
        ];
        $this->bindings[] = $range[0];
        $this->bindings[] = $range[1];

        return $this;
    }

    /**
     * LIKE con los comodines escapados: el usuario no puede convertir su
     * búsqueda en un patrón `%`.
     */
    public function whereContains(string $column, string $term, string $boolean = 'AND'): self
    {
        return $this->whereAnyOf([$column], $term, $boolean);
    }

    /**
     * `LIKE %término%` sobre varias columnas unidas con OR.
     *
     * `ESCAPE '\\'` se declara explícitamente porque SQLite no usa barra
     * invertida como carácter de escape por defecto (MySQL sí).
     *
     * @param  array<int, string>  $columns
     */
    public function whereAnyOf(array $columns, string $term, string $boolean = 'AND'): self
    {
        $columns = array_values(array_filter($columns, static fn (string $c): bool => $c !== ''));

        if ($columns === []) {
            return $this;
        }

        // '!' como carácter de escape (y no '\\'): MySQL interpreta barras
        // invertidas dentro de los literales de cadena y SQLite no, así que
        // '\\' funcionaría en un motor y rompería el otro.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $parts = [];

        foreach ($columns as $column) {
            $parts[] = $this->db->quoteIdent($column)." LIKE ? ESCAPE '!'";
            $this->bindings[] = '%'.$escaped.'%';
        }

        $this->wheres[] = [$boolean, '('.implode(' OR ', $parts).')', $columns[0]];

        return $this;
    }

    /**
     * @param  array<int, string>  $columns
     */
    public function orWhereAnyOf(array $columns, string $term): self
    {
        return $this->whereAnyOf($columns, $term, 'OR');
    }

    /**
     * LIKE con el patrón tal cual (el llamador pone los `%`).
     */
    public function whereLike(string $column, string $pattern, string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, $this->db->quoteIdent($column).' LIKE ?', $column];
        $this->bindings[] = $pattern;

        return $this;
    }

    /**
     * Condición escrita a mano. Úsala solo con SQL fijo: los valores deben ir
     * en $bindings, nunca concatenados.
     *
     * @param  array<int, mixed>  $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, '('.trim($sql).')', 'raw'];
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    // -----------------------------------------------------------------
    // Orden, límites
    // -----------------------------------------------------------------

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $this->orders[] = [$this->db->quoteIdent($column), $direction];

        return $this;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function orderByRaw(string $expression): self
    {
        if (preg_match('/[^a-zA-Z0-9_.,\s()"\x60*+-]/', $expression) === 1) {
            throw new RuntimeException('orderByRaw() contiene caracteres no permitidos.');
        }

        $this->orders[] = [$expression, ''];

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->db->quoteIdent($column);
        }

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function take(int $limit): self
    {
        return $this->limit($limit);
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function forPage(int $page, int $perPage = 15): self
    {
        return $this->limit($perPage)->offset(max(0, ($page - 1) * $perPage));
    }

    // -----------------------------------------------------------------
    // Ejecución
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        return $this->db->select($this->toSql(), $this->getBindings());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $rows = $this->clone()->limit(1)->get();

        return $rows[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function firstOrFail(): array
    {
        return $this->first() ?? throw new NotFoundHttpException("No existe el registro solicitado en {$this->table}.");
    }

    public function count(string $column = '*'): int
    {
        $aggregate = $column === '*' ? 'COUNT(*)' : 'COUNT('.$this->db->quoteIdent($column).')';
        $clone = $this->clone();
        $clone->columns = [$aggregate.' AS aggregate'];
        $clone->rawColumns = true;
        $clone->orders = [];
        $clone->groups = [];
        $clone->limit = null;
        $clone->offset = null;

        return (int) $clone->db->scalar($clone->toSql(), $clone->getBindings());
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function sum(string $column): float|int
    {
        return (float) ($this->aggregate('SUM', $column) ?? 0);
    }

    public function avg(string $column): ?float
    {
        $value = $this->aggregate('AVG', $column);

        return $value === null ? null : (float) $value;
    }

    private function aggregate(string $function, string $column): mixed
    {
        $clone = $this->clone();
        $clone->columns = [$function.'('.$this->db->quoteIdent($column).') AS aggregate'];
        $clone->rawColumns = true;
        $clone->orders = [];
        $clone->groups = [];
        $clone->limit = null;
        $clone->offset = null;

        return $clone->db->scalar($clone->toSql(), $clone->getBindings());
    }

    /**
     * @return array<int, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $columns = $key === null ? [$column] : [$key, $column];
        $clone = $this->clone();
        $clone->columns = $columns;
        $rows = $clone->db->select($clone->toSql(), $clone->getBindings());

        $result = [];

        foreach ($rows as $row) {
            if ($key === null) {
                $result[] = reset($row);

                continue;
            }

            $result[(string) ($row[$key] ?? '')] = $row[$column] ?? null;
        }

        return $result;
    }

    /**
     * SELECT paginado con total, listo para el layout.
     *
     * @param  array<string, int|string>  $appends
     */
    public function paginate(int $page = 1, int $perPage = 15, array $appends = []): Paginator
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $total = $this->count();
        $items = $this->clone()->forPage($page, $perPage)->get();

        return new Paginator($items, $total, $perPage, $page, $appends);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): int
    {
        return $this->db->insert($this->table, $values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $sets = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $sets[] = $this->db->quoteIdent((string) $column).' = ?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->db->quoteIdent($this->table),
            implode(', ', $sets),
            $this->wheres === [] ? '' : ' WHERE '.$this->compileWheres()
        );

        return $this->db->statement($sql, array_merge($bindings, $this->bindings));
    }

    public function delete(): int
    {
        $sql = sprintf(
            'DELETE FROM %s%s',
            $this->db->quoteIdent($this->table),
            $this->wheres === [] ? '' : ' WHERE '.$this->compileWheres()
        );

        return $this->db->statement($sql, $this->bindings);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        $clone = $this->clone();

        foreach ($attributes as $column => $value) {
            $clone->where((string) $column, $value);
        }

        if ($clone->first() !== null) {
            return $values !== [] && $this->update($values) >= 0;
        }

        return $this->insert($attributes + $values) > 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $identifiers = array_map(fn (string $column): string => $this->db->quoteIdent($column), $columns);
        $bindings = [];
        $tuples = [];

        foreach ($rows as $row) {
            $tuple = [];

            foreach ($columns as $column) {
                $tuple[] = '?';
                $bindings[] = $row[$column] ?? null;
            }

            $tuples[] = '('.implode(', ', $tuple).')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->db->quoteIdent($this->table),
            implode(', ', $identifiers),
            implode(', ', $tuples)
        );

        return $this->db->statement($sql, $bindings);
    }

    /**
     * @return array<int, mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function toSql(): string
    {
        $sql = 'SELECT '.implode(', ', array_map(
            function (string $column): string {
                if ($this->rawColumns) {
                    return $column;
                }

                return $column === '*' ? '*' : $this->renderColumn($column);
            },
            $this->columns
        ));

        $sql .= ' FROM '.$this->db->quoteIdent($this->table);

        if ($this->wheres !== []) {
            $sql .= ' WHERE '.$this->compileWheres();
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY '.implode(', ', $this->groups);
        }

        if ($this->orders !== []) {
            $parts = [];

            foreach ($this->orders as [$column, $direction]) {
                $parts[] = $direction === '' ? $column : $column.' '.$direction;
            }

            $sql .= ' ORDER BY '.implode(', ', $parts);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT '.$this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET '.$this->offset;
        }

        return $sql;
    }

    /**
     * SQL con los valores incrustados. Solo para depurar en logs o tests:
     * nunca se ejecuta, para que no dé la idea de que se puede concatenar.
     */
    public function toRawSql(): string
    {
        $queue = $this->bindings;

        return preg_replace_callback('/\?/', static function () use (&$queue): string {
            $binding = array_shift($queue);

            return match (true) {
                $binding === null => 'NULL',
                is_bool($binding) => $binding ? '1' : '0',
                is_int($binding) || is_float($binding) => (string) $binding,
                default => "'".str_replace("'", "''", (string) $binding)."'",
            };
        }, $this->toSql()) ?? $this->toSql();
    }

    private function renderColumn(string $column): string
    {
        // Ya viene citado ("nombre"): no se vuelve a citar. La comparación se
        // hace a mano para no meter comillas dentro de un patrón delimitado.
        if (strlen($column) > 2 && $column[0] === '"' && $column[strlen($column) - 1] === '"') {
            return $column;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) === 1) {
            return $this->db->quoteIdent($column);
        }

        // Agregados tipo `COUNT(*) AS total`: se validan pero no se citan.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\([A-Za-z0-9_*.,\s]*\))?( as [A-Za-z_][A-Za-z0-9_]*)?$/i', $column) === 1) {
            return $column;
        }

        return $this->db->quoteIdent($column);
    }

    private function compileWheres(): string
    {
        $sql = '';

        foreach ($this->wheres as $index => [$boolean, $clause]) {
            if ($index === 0) {
                $sql = $clause;

                continue;
            }

            $sql .= ' '.$boolean.' '.$clause;
        }

        return $sql;
    }
}
