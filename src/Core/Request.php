<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

/**
 * Petición HTTP inmutable e independiente de los superglobales.
 *
 * Permite probar el router y los controladores sin simular un servidor web:
 * basta con construir `new Request('GET', '/productos', ...)`.
 */
final class Request
{
    /**
     * Métodos a los que puede reescribirse un POST de formulario con _method.
     *
     * @var string[]
     */
    public const OVERRIDABLE_METHODS = ['PUT', 'PATCH', 'DELETE'];

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private string $method = 'GET',
        private string $path = '/',
        private array $query = [],
        private array $body = [],
        private array $attributes = [],
        private array $server = [],
        private array $cookies = [],
        private array $files = [],
        private array $headers = [],
        private ?string $rawBody = null,
        private string $basePath = '',
    ) {
        // También aquí, y no solo en capture(): las peticiones construidas a mano
        // (sub-request, tests) deben comportarse igual que las reales.
        $this->method = self::applyMethodOverride($this->method, $this->body);
    }

    /**
     * PUT/PATCH/DELETE llegan desde formularios HTML como POST + _method.
     */
    private static function applyMethodOverride(string $method, array $body): string
    {
        if ($method !== 'POST' || !isset($body['_method'])) {
            return $method;
        }

        $override = strtoupper((string) $body['_method']);

        return in_array($override, self::OVERRIDABLE_METHODS, true) ? $override : $method;
    }

    /**
     * Construye la petición a partir de los superglobales.
     *
     * @param  string  $basePath  prefijo de instalación, p. ej. /rutas_amigables
     */
    public static function capture(string $basePath = ''): self
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = self::normalizePath((string) (parse_url($uri, PHP_URL_PATH) ?: '/'), $basePath);

        $body = $_POST;

        // Soporte para PUT/PATCH/DELETE desde formularios HTML con _method.
        $method = self::applyMethodOverride($method, $body);

        $raw = null;

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $raw = self::readInput();

            // JSON en el cuerpo: se fusiona con los datos del formulario.
            if ($body === [] && $raw !== null && $raw !== '') {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        $headers = [];

        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $serverKey => $header) {
            if (isset($server[$serverKey])) {
                $headers[$header] = (string) $server[$serverKey];
            }
        }

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            body: $body,
            attributes: [],
            server: $server,
            cookies: $_COOKIE,
            files: $_FILES,
            headers: $headers,
            rawBody: $raw,
            basePath: $basePath,
        );
    }

    /**
     * Quita el prefijo de instalación, la barra final y el index.php.
     */
    public static function normalizePath(string $path, string $basePath = ''): string
    {
        $path = str_replace('\\', '/', $path);

        if ($basePath !== '') {
            $prefix = '/'.trim($basePath, '/');

            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }
        }

        if (str_ends_with($path, '/index.php')) {
            $path = substr($path, 0, -strlen('/index.php'));
        } elseif ($path === 'index.php') {
            $path = '/';
        }

        $path = '/'.trim($path, '/');

        return $path === '/' ? '/' : $path;
    }

    private static function readInput(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $contents = @file_get_contents('php://input');

        return $contents === false ? null : $contents;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }

    /**
     * Ruta sin query string, sin barra final y sin prefijo de instalación.
     */
    public function path(): string
    {
        return $this->path;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isReadOnly(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    /**
     * Busca primero en el cuerpo y luego en la query string.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }

        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /**
     * Una clave "filled" existe y no está vacía (ni cadena en blanco).
     */
    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '' && !(is_array($value) && $value === []);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        if (is_array($value)) {
            return $default;
        }

        return trim((string) $value);
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->input($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower((string) $value), ['false', 'off', 'no', '0', ''], true);
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function file(?string $key = null): mixed
    {
        return $key === null ? $this->files : ($this->files[$key] ?? null);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * @return array<string, mixed>
     */
    public function server(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }

        return $this->server[$key] ?? $default;
    }

    /**
     * Parámetros extraídos de la ruta (:id, :slug, ...).
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = $attributes;

        return $clone;
    }

    public function withPath(string $path): self
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    /**
     * ¿El cliente prefiere JSON? Se usa para decidir cómo pintar un error.
     */
    public function wantsJson(): bool
    {
        if ($this->query('format') === 'json') {
            return true;
        }

        $accept = (string) ($this->header('accept') ?? '');

        if ($accept !== '' && str_contains($accept, 'application/json')) {
            return true;
        }

        return $this->header('x-requested-with') === 'XMLHttpRequest'
            && !str_contains($accept, 'text/html');
    }

    public function isAjax(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    public function isSecure(): bool
    {
        $https = $this->server('HTTPS');

        if (is_string($https) && $https !== '' && $https !== 'off') {
            return true;
        }

        return $this->header('x-forwarded-proto') === 'https';
    }

    public function host(): string
    {
        $host = (string) ($this->header('host') ?? $this->server('HTTP_HOST') ?? $this->server('SERVER_NAME') ?? 'localhost');

        // El header Host llega del cliente: sin validar, serviría para envenenar
        // enlaces de correo o redirecciones.
        return preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $host) === 1 ? $host : 'localhost';
    }

    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function baseUrl(): string
    {
        return $this->scheme().'://'.$this->host();
    }

    public function fullUrl(): string
    {
        $query = $this->query();

        $uri = $this->basePath().$this->path;

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $uri;
    }

    /**
     * Ruta solicitada tal como llegó (incluye el prefijo de instalación).
     */
    public function requestUri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    public function ip(): string
    {
        return (string) ($this->server('REMOTE_ADDR') ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) ($this->header('user-agent') ?? '');
    }
}
