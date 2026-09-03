<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use Closure;
use SimpleMvc\Exceptions\HttpException;
use SimpleMvc\Exceptions\MethodNotAllowedHttpException;
use SimpleMvc\Exceptions\NotFoundHttpException;
use Throwable;

/**
 * Router con soporte de métodos HTTP, grupos, middleware, rutas nombradas,
 * comodines, fallback y verificación CSRF opcional.
 *
 * Mantiene la API del proyecto original (`$router->add($uri, $action)` y
 * `:param`) para que las rutas viejas sigan funcionando.
 */
final class Router
{
    public const CSRF_TOKEN_KEY = '_token';

    private const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @var array<int, Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $namedRoutes = [];

    /** @var array<int, string|callable> */
    private array $middleware = [];

    /** @var (Closure(Throwable, Request): Response)|null */
    private $exceptionHandler = null;

    /** @var array<int, array{prefix: string, middleware: array<int, string|callable>, as: string, where: array<string, string>}> */
    private array $groupStack = [];

    private ?Route $fallbackRoute = null;

    /** @var array<string, string> */
    private array $csrfWhitelist = [];

    public function __construct(private Container $container, private string $basePath = '')
    {
    }

    // -----------------------------------------------------------------
    // Registro
    // -----------------------------------------------------------------

    public function add(string $uri, mixed $action): Route
    {
        return $this->register(['GET', 'POST', 'HEAD'], $uri, $action);
    }

    public function get(string $uri, mixed $action): Route
    {
        return $this->register(['GET'], $uri, $action);
    }

    public function post(string $uri, mixed $action): Route
    {
        return $this->register(['POST'], $uri, $action);
    }

    public function put(string $uri, mixed $action): Route
    {
        return $this->register(['PUT'], $uri, $action);
    }

    public function patch(string $uri, mixed $action): Route
    {
        return $this->register(['PATCH'], $uri, $action);
    }

    public function delete(string $uri, mixed $action): Route
    {
        return $this->register(['DELETE'], $uri, $action);
    }

    public function options(string $uri, mixed $action): Route
    {
        return $this->register(['OPTIONS'], $uri, $action);
    }

    /**
     * @param  string|string[]  $methods
     */
    public function match(string|array $methods, string $uri, mixed $action): Route
    {
        $methods = is_array($methods) ? $methods : [$methods];
        $methods = array_values(array_intersect($methods, self::ALLOWED_METHODS));

        if ($methods === []) {
            throw new \RuntimeException('Métodos HTTP no soportados: '.implode(', ', (array) $methods));
        }

        return $this->register($methods, $uri, $action);
    }

    public function any(string $uri, mixed $action): Route
    {
        return $this->register(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $uri, $action);
    }

    /**
     * Ruta que se usa cuando ninguna otra coincidió (p. ej. para pintar
     * un 404 propio o montar un SPA). No interfiere con el 405.
     */
    public function fallback(mixed $action): Route
    {
        $route = new Route('/*', $action);
        $route->methods(self::ALLOWED_METHODS);

        $top = $this->groupStack === [] ? [] : end($this->groupStack);

        foreach ($this->middlewareFor($top['middleware'] ?? []) as $middleware) {
            $route->middleware($middleware);
        }

        $this->fallbackRoute = $route;

        return $route;
    }

    /**
     * Agrupa rutas bajo un prefijo, middleware, nombre o restricciones comunes.
     *
     * @param  array{prefix?: string, middleware?: string|string[]|callable|callable[], as?: string, where?: array<string, string>}  $attributes
     */
    public function group(array $attributes, Closure|callable $callback): void
    {
        $previous = $this->groupStack === [] ? null : end($this->groupStack);

        $prefix = ($previous['prefix'] ?? '').$this->sanitizePrefix((string) ($attributes['prefix'] ?? ''));
        $middleware = array_merge($previous['middleware'] ?? [], (array) ($attributes['middleware'] ?? []));
        $as = ($previous['as'] ?? '').(string) ($attributes['as'] ?? '');
        $where = array_merge($previous['where'] ?? [], (array) ($attributes['where'] ?? []));

        $this->groupStack[] = [
            'prefix' => $prefix,
            'middleware' => $middleware,
            'as' => $as,
            'where' => $where,
        ];

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * Middleware global para todas las rutas registradas después de llamarlo.
     *
     * @param  string|callable  $middleware
     */
    /**
     * Middleware global: se aplica a las rutas registradas después de la llamada.
     *
     * @param  string|string[]|callable  $middleware
     */
    public function middleware(array|string|callable $middleware): self
    {
        foreach ((array) $middleware as $item) {
            if (!in_array($item, $this->middleware, true)) {
                $this->middleware[] = $item;
            }
        }

        return $this;
    }

    /**
     * @param  array<int, string|callable>  $extra
     * @return array<int, string|callable>
     */
    private function middlewareFor(array $extra): array
    {
        $all = array_merge($this->middleware, $extra);
        $unique = [];

        foreach ($all as $item) {
            if (!in_array($item, $unique, true)) {
                $unique[] = $item;
            }
        }

        return $unique;
    }

    /**
     * Exige un token CSRF válido en las rutas que se registren después
     * (o en todo el grupo). Solo aplica a métodos que modifican datos.
     */
    public function requireCsrf(bool $enabled = true): void
    {
        if ($enabled) {
            $this->middleware(CsrfMiddleware::class);

            return;
        }

        $this->middleware = array_values(array_filter(
            $this->middleware,
            static fn (mixed $item): bool => $item !== CsrfMiddleware::class
        ));
    }

    private function sanitizePrefix(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');

        return $prefix === '' ? '' : '/'.$prefix;
    }

    private function register(array $methods, string $uri, mixed $action): Route
    {
        $top = $this->groupStack === [] ? null : end($this->groupStack);
        $uri = ($top['prefix'] ?? '').'/'.trim($uri, '/');
        $route = new Route($uri, $action);
        $route->methods($methods === ['GET'] ? ['GET', 'HEAD'] : $methods);

        // El middleware global del router y el de los grupos activos. Sin esto,
        // un $router->middleware(...) antes de registrar rutas no hacía nada.
        foreach ($this->middlewareFor($top['middleware'] ?? []) as $middleware) {
            $route->middleware($middleware);
        }

        foreach ($top['where'] ?? [] as $parameter => $pattern) {
            $route->where((string) $parameter, $pattern);
        }

        $route->namePrefix((string) ($top['as'] ?? ''));
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Devuelve un closure que convierte una excepción en respuesta. Se usa
     * dentro del pipeline (ver runRoute()) para que los middleware que siguen
     * envolviendo la acción puedan decorar también la respuesta de error:
     * cabeceras de seguridad, tiempos, CORS, id de correlación.
     *
     * @param  (callable(Throwable, Request): Response)|null  $handler
     */
    public function exceptionHandler(?callable $handler): self
    {
        $this->exceptionHandler = $handler === null ? null : Closure::fromCallable($handler);

        return $this;
    }

    // -----------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------

    public function dispatch(Request $request): Response
    {
        $path = $request->path();
        $allowed = [];

        foreach ($this->routes as $route) {
            $parameters = $route->match($path);

            if ($parameters === null) {
                continue;
            }

            if (!$route->allowsMethod($request->method())) {
                $allowed = array_merge($allowed, $route->allowedMethods());

                continue;
            }

            return $this->runRoute($route, $request->withAttributes($parameters));
        }

        // Un método no permitido tiene prioridad sobre el fallback: si existe
        // la ruta pero con otro verbo, eso es 405, no 404.
        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException($allowed, "Método {$request->method()} no permitido para {$path}.");
        }

        if ($this->fallbackRoute !== null) {
            return $this->runRoute($this->fallbackRoute, $request->withAttributes(['wildcard' => ltrim($path, '/')]));
        }

        throw new NotFoundHttpException("No hay ninguna ruta para {$request->method()} {$path}");
    }

    /**
     * Ejecuta la ruta envuelta en su pipeline de middleware.
     */
    private function runRoute(Route $route, Request $request): Response
    {
        $destination = function (Request $request) use ($route): Response {
            try {
                $result = $route->run($this->container, $request, $request->attributes());

                return $result instanceof Response ? $result : Response::make($result);
            } catch (Throwable $e) {
                // Sin handler se propaga (así funciona el router suelto en los
                // tests y si alguien lo reusa fuera de App).
                if ($this->exceptionHandler === null) {
                    throw $e;
                }

                return ($this->exceptionHandler)($e, $request);
            }
        };

        $pipeline = array_reverse($route->middlewareList());

        foreach ($pipeline as $middleware) {
            $next = $destination;

            $destination = function (Request $request) use ($middleware, $next): Response {
                $handler = $this->resolveMiddleware($middleware);
                $response = $handler($request, $next);

                return $response instanceof Response ? $response : Response::make($response);
            };
        }

        $response = $destination($request);

        return $this->prepareResponse($response);
    }

    /**
     * @param  string|callable  $middleware
     */
    private function resolveMiddleware(string|callable $middleware): callable
    {
        if (is_callable($middleware)) {
            return $middleware;
        }

        $instance = $this->container->make($middleware);

        if (method_exists($instance, 'handle')) {
            return [$instance, 'handle'];
        }

        if (method_exists($instance, '__invoke')) {
            return [$instance, '__invoke'];
        }

        throw new \RuntimeException("El middleware [{$middleware}] debe tener un método handle() o __invoke().");
    }

    private function prepareResponse(Response $response): Response
    {
        foreach ($this->container->make(Session::class)->pendingCookies() as $cookie) {
            $response = $response->withCookie($cookie['name'], $cookie['value'], $cookie['options']);
        }

        return $response->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"
            );
    }

    // -----------------------------------------------------------------
    // Utilidades
    // -----------------------------------------------------------------

    /**
     * @return array<int, Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function find(string $name): ?Route
    {
        return $this->nameIndex()[$name] ?? null;
    }

    /**
     * Índice nombre => ruta. Se construye al primer uso porque las rutas
     * pueden recibir su nombre después de registrarse (API fluida).
     *
     * @return array<string, Route>
     */
    public function nameIndex(): array
    {
        if ($this->namedRoutes !== []) {
            return $this->namedRoutes;
        }

        foreach ($this->routes as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            if (isset($this->namedRoutes[$name])) {
                throw new \RuntimeException("Nombre de ruta duplicado: {$name}");
            }

            $this->namedRoutes[$name] = $route;
        }

        return $this->namedRoutes;
    }

    /**
     * URL generada a partir del nombre de la ruta.
     *
     * @param  array<string, int|string>  $parameters
     */
    public function url(string $name, array $parameters = []): string
    {
        $route = $this->find($name) ?? throw new \RuntimeException("La ruta '{$name}' no está registrada con ese nombre.");

        return $this->basePath.$route->build($parameters);
    }

    public function basePath(): string
    {
        return $this->basePath;
    }
}
