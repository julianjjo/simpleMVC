<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use Closure;
use SimpleMvc\Exceptions\HttpException;

/**
 * Middleware CSRF: rechaza peticiones que modifican datos sin un token válido.
 *
 * El micro-framework original no distinguía verbos, así que un POST podía
 * ejecutar cualquier acción. Se comparan con hash_equals para no exponer el
 * token a un ataque de temporización.
 */
final class CsrfMiddleware
{
    public const TOKEN_FIELD = '_token';

    public function __construct(private Session $session, private ?Closure $ignore = null)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isReadOnly() || $this->isIgnored($request)) {
            return $next($request);
        }

        $token = $request->body(self::TOKEN_FIELD) ?? $request->header('x-csrf-token');

        if (!$this->session->verifyToken(is_string($token) ? $token : null)) {
            if ($request->wantsJson()) {
                throw new HttpException(419, 'CSRF token mismatch.');
            }

            throw new HttpException(419, 'El token de seguridad expiró o no es válido. Recarga la página e inténtalo de nuevo.');
        }

        return $next($request);
    }

    private function isIgnored(Request $request): bool
    {
        if ($this->ignore === null) {
            return false;
        }

        return (bool) ($this->ignore)($request);
    }
}
