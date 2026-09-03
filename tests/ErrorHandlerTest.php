<?php

declare(strict_types=1);

namespace Tests;

use ErrorException;
use PHPUnit\Framework\TestCase;
use SimpleMvc\Core\Config;
use SimpleMvc\Core\ErrorHandler;
use SimpleMvc\Core\Logger;
use SimpleMvc\Exceptions\NotFoundHttpException;

final class ErrorHandlerTest extends TestCase
{
    private function handler(bool $debug = true): ErrorHandler
    {
        return new ErrorHandler(
            new Config(['app' => ['debug' => $debug, 'env' => $debug ? 'dev' : 'prod'], 'logging' => ['path' => '', 'level' => 'debug']]),
            new Logger(null, 'debug')
        );
    }

    public function testConvertiraWarningsEnExcepcionesDentroDelProyecto(): void
    {
        $handler = $this->handler();
        $handler->register();

        // El mask de avisos lo manda quien ejecuta (PHPUnit trae el suyo, y el
        // handler respeta lo que esté reportándose): se fija a propósito.
        $previous = error_reporting(E_ALL);

        try {
            trigger_error('aviso de prueba', E_USER_WARNING);
            self::fail('El handler debería convertir el warning en ErrorException.');
        } catch (ErrorException $e) {
            self::assertSame('aviso de prueba', $e->getMessage());
            self::assertSame(E_USER_WARNING, $e->getSeverity());
            self::assertStringContainsString('ErrorHandlerTest.php', $e->getFile());
        }

        // Con el aviso fuera del mask, el handler se aparta y deja hacer a PHP.
        error_reporting(E_ALL & ~E_USER_WARNING);

        try {
            trigger_error('no se reporta', E_USER_WARNING);
            self::assertTrue(true);
        } catch (ErrorException $e) {
            self::fail('Un aviso silenciado no debería convertirse en excepción.');
        }

        error_reporting($previous);
        $handler->unregister();

        // Sin handler instalado, el aviso vuelve a ser solo un aviso.
        self::assertFalse($handler->isRegistered());
        @trigger_error('ya no molesta', E_USER_WARNING);
        self::assertTrue(true);
    }

    public function testNoConvierteNadaDeVendor(): void
    {
        $handler = $this->handler();

        self::assertTrue($handler->shouldConvert(E_USER_WARNING, __FILE__));
        self::assertFalse($handler->shouldConvert(E_USER_WARNING, '/var/www/proyecto/vendor/phpunit/phpunit/src/Runner.php'));
        self::assertFalse($handler->shouldConvert(E_USER_WARNING, 'C:\\www\\proyecto\\vendor\\autoload.php'));
    }

    public function testLasDeprecacionesSoloMolestanEnDesarrollo(): void
    {
        self::assertTrue($this->handler(true)->shouldConvert(E_USER_DEPRECATED, __FILE__));
        self::assertFalse($this->handler(false)->shouldConvert(E_USER_DEPRECATED, __FILE__));
        self::assertFalse($this->handler(true)->shouldConvert(E_DEPRECATED, '/var/www/x/vendor/y.php'));
    }

    public function testRegisterEsIdempotenteYUnregisterReversible(): void
    {
        $handler = $this->handler();

        $handler->register();
        $handler->register();
        self::assertTrue($handler->isRegistered());

        $handler->unregister();
        $handler->unregister();
        self::assertFalse($handler->isRegistered());

        // Se puede volver a instalar.
        $handler->register();
        self::assertTrue($handler->isRegistered());
        $handler->unregister();
    }

    public function testRespuestasDeError(): void
    {
        $handler = $this->handler(false);

        $response = $handler->handle(new NotFoundHttpException('no está aquí'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString('404', $response->body());
        // Sin debug, el mensaje interno no se filtra.
        self::assertStringNotContainsString('no está aquí', $response->body());

        $json = $handler->handle(new \RuntimeException('fallo interno'), new \SimpleMvc\Core\Request(headers: ['accept' => 'application/json']));

        self::assertSame(500, $json->status());
        self::assertSame(\SimpleMvc\Core\Response::JSON, $json->getHeader('content-type'));
        self::assertSame(500, json_decode($json->body(), true)['error']);
        self::assertStringNotContainsString('fallo interno', $json->body());
    }

    public function testEnDepuracionSeVeElDetalle(): void
    {
        $handler = $this->handler(true);
        $response = $handler->handle(new NotFoundHttpException('no está aquí'));

        self::assertStringContainsString('no está aquí', $response->body());
    }

    public function testExcepcionDeValidacionRedirige(): void
    {
        $validator = \SimpleMvc\Core\Validator::make(['nombre' => ''], ['nombre' => 'required']);
        $handler = $this->handler();

        $response = $handler->handle(
            (new \SimpleMvc\Exceptions\ValidationException($validator))->redirectTo('/productos/nuevo'),
            new \SimpleMvc\Core\Request(method: 'POST', path: '/productos')
        );

        self::assertSame(303, $response->status());
        self::assertSame('/productos/nuevo', $response->getHeader('location'));
    }

    public function testDetectaRunnerDePruebas(): void
    {
        // Bajo PHPUnit la definición está presente; el handler no debe pisar la
        // configuración de avisos del runner.
        self::assertSame(defined('PHPUNIT_COMPOSER_INSTALL'), ErrorHandler::runningUnderTestRunner());
    }
}
