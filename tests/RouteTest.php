<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleMvc\Core\Route;

final class RouteTest extends TestCase
{
    public function testExtraeParametrosEnOrden(): void
    {
        $route = new Route('/productos/:id', fn () => 'x');

        self::assertSame(['id' => '5'], $route->match('/productos/5'));
        self::assertSame(['id'], $route->parameterNames());
    }

    public function testVariosParametrosConservanElOrdenDeDeclaracion(): void
    {
        $route = new Route('/demo/:a/:b/:c', fn () => 'x');

        self::assertSame(['a' => 'uno', 'b' => 'dos', 'c' => 'tres'], $route->match('/demo/uno/dos/tres'));
    }

    public function testRestriccionEnElPatternDelSegmento(): void
    {
        $route = new Route('/productos/:id(\d+)', fn () => 'x');

        self::assertSame(['id' => '12'], $route->match('/productos/12'));
        self::assertNull($route->match('/productos/abc'));
        self::assertFalse($route->matchesPath('/productos/abc'));
    }

    public function testWhereSobrescribeLaRestriccion(): void
    {
        $route = new Route('/x/:id', fn () => 'x');
        self::assertNotNull($route->match('/x/abc'));

        $route->where('id', '\d+');
        self::assertNull($route->match('/x/abc'));
        self::assertNotNull($route->match('/x/99'));
    }

    public function testEscapaLosCaracteresEspecialesDeLaUri(): void
    {
        // El router original solo reemplazaba '/' por '\/', de modo que el '.'
        // de /v1.0 seguía siendo "cualquier caracter" dentro de la regex.
        $route = new Route('/v1.0/status', fn () => 'x');

        self::assertTrue($route->matchesPath('/v1.0/status'));
        self::assertFalse($route->matchesPath('/v100/status'));
    }

    public function testToleraLaBarraFinal(): void
    {
        $route = new Route('/productos', fn () => 'x');

        self::assertTrue($route->matchesPath('/productos'));
        self::assertTrue($route->matchesPath('/productos/'));
        self::assertSame('/productos', $route->uri(), 'la uri declarada queda intacta');

        $root = new Route('/', fn () => 'x');
        self::assertSame('/', $root->uri());
        self::assertTrue($root->matchesPath('/'));
        self::assertTrue($root->matchesPath(''));
    }

    public function testComodinFinal(): void
    {
        $route = new Route('/archivos/*', fn () => 'x');

        self::assertSame(['wildcard' => 'a/b/c.txt'], $route->match('/archivos/a/b/c.txt'));
    }

    public function testDecodificaLosParametros(): void
    {
        $route = new Route('/buscar/:q', fn () => 'x');

        self::assertSame(['q' => 'niño y águila'], $route->match('/buscar/ni%C3%B1o%20y%20%C3%A1guila'));
    }

    public function testRechazaParametrosDuplicados(): void
    {
        $route = new Route('/x/:id/y/:id', fn () => 'x');

        try {
            $route->match('/x/1/y/2');
            self::fail('Debería rechazar un parámetro repetido.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('repite el parámetro', $e->getMessage());
        }
    }

    public function testGetAnadeHeadImplicitamente(): void
    {
        $route = new Route('/', fn () => 'x');
        $route->methods(['GET']);

        self::assertSame(['GET', 'HEAD'], $route->allowedMethods());
        self::assertTrue($route->allowsMethod('head'));
        self::assertFalse($route->allowsMethod('post'));
    }

    public function testNombreConPrefijoDeGrupo(): void
    {
        $route = new Route('/productos/:id', fn () => 'x');
        self::assertNull($route->getName());

        $route->name('show');
        self::assertSame('show', $route->getName());

        $route->namePrefix('products.');
        self::assertSame('products.show', $route->getName());
        self::assertSame('show', $route->rawName());
    }

    public function testBuildGeneraLaUrlYEnviarElRestoAQuery(): void
    {
        $route = new Route('/productos/:id(\d+)', fn () => 'x');

        self::assertSame('/productos/42', $route->build(['id' => 42]));
        self::assertSame('/productos/42?page=2', $route->build(['id' => 42, 'page' => 2]));
        self::assertSame('/productos/ni%20%C3%B1o', (new Route('/productos/:slug', fn () => 'x'))
            ->build(['slug' => 'ni ño']));
    }

    public function testFallaAlConstruirSinParametrosObligatorios(): void
    {
        $route = new Route('/productos/:id(\d+)', fn () => 'x');

        try {
            $route->build([]);
            self::fail('Debería exigir el parámetro id.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Falta el parámetro', $e->getMessage());
        }
    }

    public function testUriVaciaNoEsValida(): void
    {
        try {
            new Route('/x', null);
            self::fail('Una ruta sin acción no tiene sentido.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('no tiene ninguna acción', $e->getMessage());
        }
    }
}
