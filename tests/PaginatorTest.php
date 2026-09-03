<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use SimpleMvc\Core\Paginator;

final class PaginatorTest extends TestCase
{
    public function testCalculosBasicos(): void
    {
        $paginator = new Paginator(['a', 'b', 'c'], total: 10, perPage: 3, currentPage: 2);

        self::assertCount(3, $paginator);
        self::assertSame(10, $paginator->total());
        self::assertSame(4, $paginator->lastPage());
        self::assertSame(4, $paginator->firstItem());
        self::assertSame(6, $paginator->lastItem());
        self::assertTrue($paginator->hasPages());
        self::assertTrue($paginator->hasMorePages());
        self::assertFalse($paginator->onFirstPage());
        self::assertSame(1, $paginator->previousPage());
        self::assertSame(3, $paginator->nextPage());
    }

    public function testPaginaFueraDeRangoSeRecorta(): void
    {
        $paginator = new Paginator([], total: 5, perPage: 5, currentPage: 99);

        self::assertSame(1, $paginator->currentPage());
        self::assertTrue($paginator->onFirstPage());
        self::assertNull($paginator->nextPage());
        self::assertNull($paginator->previousPage());
        self::assertSame(0, $paginator->firstItem());
    }

    public function testCeroResultados(): void
    {
        $paginator = new Paginator([], total: 0, perPage: 9, currentPage: 1);

        self::assertSame(1, $paginator->lastPage());
        self::assertFalse($paginator->hasPages());
        self::assertSame(0, $paginator->lastItem());
        self::assertSame([1], $paginator->window());
    }

    public function testQueryStringConservaFiltros(): void
    {
        $paginator = new Paginator(['x'], total: 30, perPage: 10, currentPage: 2, appends: ['q' => 'niño']);

        self::assertSame('?q=ni%C3%B1o&page=2', $paginator->queryString(2));
        self::assertSame('?q=ni%C3%B1o', $paginator->queryString(1), 'la página 1 no lleva page=');
        self::assertSame('/productos?q=ni%C3%B1o&page=3', $paginator->url(3, '/productos'));
        self::assertSame('/productos?q=ni%C3%B1o', $paginator->url(1, '/productos/'));
    }

    public function testVentanaDePaginas(): void
    {
        $paginator = new Paginator([], total: 100, perPage: 10, currentPage: 5);

        self::assertSame([3, 4, 5, 6, 7], $paginator->window(5));
        self::assertSame([1, 2, 3, 4, 5], (new Paginator([], 100, 10, 1))->window(5));
        self::assertSame([6, 7, 8, 9, 10], (new Paginator([], 100, 10, 10))->window(5));
    }

    public function testEsIterableYSerializable(): void
    {
        $paginator = new Paginator([1, 2], total: 2, perPage: 5, currentPage: 1);

        $items = [];
        foreach ($paginator as $item) {
            $items[] = $item;
        }

        self::assertSame([1, 2], $items);
        self::assertSame([1, 2], $paginator->toArray()['data']);
        self::assertSame(2, $paginator->toArray()['total']);
        self::assertSame('{"data":[1,2],"total":2,"per_page":5,"current_page":1,"last_page":1,"from":1,"to":2}', json_encode($paginator));
    }
}
