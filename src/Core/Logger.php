<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use Throwable;

/**
 * Logger PSR-3-like en un archivo, sin dependencias.
 */
final class Logger
{
    private ?string $path;

    private string $minimumLevel;

    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
    ];

    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    private array $memory = [];

    /**
     * Contexto de la petición actual: se añade a todos los registros hasta que
     * se limpia (App lo borra al terminar cada petición, de modo que un runtime
     * persistente no se lo pase a la siguiente).
     *
     * @var array<string, mixed>
     */
    private array $context = [];

    public function __construct(?string $path = null, string $minimumLevel = 'debug')
    {
        // '' cuenta como "sin archivo": solo se guardan en memoria.
        $this->path = $path === null || $path === '' ? null : $path;
        $this->minimumLevel = $minimumLevel;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string|Throwable $message, array $context = []): void
    {
        $this->log('critical', $message instanceof Throwable ? $message->getMessage() : $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        if (!isset(self::LEVELS[$level])) {
            $level = 'debug';
        }

        if (self::LEVELS[$level] < (self::LEVELS[strtolower($this->minimumLevel)] ?? 0)) {
            return;
        }

        // Lo que pase por aquí manda sobre el contexto de la petición.
        $context = array_merge($this->context, $context);

        $record = [
            'level' => $level,
            'message' => $this->interpolate($message, $context),
            'context' => $context,
        ];

        $this->memory[] = $record;

        if (count($this->memory) > 100) {
            array_shift($this->memory);
        }

        if ($this->path === null) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $record['message'],
            $context === [] ? '' : ' '.json_encode($this->scalarize($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0o775, true);
        }

        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }

    /**
     * Sustituye {clave} con los valores del contexto.
     *
     * @param  array<string, mixed>  $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || (is_object($value) && method_exists($value, '__toString'))) {
                $replace['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function scalarize(array $context): array
    {
        $result = [];

        foreach ($context as $key => $value) {
            $result[$key] = match (true) {
                $value instanceof Throwable => $value::class.': '.$value->getMessage(),
                is_object($value) && method_exists($value, '__toString') => (string) $value,
                is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
                is_bool($value) => $value ? 'true' : 'false',
                is_object($value) => $value::class,
                default => $value,
            };
        }

        return $result;
    }

    /**
     * Registros en memoria de esta petición (pruebas, depuración).
     *
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    public function records(): array
    {
        return $this->memory;
    }

    /**
     * Añade contexto para los próximos registros (p. ej. el id de correlación
     * de la petición, desde un middleware).
     *
     * @param  array<string, mixed>  $context
     */
    public function pushContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function clearContext(): void
    {
        $this->context = [];
    }

    public function path(): ?string
    {
        return $this->path;
    }
}
