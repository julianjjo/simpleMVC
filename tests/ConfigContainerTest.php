<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleMvc\Core\Config;
use SimpleMvc\Core\Container;
use SimpleMvc\Core\Request;
use SimpleMvc\Core\Response;
use SimpleMvc\Support\Env;
use Tests\Support\FakeController;
use Tests\Support\SimpleMiddleware;

final class ConfigContainerTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/simplemvc-config-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir.'/config', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/config/*.php') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($this->tmpDir.'/.env');
        @rmdir($this->tmpDir.'/config');
        @rmdir($this->tmpDir);
    }

    // -----------------------------------------------------------------
    // Env
    // -----------------------------------------------------------------

    public function testParseaUnEnv(): void
    {
        $parsed = Env::parse(<<<'ENV'
            # comentario
            SIMPLEMVC_TEST_TEXTO=hola mundo
            SIMPLEMVC_TEST_CITADO = "con  espacios"
            SIMPLEMVC_TEST_COMILLA='literal \n'
            export SIMPLEMVC_TEST_EXPORT=si
            SIMPLEMVC_TEST_VACIO=
            SIMPLEMVC_TEST_INLINE=valor # resto ignorado
            ; comentario de punto y coma
            MALFORMADA
            SIMPLEMVC_TEST_IGUAL=a=b
            ENV);

        self::assertSame('hola mundo', $parsed['SIMPLEMVC_TEST_TEXTO']);
        self::assertSame('con  espacios', $parsed['SIMPLEMVC_TEST_CITADO']);
        self::assertSame('literal \\n', $parsed['SIMPLEMVC_TEST_COMILLA']);
        self::assertSame('si', $parsed['SIMPLEMVC_TEST_EXPORT']);
        self::assertSame('', $parsed['SIMPLEMVC_TEST_VACIO']);
        self::assertSame('valor', $parsed['SIMPLEMVC_TEST_INLINE']);
        self::assertSame('a=b', $parsed['SIMPLEMVC_TEST_IGUAL']);
        self::assertArrayNotHasKey('MALFORMADA', $parsed);
    }

    public function testLoadYEscalaDeTipos(): void
    {
        file_put_contents($this->tmpDir.'/.env', "SIMPLEMVC_TEST_ON=true\nSIMPLEMVC_TEST_OFF=false\nSIMPLEMVC_TEST_N=42\nSIMPLEMVC_TEST_TXT=\"hola\"\n");

        $loaded = Env::load($this->tmpDir.'/.env');

        self::assertGreaterThan(0, $loaded);
        self::assertTrue(Env::bool('SIMPLEMVC_TEST_ON'));
        self::assertFalse(Env::bool('SIMPLEMVC_TEST_OFF'));
        self::assertTrue(Env::bool('SIMPLEMVC_TEST_INEXISTENTE', true));
        self::assertSame(42, Env::int('SIMPLEMVC_TEST_N'));
        self::assertSame(7, Env::int('SIMPLEMVC_TEST_INEXISTENTE', 7));
        self::assertSame('hola', Env::get('SIMPLEMVC_TEST_TXT'));
        self::assertNull(Env::get('SIMPLEMVC_TEST_NOPE'));

        unset($_ENV['SIMPLEMVC_TEST_ON'], $_ENV['SIMPLEMVC_TEST_OFF'], $_ENV['SIMPLEMVC_TEST_N'], $_ENV['SIMPLEMVC_TEST_TXT']);
    }

    public function testCargaDeEnvInexistenteNoExplota(): void
    {
        self::assertSame(0, Env::load($this->tmpDir.'/no-hay.env'));
    }

    // -----------------------------------------------------------------
    // Config
    // -----------------------------------------------------------------

    public function testConfigPorArchivoYNotacionDePuntos(): void
    {
        file_put_contents($this->tmpDir.'/config/app.php', "<?php\nreturn ['name' => 'demo', 'nested' => ['deep' => ['x' => 1]]];\n");
        file_put_contents($this->tmpDir.'/config/db.php', "<?php\nreturn ['driver' => 'sqlite'];\n");

        $config = Config::load($this->tmpDir);

        self::assertSame('demo', $config->get('app.name'));
        self::assertSame(1, $config->get('app.nested.deep.x'));
        self::assertSame('sqlite', $config->get('db.driver'));
        self::assertSame('por-defecto', $config->get('nada.aqui', 'por-defecto'));
        self::assertTrue($config->has('db.driver'));
        self::assertFalse($config->has('db.inexistente'));

        $config->set('db.driver', 'mysql');
        self::assertSame('mysql', $config->get('db.driver'));

        $config->set('nuevo.item', true);
        self::assertTrue($config->get('nuevo.item'));
    }

    public function testConfigSinArchivos(): void
    {
        $config = Config::load($this->tmpDir);

        self::assertSame([], $config->all());
        self::assertFalse($config->isDebug());
        self::assertSame('dev', $config->environment());
    }

    public function testElProyectoTieneConfiguracionLegible(): void
    {
        $config = Config::load(dirname(__DIR__));

        self::assertSame('simpleMVC', $config->get('app.name'));
        self::assertContains($config->get('database.driver'), ['sqlite', 'mysql', 'mariadb']);
        self::assertSame('layout', $config->get('app.views.layout'));
    }

    // -----------------------------------------------------------------
    // Container
    // -----------------------------------------------------------------

    public function testAutowiringPorConstructor(): void
    {
        $container = new Container();
        $container->instance(Request::class, new Request(method: 'POST', path: '/x'));
        $container->bind(SimpleMiddleware::class, fn () => new SimpleMiddleware());

        $controller = $container->make(FakeController::class);

        self::assertInstanceOf(FakeController::class, $controller);
    }

    public function testSingletonSeReutiliza(): void
    {
        $container = new Container();
        $container->singleton(FakeController::class, fn () => new FakeController('unico'));

        self::assertSame($container->make(FakeController::class), $container->make(FakeController::class));
    }

    public function testParametrosExplicitosGanan(): void
    {
        $container = new Container();
        $controller = $container->make(FakeController::class, ['name' => 'manual']);

        self::assertSame('manual:1:q', $controller->show(new Request(query: ['q' => 'q']), 1));
    }

    public function testClaseInexistente(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $container->make('No\\Existe\\Esta\\Clase');
    }

    public function testDependenciaCircularSeDetecta(): void
    {
        $container = new Container();
        $container->bind('Tests\\Support\\CircularA', fn (Container $c) => $c->make('Tests\\Support\\CircularB'));
        $container->bind('Tests\\Support\\CircularB', fn (Container $c) => $c->make('Tests\\Support\\CircularA'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/circular/i');

        $container->make('Tests\\Support\\CircularA');
    }

    public function testCallInyectaServicios(): void
    {
        $container = new Container();
        $response = Response::html('x');
        $container->instance(Response::class, $response);

        $result = $container->call(fn (Response $r, string $extra = 'vacio') => $extra.':'.$r->status(), ['extra' => 'llamado']);

        self::assertSame('llamado:200', $result);
    }

    public function testBoundSoloCuentaRegistrados(): void
    {
        $container = new Container();

        self::assertFalse($container->bound(FakeController::class));
        self::assertTrue($container->has(FakeController::class), 'has() también acepta clases existentes');

        $container->instance(FakeController::class, new FakeController());
        self::assertTrue($container->bound(FakeController::class));
    }
}
