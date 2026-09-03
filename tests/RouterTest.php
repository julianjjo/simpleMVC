<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleMvc\Core\Container;
use SimpleMvc\Core\CsrfMiddleware;
use SimpleMvc\Core\Request;
use SimpleMvc\Core\Response;
use SimpleMvc\Core\Router;
use SimpleMvc\Core\Session;
use SimpleMvc\Exceptions\HttpException;
use SimpleMvc\Exceptions\MethodNotAllowedHttpException;
use SimpleMvc\Exceptions\NotFoundHttpException;
use Tests\Support\FakeController;

final class RouterTest extends TestCase
{
    private Container $container;

    private Router $router;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->instance(Session::class, new Session(native: false));
        $this->router = new Router($this->container);
    }

    private function request(string $method = 'GET', string $path = '/', array $body = [], array $query = []): Request
    {
        return new Request(method: $method, path: $path, query: $query, body: $body);
    }

    public function testDevuelveUnaCadenaComoHtml(): void
    {
        $this->router->get('/', fn () => '<h1>Home</h1>');

        $response = $this->router->dispatch($this->request(path: '/'));

        self::assertSame(200, $response->status());
        self::assertSame('<h1>Home</h1>', $response->body());
        self::assertSame(Response::HTML, $response->getHeader('content-type'));
    }

    /**
     * El bug de `call_user_func_array` con claves nombradas en PHP 8.
     */
    public function testPasaLosParametrosComoListaPosicional(): void
    {
        $this->router->get('/r/:alpha/:beta', fn (string $alpha, string $beta): string => $alpha.'|'.$beta);

        self::assertSame('x|y', $this->router->dispatch($this->request(path: '/r/x/y'))->body());
    }

    public function testCoercionaLosParametrosAlTipoDeclarado(): void
    {
        $this->router->get('/n/:id(\d+)', fn (int $id): string => (string) ($id * 2));

        self::assertSame('14', $this->router->dispatch($this->request(path: '/n/7'))->body());
    }

    public function testCoercionaFlotantes(): void
    {
        $this->router->get('/p/:precio', fn (float $precio): string => number_format($precio, 2, '.', ''));

        self::assertSame('12.50', $this->router->dispatch($this->request(path: '/p/12.5'))->body());
    }

    public function testUnNumeroFueraDeRangoDevuelve404(): void
    {
        $this->router->get('/n/:id', fn (int $id): string => (string) $id);

        try {
            $this->router->dispatch($this->request(path: '/n/abc'));
            self::fail('Se esperaba un 404.');
        } catch (NotFoundHttpException $e) {
            self::assertStringContainsString('no es un número válido', $e->getMessage());
        }
    }

    public function testInyectaElRequestYLosServiciosDelContenedor(): void
    {
        $this->container->instance(FakeController::class, new FakeController('servicio'));

        $this->router->get('/c/:id(\d+)', [FakeController::class, 'show']);

        $response = $this->router->dispatch($this->request(method: 'GET', path: '/c/9', query: ['q' => 'lua']));

        self::assertSame('servicio:9:lua', $response->body());
    }

    public function testResuelveElNombreViejoControllerMetodo(): void
    {
        // Compatibilidad con el estilo del tutorial: 'Controller::metodo'.
        $this->router->add('/legacy', 'Tests\Support\FakeController::plain');

        self::assertSame('legacy', $this->router->dispatch($this->request(path: '/legacy'))->body());
    }

    public function testAddAceptaGetYPost(): void
    {
        $this->router->add('/form', fn (Request $request): string => $request->method());

        self::assertSame('GET', $this->router->dispatch($this->request(path: '/form'))->body());
        self::assertSame('POST', $this->router->dispatch($this->request(method: 'POST', path: '/form'))->body());
    }

    public function testArraySeConvierteEnJson(): void
    {
        $this->router->get('/api', fn (): array => ['nombre' => 'Niño']);

        $response = $this->router->dispatch($this->request(path: '/api'));

        self::assertSame(Response::JSON, $response->getHeader('content-type'));
        // Sin JSON_UNESCAPED_UNICODE saldría "Ni\u00f1o".
        self::assertStringContainsString('"nombre":"Niño"', $response->body());
    }

    public function testUnObjetoResponsePasaTalCual(): void
    {
        $this->router->get('/r', fn () => Response::text('plano', 201)->withHeader('X-Faker', 'si'));

        $response = $this->router->dispatch($this->request(path: '/r'));

        self::assertSame(201, $response->status());
        self::assertSame('plano', $response->body());
        self::assertSame('si', $response->getHeader('x-faker'));
    }

    public function testRutaInexistenteLanza404(): void
    {
        $this->router->get('/existe', fn () => 'ok');

        $this->expectException(NotFoundHttpException::class);
        $this->router->dispatch($this->request(path: '/no'));
    }

    public function testMetodoIncorrectoDevuelve405ConAllow(): void
    {
        $this->router->get('/solo-get', fn () => 'ok');

        try {
            $this->router->dispatch($this->request(method: 'POST', path: '/solo-get'));
            self::fail('Se esperaba MethodNotAllowedHttpException.');
        } catch (MethodNotAllowedHttpException $e) {
            self::assertSame(405, $e->status());
            self::assertStringContainsString('GET', $e->headers()['Allow'] ?? '');
            self::assertContains('GET', $e->allowedMethods());
        }
    }

    public function testFallbackCubreLoQueNoCoincide(): void
    {
        $this->router->get('/conocido', fn () => 'ok');
        $this->router->fallback(fn (Request $request): string => 'fallback:'.$request->path());

        self::assertSame('fallback:/otra/cosa', $this->router->dispatch($this->request(path: '/otra/cosa'))->body());
    }

    public function testEl405TienePrioridadSobreElFallback(): void
    {
        $this->router->get('/x', fn () => 'ok');
        $this->router->fallback(fn () => 'fallback');

        $this->expectException(MethodNotAllowedHttpException::class);
        $this->router->dispatch($this->request(method: 'DELETE', path: '/x'));
    }

    public function testMiddlewareGlobalYDeRuta(): void
    {
        $trace = [];

        $this->router->middleware(function (Request $request, Closure $next) use (&$trace): Response {
            $trace[] = 'global:entrada';

            $response = $next($request);
            $trace[] = 'global:salida';

            return $response->withHeader('X-Global', '1');
        });

        $this->router->get('/m', function () use (&$trace): string {
            $trace[] = 'accion';

            return 'ok';
        })->middleware(function (Request $request, Closure $next) use (&$trace): Response {
            $trace[] = 'ruta:entrada';

            $response = $next($request);
            $trace[] = 'ruta:salida';

            return $response->withHeader('X-Ruta', '1');
        });

        $response = $this->router->dispatch($this->request(path: '/m'));

        // Cebolla: el global envuelve al de ruta, así que es el último en ver
        // la respuesta.
        self::assertSame(
            ['global:entrada', 'ruta:entrada', 'accion', 'ruta:salida', 'global:salida'],
            $trace,
            'el middleware global debe aplicarse de verdad a las rutas registradas después'
        );
        self::assertSame('1', $response->getHeader('x-global'));
        self::assertSame('1', $response->getHeader('x-ruta'));
    }

    public function testEncabezadosDeSeguridadPorDefecto(): void
    {
        $this->router->get('/s', fn () => 'ok');

        $response = $this->router->dispatch($this->request(path: '/s'));

        self::assertSame('nosniff', $response->getHeader('x-content-type-options'));
        self::assertSame('SAMEORIGIN', $response->getHeader('x-frame-options'));
        self::assertStringContainsString("default-src 'self'", (string) $response->getHeader('content-security-policy'));
    }

    public function testGruposConPrefijoNombreYMiddleware(): void
    {
        $this->router->group(['prefix' => '/admin', 'as' => 'admin.', 'middleware' => 'Tests\Support\SimpleMiddleware'], function (Router $router): void {
            $router->get('/users/:id(\d+)', fn (int $id): string => 'user '.$id)->name('users.show');
        });

        $route = $this->router->routes()[0];

        self::assertSame('/admin/users/:id(\d+)', $route->uri());
        self::assertSame('admin.users.show', $route->getName());
        self::assertSame('user 3', $this->router->dispatch($this->request(path: '/admin/users/3'))->body());
        self::assertSame('/admin/users/3', $this->router->url('admin.users.show', ['id' => 3]));
    }

    public function testUrlConPrefijoDeInstalacion(): void
    {
        $router = new Router($this->container, '/rutas_amigables');
        $router->get('/productos/:id(\d+)', fn (int $id) => 'x')->name('products.show');

        self::assertSame('/rutas_amigables/productos/4', $router->url('products.show', ['id' => 4]));
    }

    public function testNombreDuplicadoExplota(): void
    {
        $this->router->get('/a', fn () => 'a')->name('uniq');
        $this->router->get('/b', fn () => 'b')->name('uniq');

        $this->expectException(RuntimeException::class);
        $this->router->url('uniq');
    }

    public function testCsrfRechazaUnPostSinToken(): void
    {
        $this->router->requireCsrf();
        $this->router->post('/guardar', fn () => 'ok');

        try {
            $this->router->dispatch($this->request(method: 'POST', path: '/guardar'));
            self::fail('Se esperaba 419 por falta de token CSRF.');
        } catch (HttpException $e) {
            self::assertSame(419, $e->status());
        }
    }

    public function testCsrfAceptaElTokenValido(): void
    {
        $session = $this->container->make(Session::class);
        $session->start();

        $this->router->middleware(CsrfMiddleware::class);
        $this->router->post('/guardar', fn () => 'ok');

        $response = $this->router->dispatch($this->request(
            method: 'POST',
            path: '/guardar',
            body: ['_token' => $session->token()]
        ));

        self::assertSame('ok', $response->body());
    }

    public function testCsrfNoBloqueaLosGet(): void
    {
        $this->router->requireCsrf();
        $this->router->get('/listar', fn () => 'listo');

        self::assertSame('listo', $this->router->dispatch($this->request(path: '/listar'))->body());
    }

    public function testGetRegistraHead(): void
    {
        $this->router->get('/h', fn () => 'hola');

        self::assertSame('hola', $this->router->dispatch($this->request(method: 'HEAD', path: '/h'))->body());
    }
}
