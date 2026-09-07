<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use SimpleMvc\Core\Request;

final class RequestTest extends TestCase
{
    public function testNormalizaRutas(): void
    {
        self::assertSame('/productos', Request::normalizePath('/productos/'));
        self::assertSame('/productos', Request::normalizePath('/productos'));
        self::assertSame('/', Request::normalizePath('/'));
        self::assertSame('/', Request::normalizePath('/index.php'));
        // Los `..` se dejan intactos: el router no tiene rutas con ellos y, si
        // las tuviera, la barra final sobra para casar.
        self::assertSame('/productos/..', Request::normalizePath('/productos/../'));
    }

    public function testQuitaElPrefijoDeInstalacion(): void
    {
        self::assertSame('/productos/3', Request::normalizePath('/rutas_amigables/productos/3', '/rutas_amigables'));
        self::assertSame('/', Request::normalizePath('/rutas_amigables', '/rutas_amigables'));
    }

    public function testLeeQueryYBody(): void
    {
        $request = new Request(
            method: 'GET',
            path: '/buscar',
            query: ['q' => 'monitor', 'page' => '3'],
            body: ['q' => 'desde-post'],
        );

        self::assertSame('monitor', $request->query('q'));
        self::assertSame(['q' => 'monitor', 'page' => '3'], $request->query());
        self::assertSame('desde-post', $request->input('q'), 'el cuerpo manda sobre la query');
        self::assertSame(3, $request->int('page'));
        self::assertNull($request->int('no_existe'));
        self::assertSame('desde-post', $request->string('q'), 'string() sigue a input(): cuerpo primero');
        self::assertSame('monitor', $request->query('q'));
        self::assertSame('desde-post', $request->body('q'));
    }

    public function testCastingDeEscalares(): void
    {
        $request = new Request(method: 'POST', path: '/', body: [
            'activo' => '1',
            'inactivo' => 'false',
            'vacío' => '',
            'precio' => '19,90',
        ]);

        self::assertTrue($request->bool('activo'));
        self::assertFalse($request->bool('inactivo'));
        self::assertTrue($request->bool('ausente', true));
        self::assertFalse($request->filled('vacío'));
        self::assertTrue($request->has('vacío'));
    }

    public function testMetodoSobrescritoPorFormularios(): void
    {
        $_POST = ['_method' => 'PUT'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/productos/3';
        $_GET = [];
        $_COOKIE = [];
        $_FILES = [];

        $request = Request::capture();

        self::assertSame('PUT', $request->method());
        self::assertSame('/productos/3', $request->path());
        self::assertFalse($request->isReadOnly());

        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testMetodoSobrescritoNoAceptaCualquierCosa(): void
    {
        $_POST = ['_method' => 'EAVESDROP'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/x';
        $_GET = [];

        self::assertSame('POST', Request::capture()->method());

        $_POST = [];
        unset($_SERVER['REQUEST_URI']);
    }

    public function testEncabezadosEnMinusculas(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $request = Request::capture();

        self::assertSame('XMLHttpRequest', $request->header('X-REQUESTED-WITH'));
        self::assertTrue($request->isAjax());
        self::assertFalse($request->wantsJson());

        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
    }

    public function testQuiereJsonPorAccept(): void
    {
        $request = new Request(headers: ['accept' => 'application/json']);

        self::assertTrue($request->wantsJson());
        self::assertTrue((new Request(query: ['format' => 'json']))->wantsJson());
    }

    public function testHostRechazaValoresRaros(): void
    {
        $normal = new Request(headers: ['host' => 'example.com:8080']);
        $sucio = new Request(headers: ['host' => "evil.example.com\r\nSet-Cookie: a=b"]);

        self::assertSame('example.com:8080', $normal->host());
        self::assertSame('localhost', $sucio->host(), 'un Host manipulable no puede acabar en un enlace generado');
    }

    public function testAttributesInmutables(): void
    {
        $request = new Request(path: '/x');
        $withParams = $request->withAttributes(['id' => '7']);

        self::assertSame([], $request->attributes());
        self::assertSame(['id' => '7'], $withParams->attributes());
        self::assertSame('7', $withParams->attribute('id'));
        self::assertNull($withParams->attribute('nada'));
    }

    public function testUrlCompletaConservaElPrefijo(): void
    {
        $request = new Request(path: '/productos', query: ['page' => '2'], basePath: '/rutas_amigables');

        self::assertSame('/rutas_amigables/productos?page=2', $request->fullUrl());
        self::assertSame('http', $request->scheme());
        self::assertTrue((new Request(server: ['HTTPS' => 'on']))->isSecure());
    }

    public function testJsonEnElCuerpo(): void
    {
        // En CLI php://input está vacío, así que la petición se construye a mano
        // con el cuerpo ya decodificado.
        $request = new Request(
            method: 'POST',
            path: '/api/x',
            body: ['nombre' => 'Teclado'],
            headers: ['content-type' => 'application/json'],
        );

        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('Teclado', $request->string('nombre'));
        self::assertNull($request->rawBody());
    }
}
