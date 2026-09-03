<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use SimpleMvc\Exceptions\NotFoundHttpException;
use RuntimeException;

/**
 * Una ruta: URI con parámetros + acción + métodos HTTP permitidos.
 *
 * Cambios frente al `Route` original:
 *  - `preg_quote()` sobre los segmentos literales (antes `:id` o un `.` en la
 *    URI se interpretaban como regex).
 *  - los parámetros se pasan como lista posicional; `call_user_func_array` con
 *    claves nombradas revienta en PHP 8 ("Unknown named parameter").
 *  - soporta RESTricciones por parámetro, nombres de ruta, middleware,
 *    comodines y métodos HTTP.
 */
final class Route
{
    /**
     * :nombre  ó  :nombre(regex)
     */
    public const PARAMETER_PATTERN = ':([a-zA-Z_][a-zA-Z0-9_]*)(?:\(((?:[^()]|\([^()]*\))*)\))?';

    private const WILDCARD = '*';

    private ?string $compiled = null;

    /** @var string[] */
    private array $parameterNames = [];

    /** @var array<string, string> */
    private array $constraints = [];

    /** @var string[] */
    private array $methods = ['GET'];

    /** @var array<int, string|callable> */
    private array $middleware = [];

    private ?string $name = null;

    private string $namePrefix = '';

    public function __construct(private string $uri, private mixed $action)
    {
        // Ojo: aquí NO se normalizan las barras invertidas — formarían parte de
        // las restricciones regex de los parámetros, p. ej. :id(\d+).
        $this->uri = '/'.trim($uri, '/');

        if ($this->uri !== '/') {
            $this->uri = rtrim($this->uri, '/');
        }

        if ($this->action === null || $this->action === '' || $this->action === []) {
            throw new RuntimeException("La ruta {$this->uri} no tiene ninguna acción asociada.");
        }
    }

    // -----------------------------------------------------------------
    // Fluente
    // -----------------------------------------------------------------

    /**
     * @param  string|string[]  $methods
     */
    public function methods(string|array $methods): self
    {
        $methods = is_array($methods) ? $methods : (preg_split('/[\s|,]+/', $methods) ?: []);
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $method): string => strtoupper(trim($method)),
            $methods
        ))));

        if ($normalized === []) {
            throw new RuntimeException("La ruta {$this->uri} debe aceptar al menos un método HTTP.");
        }

        $this->methods = $normalized;
        $this->addHeadForGet();

        return $this;
    }

    /**
     * @param  string|string[]|callable  $middleware
     */
    public function middleware(string|array|callable $middleware): self
    {
        foreach ((array) $middleware as $item) {
            $this->middleware[] = $item;
        }

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Prefijo aportado por Router::group(['as' => 'products.']).
     */
    public function namePrefix(string $prefix): self
    {
        $this->namePrefix = $prefix;

        return $this;
    }

    /**
     * Restringe el contenido de un parámetro con una expresión regular.
     */
    public function where(string $parameter, string $pattern): self
    {
        $this->constraints[$parameter] = $pattern;
        $this->compiled = null;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function constraints(): array
    {
        return $this->constraints;
    }

    // -----------------------------------------------------------------
    // Introspección
    // -----------------------------------------------------------------

    public function uri(): string
    {
        return $this->uri;
    }

    public function action(): mixed
    {
        return $this->action;
    }

    public function getName(): ?string
    {
        return $this->name === null ? null : $this->namePrefix.$this->name;
    }

    /**
     * Nombre sin el prefijo del grupo.
     */
    public function rawName(): ?string
    {
        return $this->name;
    }

    /**
     * Métodos que acepta esta ruta (GET añade HEAD implícitamente).
     *
     * @return string[]
     */
    public function allowedMethods(): array
    {
        return $this->methods;
    }

    /**
     * @return string[]
     */
    public function parameterNames(): array
    {
        $this->compile();

        return $this->parameterNames;
    }

    /**
     * @return array<int, string|callable>
     */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    public function allowsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    // -----------------------------------------------------------------
    // Coincidencia
    // -----------------------------------------------------------------

    public function regex(): string
    {
        $this->compile();

        /** @var string $this->compiled */
        return $this->compiled;
    }

    public function matchesPath(string $path): bool
    {
        return preg_match($this->regex(), $this->normalize($path)) === 1;
    }

    /**
     * @return array<string, string>|null  null cuando la ruta no coincide
     */
    public function match(string $path): ?array
    {
        $matches = [];

        if (preg_match($this->regex(), $this->normalize($path), $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($this->parameterNames as $name) {
            if (!isset($matches[$name]) || $matches[$name] === '') {
                continue;
            }

            $parameters[$name] = $this->decode((string) $matches[$name]);
        }

        return $parameters;
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    private function decode(string $value): string
    {
        $decoded = rawurldecode($value);

        return $decoded === '' ? $value : $decoded;
    }

    private function addHeadForGet(): void
    {
        if (in_array('GET', $this->methods, true) && !in_array('HEAD', $this->methods, true)) {
            $this->methods[] = 'HEAD';
        }
    }

    private function compile(): void
    {
        if ($this->compiled !== null) {
            return;
        }

        $this->parameterNames = [];
        $segments = array_values(array_filter(explode('/', $this->uri), static fn (string $s): bool => $s !== ''));
        $pattern = '';

        foreach ($segments as $segment) {
            if ($segment === self::WILDCARD) {
                $this->parameterNames[] = 'wildcard';
                $pattern .= '/(?P<wildcard>.*?)';

                continue;
            }

            if (preg_match('/^'.self::PARAMETER_PATTERN.'$/', $segment, $matches) === 1) {
                $name = $matches[1];

                if (in_array($name, $this->parameterNames, true)) {
                    throw new RuntimeException("La ruta {$this->uri} repite el parámetro \${$name}.");
                }

                if (isset($matches[2]) && $matches[2] !== '') {
                    $this->constraints[$name] ??= $matches[2];
                }

                $this->parameterNames[] = $name;
                $pattern .= '/(?P<'.$name.'>('.(isset($this->constraints[$name]) ? $this->constraints[$name] : '[^/]+').'))';

                continue;
            }

            $pattern .= '/'.preg_quote($segment, '#');
        }

        $this->compiled = '#^'.$pattern.'/?$#u';
        $this->addHeadForGet();
    }

    // -----------------------------------------------------------------
    // Ejecución
    // -----------------------------------------------------------------

    /**
     * @param  array<string, string>  $parameters
     */
    public function run(Container $container, Request $request, array $parameters = []): mixed
    {
        $handler = $this->resolveHandler($container);
        $reflection = $this->reflect($handler);

        return $handler(...$this->bindArguments($reflection, $parameters, $request, $container));
    }

    /**
     * Convierte la acción configurada (closure, string, arreglo) en un callable.
     */
    private function resolveHandler(Container $container): callable
    {
        $action = $this->action;

        if ($action instanceof Closure) {
            return $action;
        }

        if (is_object($action) && method_exists($action, '__invoke')) {
            return $action(...);
        }

        if (is_array($action) && count($action) === 2 && is_string($action[0])) {
            $class = $this->resolveClassName($action[0]);

            return [$this->instantiate($container, $class), $action[1]];
        }

        if (is_string($action)) {
            if (str_contains($action, '::')) {
                [$class, $method] = explode('::', $action, 2);

                return [$this->instantiate($container, $this->resolveClassName($class)), $method];
            }

            if (class_exists($action) || class_exists($this->resolveClassName($action))) {
                return [$this->instantiate($container, $this->resolveClassName($action)), '__invoke'];
            }

            if (function_exists($action)) {
                return $action;
            }

            throw new RuntimeException("La acción '{$action}' no es un controlador ni una función existente.");
        }

        if (is_callable($action)) {
            return $action;
        }

        throw new RuntimeException('Acción de ruta no soportada: '.get_debug_type($action));
    }

    /**
     * Permite escribir 'ProductsController::index' como en el proyecto original:
     * si la clase no existe tal cual, se busca bajo App\Controllers\.
     */
    private function resolveClassName(string $class): string
    {
        $class = ltrim($class, '\\');

        if (class_exists($class)) {
            return $class;
        }

        $relative = str_replace(['App\\Controllers\\', 'Controllers\\'], '', $class);

        foreach (['App\\Controllers\\', 'App\\'] as $prefix) {
            if (class_exists($prefix.$relative)) {
                return $prefix.$relative;
            }
        }

        throw new RuntimeException("No se encontró la clase del controlador [{$class}].");
    }

    private function instantiate(Container $container, string $class): object
    {
        return $container->has($class) || class_exists($class)
            ? $container->make($class)
            : throw new RuntimeException("No se encontró la clase del controlador [{$class}].");
    }

    private function reflect(callable $handler): ReflectionFunction|ReflectionMethod
    {
        if (is_array($handler)) {
            return new ReflectionMethod($handler[0], $handler[1]);
        }

        if (is_string($handler) && str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);

            return new ReflectionMethod($class, $method);
        }

        return new ReflectionFunction($handler);
    }

    /**
     * Une los parámetros de la URL con los argumentos declarados de la acción.
     *
     * @param  array<string, string>  $parameters
     * @return array<int, mixed>
     */
    private function bindArguments(
        ReflectionFunction|ReflectionMethod $reflection,
        array $parameters,
        Request $request,
        Container $container
    ): array {
        $values = array_values($parameters);
        $names = array_keys($parameters);
        $cursor = 0;
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                if ($typeName === Request::class || is_subclass_of($typeName, Request::class)) {
                    $arguments[] = $request;

                    continue;
                }

                $arguments[] = $container->make($typeName);

                continue;
            }

            if ($parameter->isVariadic()) {
                $arguments = array_merge($arguments, array_slice($values, $cursor));

                continue;
            }

            if (array_key_exists($name, $parameters)) {
                $arguments[] = $this->coerce($parameters[$name], $type, $name);
                $position = array_search($name, $names, true);

                if ($position !== false) {
                    $cursor = max($cursor, $position + 1);
                }

                continue;
            }

            if (array_key_exists($cursor, $values)) {
                $arguments[] = $this->coerce($values[$cursor], $type, $name);
                ++$cursor;

                continue;
            }

            if ($parameter->isDefaultValueAvailable() || $parameter->allowsNull()) {
                continue;
            }

            throw new RuntimeException("La ruta no provee el parámetro \${$name} que exige la acción.");
        }

        return $arguments;
    }

    /**
     * Coerce cadena => tipo escalar declarado. Con `declare(strict_types=1)`
     * una ruta /productos/3 llega como "3" y sin este paso daría TypeError.
     */
    private function coerce(string $value, ?\ReflectionType $type, string $parameterName): mixed
    {
        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => $this->toInt($value, $parameterName),
            'float' => is_numeric($value) ? (float) $value : $this->invalidNumber($value, $parameterName),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value === '1',
            'string', 'mixed' => $value,
            'array' => throw new RuntimeException("El parámetro de ruta \${$parameterName} no puede ser un arreglo."),
            default => $value,
        };
    }

    private function toInt(string $value, string $parameterName): int
    {
        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $this->invalidNumber($value, $parameterName);
    }

    private function invalidNumber(string $value, string $parameterName): never
    {
        throw new NotFoundHttpException(
            "El parámetro \${$parameterName} («{$value}») no es un número válido."
        );
    }

    /**
     * Genera una URL concreta para esta ruta.
     *
     * @param  array<string, int|string>  $parameters
     */
    public function build(array $parameters = []): string
    {
        // Sin esto, una ruta que nunca llegó a casarse no tiene los nombres de
        // parámetro extraídos y route() devolvía el patrón crudo.
        $this->compile();

        $uri = $this->uri;
        $used = [];

        foreach ($this->parameterNames as $name) {
            if ($name === 'wildcard') {
                continue;
            }

            if (!array_key_exists($name, $parameters)) {
                throw new RuntimeException("Falta el parámetro \${$name} para la ruta {$uri}.");
            }

            $placeholder = ':'.$name;
            $replacement = rawurlencode((string) $parameters[$name]);
            $uri = preg_replace('#(^|/)'.preg_quote($placeholder, '#').'(\([^()]*\))?#', '${1}'.$replacement, $uri, 1) ?? $uri;
            $used[] = $name;
        }

        if (in_array('wildcard', $this->parameterNames, true) && array_key_exists('wildcard', $parameters)) {
            $wildcard = implode('/', array_map(
                static fn (string $part): string => rawurlencode($part),
                explode('/', trim((string) $parameters['wildcard'], '/'))
            ));

            $uri = str_replace(self::WILDCARD, $wildcard, $uri);
            $used[] = 'wildcard';
        }

        $extra = array_diff_key($parameters, array_flip($used));

        return $uri.($extra === [] ? '' : '?'.http_build_query($extra));
    }
}
