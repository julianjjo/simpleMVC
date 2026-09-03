<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleMvc\Core\Response;
use SimpleMvc\Core\View;
use SimpleMvc\Exceptions\ViewNotFoundException;

final class ViewTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $this->view = new View(__DIR__.'/Fixtures/views', 'layout');
    }

    public function testRenderizaDentroDelLayout(): void
    {
        $html = $this->view->render('greeting', ['nombre' => 'Marta', 'notas' => ['una nota']]);

        self::assertStringContainsString('<h1>Hola Marta</h1>', $html);
        self::assertStringContainsString('<title>Hola Marta</title>', $html, '$title definido en la plantilla llega al layout');
        self::assertStringContainsString('<footer>pie</footer>', $html);
    }

    public function testSinLayout(): void
    {
        $html = $this->view->render('greeting', ['nombre' => 'Iván', 'notas' => []], null);

        self::assertStringContainsString('<h1>Hola Iván</h1>', $html);
        self::assertStringNotContainsString('<!DOCTYPE html>', $html);
    }

    public function testPartial(): void
    {
        self::assertSame('<span>valor</span>', trim($this->view->partial('partial', ['valor' => 'valor'])));
    }

    public function testComparteVariables(): void
    {
        $this->view->share('footer', 'con Composer o sin él');

        $html = $this->view->render('greeting', ['nombre' => 'x', 'notas' => []]);

        self::assertStringContainsString('<footer>con Composer o sin él</footer>', $html);
    }

    public function testResponse(): void
    {
        $response = $this->view->response('greeting', ['nombre' => 'Ana', 'notas' => []]);

        self::assertInstanceOf(Response::class, $response);
        self::assertStringContainsString('Hola Ana', $response->body());
    }

    public function testPlantillaInexistente(): void
    {
        $this->expectException(ViewNotFoundException::class);
        $this->view->render('no-existe');
    }

    public function testNoSaleDelDirectorioDeVistas(): void
    {
        // El View original concatenaba el nombre en un include: esto lo bloquea.
        foreach (['../../src/Core/Router', '../layout', '....//....//etc/passwd', '/etc/passwd'] as $evil) {
            try {
                $this->view->render($evil);
                self::fail("La plantilla «{$evil}» no debería resolverse.");
            } catch (ViewNotFoundException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }

        self::assertFalse($this->view->exists('../../src/Core/Router'));
    }

    public function testNombresRarosSeRechazan(): void
    {
        $this->expectException(ViewNotFoundException::class);
        $this->view->render('plantilla<script>');
    }

    public function testLiberaElBufferCuandoLaPlantillaFalla(): void
    {
        $before = ob_get_level();

        try {
            $this->view->render('broken');
            self::fail('La plantilla lanza a propósito.');
        } catch (RuntimeException $e) {
            self::assertSame('planta rota', $e->getMessage());
        }

        self::assertSame($before, ob_get_level(), 'los ob_start() de la plantilla deben quedar cerrados');
    }

    public function testNamespacesDeVistas(): void
    {
        $this->view->addNamespace('fixtures', __DIR__.'/Fixtures/views');

        self::assertStringContainsString('<span>ok</span>', $this->view->partial('fixtures::partial', ['valor' => 'ok']));
    }

    public function testNamespaceDesconocido(): void
    {
        $this->expectException(ViewNotFoundException::class);
        $this->view->partial('fantasma::partial');
    }
}
