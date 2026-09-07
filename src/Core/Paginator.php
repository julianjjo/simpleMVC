<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Paginador ligero para las listas (el proyecto original no paginaba nada).
 */
final class Paginator implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  array<int, mixed>  $items
     * @param  array<string, int|string>  $appends  query params a conservar en los enlaces
     */
    public function __construct(
        private array $items = [],
        private int $total = 0,
        private int $perPage = 15,
        private int $currentPage = 1,
        private array $appends = []
    ) {
        $this->perPage = max(1, $perPage);
        $this->total = max(0, $total);
        $this->currentPage = max(1, min($currentPage, max(1, $this->lastPage())));
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<string, int|string>  $appends
     */
    public static function make(array $items, int $total, int $perPage, int $currentPage, array $appends = []): self
    {
        return new self($items, $total, $perPage, $currentPage, $appends);
    }

    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function firstItem(): int
    {
        if ($this->items === []) {
            return 0;
        }

        return ($this->currentPage - 1) * $this->perPage + 1;
    }

    public function lastItem(): int
    {
        return min($this->total, ($this->currentPage - 1) * $this->perPage + count($this->items));
    }

    public function hasPages(): bool
    {
        return $this->lastPage() > 1;
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function nextPage(): ?int
    {
        return $this->hasMorePages() ? $this->currentPage + 1 : null;
    }

    public function previousPage(): ?int
    {
        return $this->currentPage > 1 ? $this->currentPage - 1 : null;
    }

    /**
     * URL completa para una página, conservando filtros activos.
     *
     * @param  string  $path  ruta actual (p. ej. /productos)
     */
    public function url(int $page, string $path = ''): string
    {
        $base = $path === '' ? '/' : rtrim($path, '/');

        if ($base === '') {
            $base = '/';
        }

        $query = $this->queryString($page);

        if ($query === '' && $page > 1) {
            $query = '?page='.$page;
        }

        return ($base === '/' ? '/' : $base).$query;
    }

    /**
     * Query string (con `?`) para una página, conservando filtros activos.
     */
    public function queryString(int $page): string
    {
        $params = $this->appends;

        if ($page > 1) {
            $params['page'] = $page;
        } else {
            unset($params['page']);
        }

        return $params === [] ? '' : '?'.http_build_query($params);
    }

    /**
     * Ventana de páginas para pintar la navegación.
     *
     * @return array<int, int>
     */
    public function window(int $size = 5): array
    {
        $last = $this->lastPage();

        if ($last <= $size) {
            return range(1, $last);
        }

        $start = max(1, $this->currentPage - (int) floor($size / 2));
        $end = min($last, $start + $size - 1);
        $start = max(1, $end - $size + 1);

        return range($start, $end);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(
                static fn (mixed $item): mixed => $item instanceof JsonSerializable ? $item->jsonSerialize() : $item,
                $this->items
            ),
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage(),
            'from' => $this->firstItem(),
            'to' => $this->lastItem(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
