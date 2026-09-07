<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

/**
 * Envoltorio de la sesión nativa de PHP.
 *
 * Con `native: false` funciona con un arreglo en memoria, lo que permite
 * probar CSRF, flashes y validación sin subir un servidor web.
 */
final class Session
{
    private const FLASH_NEW = '_flash.new';

    private const FLASH_OLD = '_flash.old';

    private const TOKEN_KEY = '_token';

    private const OLD_INPUT = '_old';

    /** @var array<string, mixed> */
    private array $bag = [];

    private bool $started = false;

    /** @var array<int, array{name: string, value: string, options: array<string, mixed>}> */
    private array $pendingCookies = [];

    /**
     * @param  array<string, mixed>  $bag  contenido inicial (en pruebas se pasa a mano)
     */
    public function __construct(
        private string $name = 'simplemvc_session',
        private int $lifetime = 7200,
        private bool $native = true,
        private bool $secure = false,
        array $bag = []
    ) {
        $this->bag = $bag;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        if ($this->native && $this->canUseNativeSession()) {
            session_name($this->name);
            session_set_cookie_params([
                'lifetime' => $this->lifetime,
                'path' => '/',
                'domain' => '',
                'secure' => $this->secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            @session_start();
        }

        if ($this->native) {
            $_SESSION = $_SESSION ?? [];
            $this->bag = &$_SESSION;
        }

        // Envolvemos el arreglo de la sesión nativa por referencia: los cambios
        // se persisten solos al terminar la petición. El envejecimiento de los
        // flashes lo hace App::handle() al final, no aquí, para que funcione
        // igual con PHP-FPM (proceso nuevo por petición) y con runtimes
        // persistentes (RoadRunner, FrankenPHP, workers).
        $this->bag[self::FLASH_NEW] = (array) ($this->bag[self::FLASH_NEW] ?? []);
        $this->bag[self::FLASH_OLD] = (array) ($this->bag[self::FLASH_OLD] ?? []);
    }

    private function canUseNativeSession(): bool
    {
        return PHP_SAPI !== 'cli'
            && !headers_sent()
            && session_status() !== PHP_SESSION_ACTIVE
            && is_writable((string) ini_get('session.save_path'));
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->bag;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->bag[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->bag[$key]);
    }

    public function put(string $key, mixed $value): void
    {
        $this->bag[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->bag[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function flush(): void
    {
        $this->bag = [];
        $this->started = $this->started && !$this->native;
        $this->start();
    }

    // -----------------------------------------------------------------
    // Flash (valor disponible solo en la siguiente petición)
    // -----------------------------------------------------------------

    public function flash(string $key, mixed $value): void
    {
        $this->bag[self::FLASH_NEW][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->bag[self::FLASH_OLD][$key] ?? $default;
    }

    public function hasFlash(string $key): bool
    {
        return array_key_exists($key, (array) $this->bag[self::FLASH_OLD]);
    }

    /**
     * @return array<string, mixed>
     */
    public function flashes(): array
    {
        return (array) ($this->bag[self::FLASH_OLD] ?? []);
    }

    /**
     * Promueve los flashes de esta petición a "viejos" (visibles en la
     * siguiente) y vacía los pendientes. App lo llama al terminar cada petición.
     */
    public function ageFlashData(): void
    {
        $this->bag[self::FLASH_OLD] = (array) ($this->bag[self::FLASH_NEW] ?? []);
        $this->bag[self::FLASH_NEW] = [];
    }

    // -----------------------------------------------------------------
    // old input
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     */
    public function setOldInput(array $input): void
    {
        unset($input['_token'], $input['password'], $input['password_confirmation'], $input['_method']);

        $this->bag[self::FLASH_NEW][self::OLD_INPUT] = $input;
    }

    /**
     * @return array<string, mixed>
     */
    public function oldInput(): array
    {
        return (array) ($this->bag[self::FLASH_OLD][self::OLD_INPUT] ?? []);
    }

    // -----------------------------------------------------------------
    // CSRF
    // -----------------------------------------------------------------

    public function token(): string
    {
        if (!is_string($this->bag[self::TOKEN_KEY] ?? null) || $this->bag[self::TOKEN_KEY] === '') {
            $this->bag[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $this->bag[self::TOKEN_KEY];
    }

    public function regenerateToken(): void
    {
        $this->bag[self::TOKEN_KEY] = bin2hex(random_bytes(32));
    }

    public function verifyToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $current = $this->bag[self::TOKEN_KEY] ?? null;

        return is_string($current) && $current !== '' && hash_equals($current, $token);
    }

    /**
     * Cambia el identificador de sesión: imprescindible tras un login para
     * evitar fijación de sesión.
     */
    public function regenerate(): void
    {
        if ($this->native && PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }

        $this->regenerateToken();
    }

    public function destroy(): void
    {
        $this->bag = [];
        $this->started = false;

        if ($this->native && PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    /**
     * Registra una cookie para que el Router la adjunte a la respuesta.
     *
     * @param  array<string, mixed>  $options
     */
    public function queueCookie(string $name, string $value, array $options = []): void
    {
        $this->pendingCookies[] = [
            'name' => $name,
            'value' => $value,
            'options' => ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $this->secure] + $options,
        ];
    }

    /**
     * @return array<int, array{name: string, value: string, options: array<string, mixed>}>
     */
    public function pendingCookies(): array
    {
        return $this->pendingCookies;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }
}
