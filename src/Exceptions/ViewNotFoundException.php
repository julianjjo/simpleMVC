<?php

declare(strict_types=1);

namespace SimpleMvc\Exceptions;

use RuntimeException;

/**
 * La plantilla pedida no existe en el directorio de vistas.
 */
final class ViewNotFoundException extends RuntimeException
{
    public function __construct(string $template, ?string $hint = null)
    {
        parent::__construct(sprintf(
            'No se encontró la plantilla "%s".%s',
            $template,
            $hint === null ? '' : ' '.$hint
        ));
    }
}
