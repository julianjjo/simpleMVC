<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use JsonSerializable;
use Stringable;

/**
 * Respuesta HTTP.
 *
 * El Router original declaraba `$response instanceof Response` pero la clase
 * Response no existía en el repositorio: ese ramo era código muerto.
 */
final class Response
{
    public const HTML = 'text/html; charset=utf-8';

    public const JSON = 'application/json; charset=utf-8';

    public const TEXT = 'text/plain; charset=utf-8';

    /** @var array<string, array{name: string, value: string}> */
    private array $headers = [];

    /** @var array<int, array{name: string, value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->header((string) $name, (string) $value);
        }

        if (!isset($this->headers['content-type'])) {
            $this->header('Content-Type', self::HTML);
        }
    }

    /**
     * Convierte lo que devolvió el controlador en una respuesta.
     *
     * Mantiene la ergonomía del micro-framework (devolver una cadena pinta
     * HTML, devolver un arreglo pinta JSON) pero añade objetos Response,
     * null => 204 y cualquier objeto serializable.
     */
    public static function make(mixed $content, int $status = 200, array $headers = []): self
    {
        if ($content instanceof self) {
            return $status === 200 && $headers === [] ? $content : $content->withStatus($status)->withHeaders($headers);
        }

        if ($content === null) {
            return new self('', 204, $headers);
        }

        if (is_array($content) || $content instanceof JsonSerializable || is_object($content) && !$content instanceof Stringable) {
            return self::json($content, $status, $headers);
        }

        return self::html(self::stringify($content), $status, $headers);
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + ['Content-Type' => self::HTML]);
    }

    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + ['Content-Type' => self::TEXT]);
    }

    /**
     * JSON sin escapar acentos ni barras: `{"nombre":"Niño"}` en lugar de
     * `{"nombre":"Ni\u00f1o"}`.
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        if (defined('JSON_THROW_ON_ERROR')) {
            $flags |= JSON_THROW_ON_ERROR;
        }

        $encoded = json_encode($data, $flags);

        if ($encoded === false) {
            return self::html('La respuesta no pudo serializarse a JSON.', 500, $headers);
        }

        return new self($encoded, $status, $headers + ['Content-Type' => self::JSON]);
    }

    /**
     * Redirección. Los destinos externos al propio sitio se descartan para
     * evitar redirecciones abiertas.
     */
    public static function redirect(string $to, int $status = 302, array $headers = []): self
    {
        $safe = self::sanitizeTarget($to);
        $response = new self('', $status, ['Location' => $safe] + $headers);
        $response->body = $safe === '' ? '' : '<a href="'.e($safe).'">Redirigiendo…</a>';

        return $response;
    }

    public static function notFound(string $message = '404 — Página no encontrada'): self
    {
        return self::html($message, 404);
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status, ['Content-Type' => self::TEXT]);
    }

    private static function sanitizeTarget(string $to): string
    {
        $to = trim($to);

        if ($to === '') {
            return '/';
        }

        // Protocolo relativo (//evil.example) o backslash: no se permiten.
        if (str_starts_with($to, '//') || str_starts_with($to, '/\\')) {
            return '/';
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $to) === 1) {
            // URL absoluta: solo si apunta al host configurado.
            $base = (string) (config('app.url') ?? '');
            $allowedHost = $base !== '' ? parse_url($base, PHP_URL_HOST) : null;
            $targetHost = parse_url($to, PHP_URL_HOST);

            if (is_string($allowedHost) && $allowedHost === $targetHost) {
                return $to;
            }

            return '/';
        }

        return str_starts_with($to, '/') ? $to : '/'.ltrim($to, '/');
    }

    private static function stringify(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return get_debug_type($value);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 400;
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true);
    }

    /**
     * Define un header (sobrescribe).
     */
    public function header(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = ['name' => $name, 'value' => $value];

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header((string) $name, (string) $value);
        }

        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)]['value'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function allHeaders(): array
    {
        $result = [];

        foreach ($this->headers as $entry) {
            $result[$entry['name']] = $entry['value'];
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;

        foreach ($headers as $name => $value) {
            $clone->header((string) $name, (string) $value);
        }

        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        return $this->withHeaders([$name => $value]);
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        $clone = clone $this;
        $clone->cookies[] = [
            'name' => $name,
            'value' => $value,
            'options' => ['path' => '/', 'httponly' => true, 'samesite' => 'Lax'] + $options,
        ];

        return $clone;
    }

    /**
     * @return array<int, array{name: string, value: string, options: array<string, mixed>}>
     */
    public function allCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Envía la respuesta al cliente.
     */
    public function send(): void
    {
        if (PHP_SAPI === 'cli') {
            echo $this->body;

            return;
        }

        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $entry) {
                header($entry['name'].': '.$entry['value'], true);
            }

            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options']);
            }
        }

        if ($this->status !== 204 && $this->status < 300) {
            echo $this->body;
        } elseif ($this->isRedirect() && $this->body !== '') {
            echo $this->body;
        }
    }

    public function __toString(): string
    {
        return $this->body;
    }
}
