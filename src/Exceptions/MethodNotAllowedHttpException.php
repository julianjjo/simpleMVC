<?php

declare(strict_types=1);

namespace SimpleMvc\Exceptions;

final class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param  string[]  $allowedMethods
     */
    public function __construct(array $allowedMethods = [], string $message = '')
    {
        parent::__construct(
            status: 405,
            message: $message,
            headers: $allowedMethods === [] ? [] : ['Allow' => implode(', ', array_unique($allowedMethods))],
        );
    }

    /**
     * @return string[]
     */
    public function allowedMethods(): array
    {
        return array_values(array_filter(explode(', ', $this->headers()['Allow'] ?? '')));
    }
}
