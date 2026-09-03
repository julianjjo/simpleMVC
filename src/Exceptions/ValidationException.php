<?php

declare(strict_types=1);

namespace SimpleMvc\Exceptions;

use SimpleMvc\Core\Validator;
use Throwable;

/**
 * Lanza el `validateOrFail()` de un controlador. El ErrorHandler la convierte en
 * 422 (JSON) o en un redirect-back con los errores y el input anterior (HTML).
 */
final class ValidationException extends HttpException
{
    private ?string $redirectTarget = null;

    public function __construct(
        private Validator $validator,
        string $message = 'Los datos enviados no son válidos.',
        ?Throwable $previous = null
    ) {
        parent::__construct(422, $message, $previous);
    }

    public function validator(): Validator
    {
        return $this->validator;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->validator->errors();
    }

    public function redirectTo(string $target): self
    {
        $this->redirectTarget = $target;

        return $this;
    }

    public function redirectTarget(): ?string
    {
        return $this->redirectTarget;
    }
}
