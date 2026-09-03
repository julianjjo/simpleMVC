<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use SimpleMvc\Exceptions\ViewNotFoundException;

/**
 * Motor de vistas: plantilla + layout, con buffers correctamente cerrados.
 *
 * Correcciones frente al `View` anterior:
 *  - hacía dos `ob_start()` seguidos y nunca liberaba el segundo (fuga de
 *    buffers y contenido duplicado en algunos servidores);
 *  - `TemplateBase.php` usaba una etiqueta de apertura sin `echo` para el
 *    título, así que nunca imprimía nada;
 *  - el nombre de la plantilla se concatenaba en el `include` sin validar,
 *    permitiendo `../../etc/passwd`. Aquí el nombre se filtra con una
 *    expresión regular y se comprueba que el archivo quede dentro de
 *    `templates/`.
 */
final class View
{
    public const AUTO_LAYOUT = '__auto__';

    /** @var array<string, mixed> */
    private array $shared = [];

    /** @var array<string, string> */
    private array $namespaces = [];

    public function __construct(
        private string $viewsPath,
        private ?string $defaultLayout = 'layout',
        private ?Session $session = null
    ) {
        $this->viewsPath = rtrim(str_replace('\\', '/', $viewsPath), '/');
    }

    /**
     * Comparte una variable con todas las plantillas.
     *
     * @param  array<string, mixed>|string  $key
     */
    public function share(array|string $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->shared = array_merge($this->shared, $key);

            return $this;
        }

        $this->shared[$key] = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function shared(): array
    {
        return $this->shared;
    }

    /**
     * Registra una carpeta de vistas para un prefijo: `admin::panel`.
     */
    public function addNamespace(string $namespace, string $path): self
    {
        $this->namespaces[$namespace] = rtrim(str_replace('\\', '/', $path), '/');

        return $this;
    }

    public function exists(string $template): bool
    {
        try {
            return is_file($this->path($template));
        } catch (ViewNotFoundException) {
            return false;
        }
    }

    /**
     * Resuelve el nombre lógico de una plantilla a una ruta absoluta segura.
     */
    public function path(string $template): string
    {
        $namespace = null;

        if (str_contains($template, '::')) {
            [$namespace, $template] = explode('::', $template, 2);
        }

        // Hay que rechazar «..» antes de nada: la conversión de puntos convertiría
        // '../x' en '//x' y el salto de directorio pasaría desapercibido.
        if (str_contains($template, '..') || str_contains($template, "\0")) {
            throw new ViewNotFoundException($template, 'Nombre de plantilla no válido.');
        }

        // `errors.404` y `errors/404` son la misma plantilla.
        $template = str_replace('.', '/', ltrim($template, '/'));

        if (preg_match('#^[A-Za-z0-9_\-/]+$#', $template) !== 1) {
            throw new ViewNotFoundException($template, 'Usa solo letras, números, guiones y barras.');
        }

        $base = $namespace === null
            ? $this->viewsPath
            : ($this->namespaces[$namespace] ?? throw new ViewNotFoundException($template, "Namespace de vistas desconocido: {$namespace}."));

        $file = $base.'/'.ltrim($template, '/');

        if (!str_ends_with($file, '.php')) {
            $file .= '.php';
        }

        $real = is_file($file) ? $this->realpathOrNull($file) : null;
        $root = is_dir($base) ? (realpath($base) ?: $base) : $base;

        if ($real === null || ($root !== '' && !str_starts_with($real, $root.'/'))) {
            throw new ViewNotFoundException(
                $namespace === null ? $template : $namespace.'::'.$template,
                'Buscado en '.$base
            );
        }

        return $real;
    }

    private function realpathOrNull(string $file): ?string
    {
        $real = realpath($file);

        return $real === false ? null : $real;
    }

    /**
     * Renderiza una plantilla dentro del layout.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data = [], ?string $layout = self::AUTO_LAYOUT): string
    {
        $variables = $this->prepare($data);
        [$content, $exported] = $this->capture($this->path($template), $variables);

        if ($layout === self::AUTO_LAYOUT) {
            $layout = $this->defaultLayout;
        }

        if ($layout === null || $layout === '') {
            return $content;
        }

        // Las variables que la plantilla define (p. ej. $title) se exportan al
        // layout: eso es lo que la versión original intentaba con `echo`.
        [$page] = $this->capture($this->path($layout), $variables + $exported + ['content' => $content]);

        return $page;
    }

    /**
     * Solo la plantilla, sin layout (para respuestas parciales o AJAX).
     *
     * @param  array<string, mixed>  $data
     */
    public function partial(string $template, array $data = []): string
    {
        [$content] = $this->capture($this->path($template), $this->prepare($data));

        return $content;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function response(string $template, array $data = [], ?string $layout = self::AUTO_LAYOUT, int $status = 200): Response
    {
        return Response::html($this->render($template, $data, $layout), $status);
    }

    public function sharedFlash(string $key, mixed $default = null): mixed
    {
        return $this->session?->getFlash($key, $default) ?? $default;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data): array
    {
        $data = array_merge($this->shared, $data);
        $data['errors'] ??= $this->sharedFlash('errors', []);
        $data['flashes'] ??= $this->session?->flashes() ?? [];

        // Contracto mínimo del layout. Sin esto, una plantilla renderizada fuera
        // de una petición (página de error, CLI) revienta con «Undefined
        // variable» y tapa el error original.
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        $data += [
            'base_url' => '',
            'debug' => false,
            'current_path' => '/',
            'current_query' => '',
            'request_uri' => $requestUri === '' ? '/' : $requestUri,
        ];

        return $data;
    }

    /**
     * Ejecuta una plantilla en un buffer de salida y devuelve su contenido más
     * las variables nuevas que haya definido (para exportarlas al layout).
     *
     * @param  array<string, mixed>  $variables
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function capture(string $file, array $variables): array
    {
        $before = get_defined_vars();
        $level = ob_get_level();

        extract($variables, EXTR_SKIP);

        ob_start();

        try {
            include $file;
            $content = (string) ob_get_clean();
        } catch (\Throwable $e) {
            // Cierra SOLO los buffers abiertos por esta plantilla: si el error
            // ocurre a mitad del render, el original dejaba ob_start() huérfano.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        $exported = array_diff_key(get_defined_vars(), $before, array_flip(['file', 'variables', 'before', 'content']));

        return [$content, $exported];
    }
}
