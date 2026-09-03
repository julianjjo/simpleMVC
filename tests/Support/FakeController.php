<?php

declare(strict_types=1);

namespace Tests\Support;

use SimpleMvc\Core\Request;

/**
 * Controlador de prueba: verifica la inyección por contenedor y el enlace de
 * argumentos de ruta.
 */
final class FakeController
{
    public function __construct(private string $name = 'default')
    {
    }

    public function show(Request $request, int $id): string
    {
        return $this->name.':'.$id.':'.(string) $request->query('q');
    }

    public static function plain(): string
    {
        return 'legacy';
    }
}
