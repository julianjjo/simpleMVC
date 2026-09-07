<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use SimpleMvc\Core\Response;

final class ResponseTest extends TestCase
{
    public function testCadenaComoHtml(): void
    {
        $response = Response::make('<p>hola</p>');

        self::assertSame(200, $response->status());
        self::assertSame('<p>hola</p>', $response->body());
        self::assertStringContainsString('text/html', (string) $response->getHeader('content-type'));
    }

    public function testArregloComoJsonSinEscaparUnicode(): void
    {
        $response = Response::make(['nombre' => 'Niño Ñ', 'url' => 'https://x.example/a']);

        self::assertSame(Response::JSON, $response->getHeader('content-type'));
        self::assertSame('{"nombre":"Niño Ñ","url":"https://x.example/a"}', $response->body());
    }

    public function testNuloComoNoContent(): void
    {
        $response = Response::make(null);

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
        self::assertTrue($response->isSuccessful(), '204 sigue siendo una respuesta correcta');
    }

    public function testUnResponsePasaIntacto(): void
    {
        $original = Response::text('x', 201);

        self::assertSame($original, Response::make($original));
        self::assertSame(400, Response::make($original, 400)->status());
    }

    public function testNumerosYBooleansSeVuelvenTexto(): void
    {
        self::assertSame('42', Response::make(42)->body());
        self::assertSame('1', Response::make(true)->body());
    }

    public function testRedireccionSegura(): void
    {
        self::assertSame('/productos', Response::redirect('/productos')->getHeader('location'));
        self::assertSame(302, Response::redirect('/x')->status());
        self::assertTrue(Response::redirect('/x')->isRedirect());
    }

    public function testRedireccionAbiertaSeNeutraliza(): void
    {
        self::assertSame('/', Response::redirect('//evil.example/phish')->getHeader('location'));
        self::assertSame('/', Response::redirect('/\\evil.example')->getHeader('location'));
        self::assertSame('/', Response::redirect('javascript:alert(1)')->getHeader('location'));
        self::assertSame('/', Response::redirect('https://otra.example/x')->getHeader('location'));
        self::assertSame('/relativa', Response::redirect('relativa')->getHeader('location'));
    }

    public function testConWithNoMutables(): void
    {
        $base = Response::html('a');
        $derived = $base->withStatus(201)->withHeader('X-Prueba', '1')->withBody('b');

        self::assertSame(200, $base->status());
        self::assertSame('a', $base->body());
        self::assertNull($base->getHeader('x-prueba'));

        self::assertSame(201, $derived->status());
        self::assertSame('1', $derived->getHeader('X-Prueba'));
        self::assertSame('b', $derived->body());
    }

    public function testCookiesConAtributosSeguros(): void
    {
        $response = Response::html('x')->withCookie('preferencia', 'oscuro');
        $cookies = $response->allCookies();

        self::assertCount(1, $cookies);
        self::assertSame('preferencia', $cookies[0]['name']);
        self::assertTrue($cookies[0]['options']['httponly']);
        self::assertSame('Lax', $cookies[0]['options']['samesite']);
    }

    public function testCabecerasNormales(): void
    {
        $response = Response::json(['a' => 1])->headers(['X-Total' => '1']);

        self::assertSame(['Content-Type' => Response::JSON, 'X-Total' => '1'], $response->allHeaders());
        self::assertSame('{"a":1}', (string) $response);
    }

    public function testNotFoundHelper(): void
    {
        self::assertSame(404, Response::notFound()->status());
        self::assertSame(404, Response::notFound('No está')->status());
        self::assertSame('No está', Response::notFound('No está')->body());
    }

    public function testJsonInvalidoNoRevienta(): void
    {
        $response = Response::json(['texto' => "\xB1\x31"]);

        // JSON_INVALID_UTF8_SUBSTITUTE reemplaza en vez de fallar.
        self::assertSame(Response::JSON, $response->getHeader('content-type'));
        self::assertStringContainsString('texto', $response->body());
    }
}
