<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\ProductRepository;
use PHPUnit\Framework\TestCase;
use SimpleMvc\Core\App;
use SimpleMvc\Core\Config;
use SimpleMvc\Core\Container;
use SimpleMvc\Core\Logger;
use SimpleMvc\Core\Request;
use SimpleMvc\Core\Response;
use SimpleMvc\Core\Session;
use SimpleMvc\Support\Str;
use Tests\Support\InteractsWithDatabase;

/**
 * Prueba de extremo a extremo: frente de control -> router -> middleware ->
 * controlador -> repositorio -> PDO SQLite -> vista -> Response.
 */
final class AppTest extends TestCase
{
    use InteractsWithDatabase;

    private ?App $previousApp = null;

    private App $app;

    private Session $session;

    private \SimpleMvc\Core\Database $db;

    private array $previousServer = [];

    protected function setUp(): void
    {
        $this->previousApp = App::instance();

        // Simula el montaje normal: docroot apuntando a public/.
        foreach (['SCRIPT_FILENAME', 'DOCUMENT_ROOT', 'SCRIPT_NAME'] as $key) {
            $this->previousServer[$key] = $_SERVER[$key] ?? null;
        }

        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__).'/public';
        $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'].'/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $this->db = $this->sqliteDatabase();
        $seeder = require dirname(__DIR__).'/database/seeds.php';
        $seeder($this->db);

        $container = new Container();
        $container->instance(Config::class, $this->sqliteConfig());
        $container->instance(\SimpleMvc\Core\Database::class, $this->db);
        $container->instance(Logger::class, new Logger(null, 'debug'));

        $this->session = new Session(native: false);
        $container->instance(Session::class, $this->session);

        $this->app = App::boot(dirname(__DIR__), $container);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        // El handler global de errores se quita: si se queda instalado, el runner
        // se lleva ErrorExceptions por avisos que no son de este código.
        $this->app->errorHandling()->unregister();

        App::setInstance($this->previousApp);
    }

    private function get(string $path, array $query = [], array $headers = []): Response
    {
        return $this->app->handle(new Request(method: 'GET', path: $path, query: $query, headers: $headers));
    }

    private function send(string $method, string $path, array $body = [], array $headers = []): Response
    {
        return $this->app->handle(new Request(method: $method, path: $path, body: $body, headers: $headers));
    }

    public function testLaPortadaSeRenderiza(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<!DOCTYPE html>', $response->body());
        self::assertStringContainsString('Micro-framework MVC en PHP 8', $response->body());
        self::assertStringContainsString('charset="utf-8"', $response->body());
        self::assertSame(Response::HTML, $response->getHeader('content-type'));
    }

    public function testElMiddlewareGlobalSeAplica(): void
    {
        // routes/web.php registra RequestId como middleware global.
        $response = $this->get('/');

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (string) $response->getHeader('x-request-id'));
        self::assertNotSame(
            (string) $response->getHeader('x-request-id'),
            (string) $this->get('/productos')->getHeader('x-request-id'),
            'cada petición tiene su propio id de correlación'
        );
        self::assertMatchesRegularExpression('/^\d+ ms$/', (string) $response->getHeader('x-response-time'));
    }

    public function testListadoDeProductosConDatosReales(): void
    {
        $response = $this->get('/productos');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('14 resultados', $response->body());
        self::assertStringContainsString('mostrando 1–9', $response->body());
        self::assertSame(9, substr_count($response->body(), 'product-card'));
        self::assertStringContainsString('/productos/audifonos-over-ear-con-cancelacion-de-ruido', $response->body());
        self::assertStringContainsString('Paginación', $response->body());
    }

    public function testFiltrosYBusquedaPorQuery(): void
    {
        $response = $this->get('/productos', ['q' => 'monitor']);

        self::assertStringContainsString('2 resultados', $response->body());
        self::assertStringNotContainsString('Teclado mecánico', $response->body());

        $byCategory = $this->get('/productos', ['categoria' => 'audio']);
        self::assertStringContainsString('Audífonos over-ear', $byCategory->body());
        self::assertStringNotContainsString('SSD NVMe', $byCategory->body());
    }

    public function testPaginacionReal(): void
    {
        $primera = $this->get('/productos');
        self::assertStringContainsString('mostrando 1–9', $primera->body());

        $segunda = $this->get('/productos', ['page' => '2']);
        self::assertStringContainsString('mostrando 10–14', $segunda->body());
        self::assertStringContainsString('Página 2 de 2', $segunda->body());
    }

    public function testPáginaDeProductoPorSlugYCanonica(): void
    {
        $response = $this->get('/productos/teclado-mecanico-inalambrico-k80');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Teclado mecánico inalámbrico K80', $response->body());
        self::assertStringContainsString('Editar</a>', $response->body());
        self::assertStringContainsString('24 unidades', $response->body());
        self::assertStringContainsString('189.900,00 COP', $response->body());

        // Por id redirige a la URL canónica con slug.
        $redirect = $this->get('/productos/1');
        self::assertSame(302, $redirect->status());
        self::assertStringContainsString('/productos/teclado-mecanico-inalambrico-k80', (string) $redirect->getHeader('location'));
    }

    public function testProductoInexistenteEs404(): void
    {
        $response = $this->get('/productos/no-existe-nada');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Página no encontrada', $response->body());
        self::assertStringContainsString('Rutas registradas', $response->body());
    }

    public function testRutaDesconocidaEs404(): void
    {
        self::assertSame(404, $this->get('/nada/por/aqui')->status());
    }

    public function testMetodoNoPermitido(): void
    {
        $response = $this->send('DELETE', '/productos');

        self::assertSame(405, $response->status());
        self::assertStringContainsString('GET', (string) $response->getHeader('allow'));
    }

    public function testApiJson(): void
    {
        $response = $this->get('/api/v1/productos', ['q' => 'ssd']);
        $payload = json_decode($response->body(), true);

        self::assertSame(Response::JSON, $response->getHeader('content-type'));
        self::assertSame(1, $payload['meta']['total']);
        self::assertSame('SSD NVMe Gen4 1 TB', $payload['data'][0]['nombre']);
        self::assertStringNotContainsString('\u00', $response->body(), 'los acentos no deben salir escapados');
    }

    public function testApiJson404(): void
    {
        $response = $this->get('/api/v1/productos/fantasma', ['format' => 'json']);

        self::assertSame(404, $response->status());
        self::assertSame(Response::JSON, $response->getHeader('content-type'));
        self::assertSame(404, json_decode($response->body(), true)['error']);
    }

    public function testEndpointDeSalud(): void
    {
        $payload = json_decode($this->get('/salud')->body(), true);

        self::assertSame('ok', $payload['status']);
        self::assertSame('sqlite', $payload['driver']);
    }

    public function testPostSinCsrfSeRechaza(): void
    {
        $this->session->start();

        $response = $this->send('POST', '/productos', [
            'nombre' => 'Sin token',
            'precio' => '10',
            'stock' => '1',
            'categoria' => 'otros',
        ]);

        self::assertSame(419, $response->status());
        self::assertSame(0, (new ProductRepository($this->db))->searchFiltered(term: 'Sin token')->total());
    }

    public function testCrearProductoPorPostYRedirect(): void
    {
        $this->session->start();

        $response = $this->send('POST', '/productos', [
            '_token' => $this->session->token(),
            'nombre' => 'Cargador GaN 65 W',
            'descripcion' => 'Carga rápida en un cubo pequeño.',
            'precio' => '78900.90',
            'stock' => '15',
            'categoria' => 'componentes',
            'destacado' => '1',
        ]);

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/productos/cargador-gan-65-w', (string) $response->getHeader('location'));

        $created = (new ProductRepository($this->db))->findBySlug('cargador-gan-65-w');
        self::assertNotNull($created);
        self::assertSame(78900.9, $created->precio);
        self::assertSame(15, $created->stock);
        self::assertTrue($created->destacado);
    }

    public function testValidacionFallidaDevuelveErroresYEInputPrevio(): void
    {
        $this->session->start();

        $response = $this->send('POST', '/productos', [
            '_token' => $this->session->token(),
            'nombre' => 'ab',
            'precio' => 'mucho',
            'stock' => '-3',
            'categoria' => 'naves-espaciales',
        ]);

        self::assertSame(303, $response->status());

        $vuelta = $this->get('/productos/nuevo');
        self::assertSame(200, $vuelta->status());
        self::assertStringContainsString('Revisa', $vuelta->body());
        self::assertStringContainsString('debe ser un número', $vuelta->body());
        self::assertStringContainsString('al menos 3 caracteres', $vuelta->body());
        self::assertStringContainsString('Escoge una categoría', $vuelta->body());
        self::assertStringContainsString('value="ab"', $vuelta->body(), 'el formulario conserva lo escrito');
    }

    public function testFlashDeExitoTrasCrear(): void
    {
        $this->session->start();
        $this->send('POST', '/productos', [
            '_token' => $this->session->token(),
            'nombre' => 'Disco externo',
            'precio' => '1',
            'stock' => '1',
            'categoria' => 'almacenamiento',
        ]);

        $listado = $this->get('/productos');

        self::assertStringContainsString('Se creó «Disco externo»', $listado->body());
        self::assertStringNotContainsString('Se creó «Disco externo»', $this->get('/productos')->body(), 'el flash dura una sola petición');
    }

    public function testEditarPorPutConFormMethodOverride(): void
    {
        $this->session->start();

        $producto = (new ProductRepository($this->db))->findBySlug('teclado-mecanico-inalambrico-k80');
        self::assertNotNull($producto);

        // Los navegadores no envían PUT: el campo _method lo convierte.
        $response = $this->send('POST', '/productos/'.$producto->id, [
            '_token' => $this->session->token(),
            '_method' => 'PUT',
            'nombre' => 'Teclado mecánico K80 pro',
            'precio' => '199900',
            'stock' => '30',
            'categoria' => 'perifericos',
        ]);

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/productos/teclado-mecanico-k80-pro', (string) $response->getHeader('location'));

        $actualizado = (new ProductRepository($this->db))->find($producto->id);
        self::assertSame('Teclado mecánico K80 pro', $actualizado?->nombre);
        self::assertSame('teclado-mecanico-k80-pro', $actualizado?->slug);
    }

    public function testEliminarProducto(): void
    {
        $this->session->start();

        $producto = (new ProductRepository($this->db))->findBySlug('mouse-ergonomico-vertical-mx-anywhere');
        self::assertNotNull($producto);

        $response = $this->send('POST', '/productos/'.$producto->id.'/eliminar', [
            '_token' => $this->session->token(),
        ]);

        self::assertSame(302, $response->status());
        self::assertNull((new ProductRepository($this->db))->find($producto->id));
        self::assertStringContainsString('Se eliminó', $this->get('/productos')->body());
    }

    public function testParametrosMultiples(): void
    {
        $response = $this->get('/demo/uno/dos/tres');

        self::assertSame(200, $response->status());
        self::assertStringContainsString(':a', $response->body());
        self::assertStringContainsString('<td>uno</td>', $response->body());
    }

    public function testLosErroresDeLaPlantillaNoDejanBufferAbierto(): void
    {
        $before = ob_get_level();

        $response = $this->get('/productos/teclado-mecanico-inalambrico-k80');

        self::assertSame(200, $response->status());
        self::assertSame($before, ob_get_level());
    }

    public function testLosErroresDeRutaTambienSalenDecorados(): void
    {
        // El 404 lo lanza el controller (findOrFail) dentro del pipeline, así que
        // RequestId todavía puede ponerle sus cabeceras al subir.
        $noEncontrado = $this->get('/productos/algo-que-no-existe');

        self::assertSame(404, $noEncontrado->status());
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (string) $noEncontrado->getHeader('x-request-id'));
        self::assertStringContainsString('Página no encontrada', $noEncontrado->body());

        // Lo mismo con el 303 de validación fallida.
        $this->session->start();
        $invalido = $this->send('POST', '/productos', [
            '_token' => $this->session->token(),
            'nombre' => '',
        ]);

        self::assertSame(303, $invalido->status());
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (string) $invalido->getHeader('x-request-id'));
        self::assertMatchesRegularExpression('/^\d+ ms$/', (string) $invalido->getHeader('x-response-time'));
        self::assertNotEmpty((string) $invalido->getHeader('location'));
    }

    public function testElContextoDeLogLlegaHastaElError(): void
    {
        $logger = $this->app->make(Logger::class);
        $before = count($logger->records());

        // RequestId anota el id de correlación; el 419 que lanza CsrfMiddleware
        // debe registrarse con ese mismo id, aunque la excepción salte del pipeline.
        $response = $this->send('POST', '/productos', ['nombre' => 'sin token']);
        self::assertSame(419, $response->status());

        $records = array_slice($logger->records(), $before);
        $withId = array_values(array_filter($records, static fn (array $r): bool => isset($r['context']['request_id'])));

        self::assertNotEmpty($withId, 'el middleware deja el id en el contexto');
        self::assertNotEmpty(array_filter($records, static fn (array $r): bool => ($r['context']['request_id'] ?? null) === ($withId[0]['context']['request_id']) && $r['level'] === 'notice'),
            'la línea del error comparte el id de correlación');
        self::assertSame([], $logger->context(), 'y el contexto no sobrevive a la petición');
    }

    public function testUrlYAssetsConPrefijos(): void
    {
        self::assertSame('/', $this->app->url('/'));
        self::assertSame('/productos', $this->app->url('productos'));
        self::assertSame('/assets/app.css', asset('assets/app.css'));
        self::assertSame('/productos/1', route('products.show', ['idOrSlug' => 1]));
    }

    public function testPrefijosAutodetectados(): void
    {
        self::assertSame(['/rutas_amigables', '/rutas_amigables/public'], App::detectPrefixes(
            '/var/www/html/rutas_amigables/public/index.php',
            '/var/www/html'
        ));

        // Docroot en la raíz del repo, sin public/ intermedio: las rutas y los
        // assets comparten prefijo.
        self::assertSame(['/rutas_amigables', '/rutas_amigables'], App::detectPrefixes(
            '/var/www/html/rutas_amigables/index.php',
            '/var/www/html'
        ));

        // SCRIPT_FILENAME fuera del docroot (symlinks, phar): no se inventa nada.
        self::assertSame(['', ''], App::detectPrefixes('/otro/sitio/public/index.php', '/var/www/html'));
        self::assertSame(['', ''], App::detectPrefixes('', ''));

        [$routePrefix, $publicPrefix] = App::detectPrefixes('/var/www/html/public/index.php', '/var/www/html');
        self::assertSame('', $routePrefix);
        self::assertSame('', $publicPrefix);

        [$routePrefix, $publicPrefix] = App::detectPrefixes('/srv/app/public/index.php', '/srv/app');
        self::assertSame('', $routePrefix);
        self::assertSame('', $publicPrefix);
    }

    public function testStrYEscaping(): void
    {
        self::assertSame('teclado-mecanico-nino', Str::slug('Teclado Mecánico Niño'));
        self::assertSame('a-b', Str::slug('  a//b  '));
        self::assertSame('', Str::slug('!!!'));
        self::assertSame('un dos…', Str::limit('un dos tres cuatro', 7));
        self::assertSame('corto', Str::limit('corto', 10));
        self::assertSame('/ruta', Str::normalizePath('/ruta/'));
        self::assertSame('/', Str::normalizePath('/'));

        $this->app->view()->addNamespace('fixtures', __DIR__.'/Fixtures/views');

        self::assertSame('<span>hola</span>', trim(partial('fixtures::partial', ['valor' => 'hola'])), 'partial() pinta sin layout');
        self::assertStringContainsString('<!DOCTYPE html>', view('params', ['params' => ['a' => 'uno']]));
        self::assertStringContainsString('Parámetros de ruta', view('params', ['params' => []]));

        self::assertSame('&lt;script&gt;x&lt;/script&gt;', e('<script>x</script>'));
        self::assertSame('a&apos;b&quot;c', e('a\'b"c'));
        self::assertSame('', e(null));
        self::assertSame('1', e(1));
    }

    public function testElAutoloaderEncuentraLasClasesDelProyecto(): void
    {
        self::assertTrue(class_exists(\SimpleMvc\Core\App::class));
        self::assertTrue(class_exists(\App\Controllers\ProductsController::class));
        self::assertTrue(interface_exists(\JsonSerializable::class));
        self::assertFalse(\SimpleMvc\Core\Autoloader::load('SimpleMvc\\Core\\ClaseQueNoExiste'));
    }
}
