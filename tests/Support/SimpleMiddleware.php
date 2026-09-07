<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use SimpleMvc\Core\Request;
use SimpleMvc\Core\Response;

final class SimpleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $response->withHeader('X-Middleware', 'ok');
    }
}
