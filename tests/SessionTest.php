<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use SimpleMvc\Core\Session;

final class SessionTest extends TestCase
{
    private function session(array $bag = []): Session
    {
        $session = new Session(native: false, bag: $bag);
        $session->start();

        return $session;
    }

    public function testGetPutForgetPull(): void
    {
        $session = $this->session();

        $session->put('usuario', 'ana');
        self::assertSame('ana', $session->get('usuario'));
        self::assertTrue($session->has('usuario'));

        self::assertSame('ana', $session->pull('usuario'));
        self::assertFalse($session->has('usuario'));
        self::assertSame('alterno', $session->get('nada', 'alterno'));

        $session->forget('usuario');
        self::assertFalse($session->has('usuario'));
    }

    public function testElTokenEsEstableYVerificable(): void
    {
        $session = $this->session();
        $token = $session->token();

        self::assertSame($token, $session->token());
        self::assertSame(64, strlen($token));
        self::assertTrue($session->verifyToken($token));
        self::assertFalse($session->verifyToken('otro'));
        self::assertFalse($session->verifyToken(''));
        self::assertFalse($session->verifyToken(null));
    }

    public function testRegenerarInvalidaElTokenAnterior(): void
    {
        $session = $this->session();
        $old = $session->token();
        $session->regenerate();

        self::assertNotSame($old, $session->token());
        self::assertFalse($session->verifyToken($old));
    }

    public function testFlashesDeUnSoloUso(): void
    {
        // Simula la petición siguiente: el bag persiste y start() promueve los pendientes.
        $primera = new Session(native: false);
        $primera->start();
        $primera->flash('success', 'Guardado');
        self::assertNull($primera->getFlash('success'), 'en la misma petición el flash aún no se ve');

        $primera->ageFlashData();   // lo que hace App al terminar la petición

        $segunda = new Session(native: false, bag: $primera->all());
        $segunda->start();

        self::assertSame('Guardado', $segunda->getFlash('success'));
        self::assertTrue($segunda->hasFlash('success'));
        self::assertSame(['success' => 'Guardado'], $segunda->flashes());

        $segunda->ageFlashData();

        $tercera = new Session(native: false, bag: $segunda->all());
        $tercera->start();
        self::assertNull($tercera->getFlash('success'), 'y al tercer salto ya desaparece');
    }

    public function testOldInputOcultaLasClavesSensibles(): void
    {
        $session = $this->session();
        $session->setOldInput([
            'nombre' => 'Teclado',
            'password' => 'secreta',
            'password_confirmation' => 'secreta',
            '_token' => 'abc',
            '_method' => 'PUT',
        ]);

        $session->ageFlashData();

        $next = new Session(native: false, bag: $session->all());
        $next->start();

        self::assertSame(['nombre' => 'Teclado'], $next->oldInput());
    }

    public function testCookiesPendientes(): void
    {
        $session = $this->session();
        $session->queueCookie('tema', 'oscuro', ['lifetime' => 3600]);

        $cookies = $session->pendingCookies();

        self::assertCount(1, $cookies);
        self::assertSame('tema', $cookies[0]['name']);
        self::assertSame(3600, $cookies[0]['options']['lifetime']);
        self::assertTrue($cookies[0]['options']['httponly']);
    }

    public function testStartEsIdempotente(): void
    {
        $session = new Session(native: false);
        $session->start();
        $session->flash('info', 'una vez');
        $session->start();
        $session->start();

        self::assertTrue($session->isStarted());
        // Si start() volviera a envejecer, el flash se perdería.
        self::assertSame('una vez', $session->all()['_flash.new']['info'] ?? null);
    }
}
