<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use Closure;
use SimpleMvc\Support\Str;
use Throwable;

/**
 * Kernel de la aplicación: configuración, contenedor, router y pipeline.
 *
 * El `index.php` original creaba el Router, registraba rutas y despachaba en
 * el mismo archivo. Aquí el frente de control queda en cinco líneas y todo lo
 * demás es componible y testeable (`App::boot()` + `handle()` devuelven una
 * Response sin necesitar un servidor web).
 */
final class App
{
    private static ?self $instance = null;

    private Container $container;

    private ?Config $config = null;

    private ?Router $router = null;

    private ?ErrorHandler $errorHandler = null;

    private string $routePrefix = '';

    private string $publicPrefix = '';

    private bool $booted = false;

    /** @var array<int, Closure> */
    private array $bootListeners = [];

    public function __construct(private string $basePath, ?Container $container = null)
    {
        $this->container = $container ?? new Container();
        self::$instance = $this;
    }

    public static function boot(string $basePath, ?Container $container = null): self
    {
        return (new self($basePath, $container))->register();
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * Reinicia el singleton. Solo para la suite de pruebas.
     */
    public static function setInstance(?self $app): void
    {
        self::$instance = $app;
    }

    public function register(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->booted = true;
        $this->basePath = rtrim(str_replace('\\', '/', $this->basePath), '/');

        // Permite inyectar configuración y servicios desde fuera (pruebas,
        // bin/console) sin tocar el filesystem real.
        if (!$this->container->bound(Config::class)) {
            $this->container->instance(Config::class, Config::load($this->basePath));
        }

        $this->config = $this->container->make(Config::class);
        $this->applyPrefixes();

        date_default_timezone_set((string) $this->config->get('app.timezone', 'UTC'));

        // asset() usa app.public_url como base de los archivos de public/.
        $this->config->set('app.public_url', $this->publicPrefix);
        $this->container->instance(self::class, $this);
        $this->container->instance(App::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Config::class, $this->config);

        $isConsole = PHP_SAPI === 'cli';

        if (!$this->container->bound(Logger::class)) {
            $this->container->singleton(Logger::class, fn () => new Logger(
                (string) $this->config->get('logging.path'),
                (string) $this->config->get('logging.level', 'debug')
            ));
        }

        if (!$this->container->bound(Database::class)) {
            $this->container->singleton(Database::class, fn () => new Database($this->config));
        }

        if (!$this->container->bound(Session::class)) {
            $this->container->singleton(Session::class, fn () => new Session(
                (string) $this->config->get('session.name', 'simplemvc_session'),
                (int) $this->config->get('session.lifetime', 7200),
                native: !($isConsole && !isset($_SERVER['REQUEST_METHOD'])),
                secure: $this->detectHttps()
            ));
        }

        if (!$this->container->bound(View::class)) {
            $this->container->singleton(View::class, fn () => new View(
                (string) $this->config->get('app.paths.views', $this->basePath.'/templates'),
                (string) $this->config->get('app.views.layout', 'layout'),
                $this->make(Session::class)
            ));
        }

        if (!$this->container->bound(Router::class)) {
            $this->container->singleton(Router::class, fn () => new Router($this->container, $this->routePrefix));
        }

        $this->view()->share([
            'app_name' => (string) $this->config->get('app.name', 'simpleMVC'),
            'base_url' => $this->routePrefix,
            'debug' => $this->config->isDebug(),
        ]);

        $this->errorHandler = new ErrorHandler($this->config, $this->make(Logger::class), $this->view());
        $this->errorHandler->register();

        $this->registerRoutes();

        foreach ($this->bootListeners as $listener) {
            $listener($this);
        }

        $this->bootListeners = [];

        return $this;
    }

    public function booted(Closure $listener): void
    {
        $this->booted ? $listener($this) : $this->bootListeners[] = $listener;
    }

    public function config(): Config
    {
        return $this->config ??= Config::load($this->basePath);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->container->make($abstract, $parameters);
    }

    public function router(): Router
    {
        return $this->make(Router::class);
    }

    public function view(): View
    {
        return $this->make(View::class);
    }

    public function db(): Database
    {
        return $this->make(Database::class);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function routePrefix(): string
    {
        return $this->routePrefix;
    }

    public function publicPrefix(): string
    {
        return $this->publicPrefix;
    }

    /**
     * Carga routes/web.php (o el archivo que se indique).
     */
    public function registerRoutes(?string $file = null): void
    {
        $file ??= $this->basePath.'/routes/web.php';

        if (!is_file($file)) {
            return;
        }

        $definition = require $file;

        if ($definition instanceof Closure) {
            $definition($this->router(), $this);

            return;
        }

        // Por si el archivo ejecuta el registro directamente (estilo del
        // proyecto original, donde index.php llamaba a $router->add()).
    }

    /**
     * Punto de entrada: captura la petición global, despacha y envía.
     */
    public function run(): Response
    {
        $request = Request::capture($this->routePrefix);
        $response = $this->handle($request);
        $response->send();

        return $response;
    }

    public function handle(Request $request): Response
    {
        $this->container->instance(Request::class, $request);
        $this->view()->share([
            'current_path' => $request->path(),
            'current_query' => $request->query(),
            'request_uri' => $request->fullUrl(),
        ]);

        $session = null;

        try {
            $session = $this->make(Session::class);
            $session->start();

            $response = $this->router()->dispatch($request);
        } catch (Throwable $e) {
            $response = $this->errorHandler->handle($e, $request);
        }

        // Los flashes creados en esta petición pasan a estar disponibles en la
        // siguiente. Se hace siempre, también cuando la ruta falló.
        $session?->ageFlashData();

        return $this->finalize($response);
    }

    private function finalize(Response $response): Response
    {
        if (!$response->getHeader('Content-Language')) {
            $response = $response->withHeader('Content-Language', 'es');
        }

        return $response;
    }

    /**
     * URL interna con el prefijo de instalación.
     */
    public function url(string $path = ''): string
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');

        if ($this->routePrefix === '') {
            return $path;
        }

        return $path === '/' ? $this->routePrefix.'/' : $this->routePrefix.$path;
    }

    /**
     * Ruta del directorio público (assets) relativa al docroot.
     */
    public function assetUrl(string $path): string
    {
        return $this->publicPrefix.'/'.ltrim($path, '/');
    }

    private function detectHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * Distingue dos prefijos, que no siempre coinciden:
     *  - routePrefix: el trozo de URL que hay que quitar para leer las rutas
     *    (p. ej. /rutas_amigables cuando el proyecto vive en un subdirectorio).
     *  - publicPrefix: dónde están los archivos públicos respecto del docroot
     *    (p. ej. /public si el docroot apunta a la raíz del repo).
     *
     * Se calculan comparando DOCUMENT_ROOT con la ubicación física del script,
     * así que funciona igual con Apache, nginx o `php -S`.
     *
     * @return array{0: string, 1: string}
     */
    public static function detectPrefixes(string $scriptFile, string $documentRoot): array
    {
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptFile)), '/');
        $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

        if ($scriptDir === '' || $scriptDir === '/' || $documentRoot === '' || !str_starts_with($scriptDir, $documentRoot)) {
            return ['', ''];
        }

        $publicPrefix = substr($scriptDir, strlen($documentRoot));

        // Docroot apuntando ya a public/: no hay prefijo que quitar ni añadir.
        if ($publicPrefix === '' || $publicPrefix === '/' || $publicPrefix === '/public') {
            return ['', ''];
        }

        $routePrefix = str_ends_with($publicPrefix, '/public')
            ? substr($publicPrefix, 0, -strlen('/public'))
            : $publicPrefix;

        return [$routePrefix, $publicPrefix];
    }

    private function applyPrefixes(): void
    {
        $configured = trim((string) $this->config->get('app.base_path', ''));
        $configuredPublic = trim((string) $this->config->get('app.public_prefix', ''));

        if ($configured !== '' || $configuredPublic !== '') {
            $this->routePrefix = $configured === '' ? '' : '/'.trim(Str::normalizePath($configured), '/');
            $this->publicPrefix = $configuredPublic === '' ? $this->routePrefix : '/'.trim(Str::normalizePath($configuredPublic), '/');

            return;
        }

        $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');

        if ($script === '') {
            $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $script = $scriptName === ''
                ? ''
                : rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/').'/'.ltrim($scriptName, '/');
        }

        [$this->routePrefix, $this->publicPrefix] = self::detectPrefixes($script, (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    }

    public function errorHandling(): ErrorHandler
    {
        return $this->errorHandler ??= new ErrorHandler(
            $this->config(),
            $this->make(Logger::class),
            $this->make(View::class)
        );
    }
}
