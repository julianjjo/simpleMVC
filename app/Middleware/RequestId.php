<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;
use SimpleMvc\Core\Logger;
use SimpleMvc\Core\Request;
use SimpleMvc\Core\Response;

/**
 * Ejemplo de middleware: asigna un id de correlación a cada petición.
 *
 * Firma de un middleware: `handle(Request $request, Closure $next): Response`.
 * Puede antes de `$next()` preparar el contexto, y después modificar la
 * respuesta (headers, caché, tiempos).
 */
final class RequestId
{
    public function __construct(private Logger $logger)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $id = bin2hex(random_bytes(6));

        $this->logger->debug('{method} {uri}', [
            'request_id' => $id,
            'method' => $request->method(),
            'uri' => $request->fullUrl(),
        ]);

        $response = $next($request);

        $ms = (int) round((microtime(true) - $start) * 1000);

        return $response
            ->withHeader('X-Request-Id', $id)
            ->withHeader('X-Response-Time', $ms.' ms');
    }
}
