<?php

declare(strict_types=1);

namespace SimpleMvc\Exceptions;

use RuntimeException;

/**
 * Falla al hablar con la base de datos (conexión, sintaxis, constraint).
 */
final class DatabaseException extends RuntimeException
{
    public function __construct(string $message, private string $sql = '', private array $bindings = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * El SQL solo se expone en modo debug: en producción no debe filtrarse.
     */
    public function safeMessage(): string
    {
        return $this->getMessage();
    }
}
