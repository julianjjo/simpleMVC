<?php

declare(strict_types=1);

namespace SimpleMvc\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Excepción con código de estado HTTP asociado.
 *
 * El router viejo respondía 404 con `header("HTTP/1.0 404 Not Found")` + exit;
 * ahora cualquier capa puede lanzar una excepción tipada y el ErrorHandler
 * decide cómo renderizarla (HTML, JSON o texto plano).
 */
class HttpException extends RuntimeException
{
    public function __construct(
        protected int $status = 500,
        string $message = '',
        ?Throwable $previous = null,
        private array $headers = []
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Solicitud inválida.',
            401 => 'No autenticado.',
            403 => 'No tienes permiso para realizar esta acción.',
            404 => 'Página no encontrada.',
            405 => 'Método no permitido.',
            419 => 'El token de CSRF expiró. Intenta de nuevo.',
            422 => 'Los datos enviados no son válidos.',
            429 => 'Demasiadas solicitudes.',
            500 => 'Error interno del servidor.',
            503 => 'El servicio no está disponible.',
            default => 'Error HTTP '.$status.'.',
        };
    }
}
