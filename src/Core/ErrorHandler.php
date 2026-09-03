<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use ErrorException;
use SimpleMvc\Exceptions\HttpException;
use SimpleMvc\Exceptions\ValidationException;
use SimpleMvc\Exceptions\ViewNotFoundException;
use Throwable;

/**
 * Conversor de excepciones a respuestas + registro en log.
 *
 * Sustituye al `error_reporting(E_ALL); ini_set('display_errors', 1)` fijo del
 * index.php original: en producción las trazas no deben salir nunca.
 */
final class ErrorHandler
{
    private bool $registered = false;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private ?View $view = null
    ) {
    }

    /**
     * Convierte warnings/notices en excepciones y captura los fatales.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        $debug = $this->config->isDebug();

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e): void {
            $response = $this->handle($e);

            if (!headers_sent()) {
                $response->send();
            } else {
                echo $response->body();
            }
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $this->logger->error('Error fatal: {message}', [
                'message' => $error['message'],
                'file' => $error['file'].':'.$error['line'],
            ]);

            if (!$this->config->isDebug() && PHP_SAPI !== 'cli') {
                echo '<h1>500 — Error interno</h1>';
            }
        });
    }

    public function handle(Throwable $e, ?Request $request = null): Response
    {
        $request ??= new Request();

        $this->report($e, $request);

        if ($e instanceof ValidationException && !$request->wantsJson()) {
            $target = $e->redirectTarget();

            if ($target !== null) {
                return Response::redirect($target === '' ? '/' : $target, 303);
            }
        }

        $status = $e instanceof HttpException ? $e->status() : 500;
        $headers = $e instanceof HttpException ? $e->headers() : [];

        if ($request->wantsJson()) {
            $payload = [
                'error' => $status,
                'message' => $this->exposeDetails($e) ? $e->getMessage() : self::genericMessage($status),
            ];

            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            } elseif ($this->exposeDetails($e)) {
                $payload['exception'] = $e::class;
                $payload['file'] = $e->getFile().':'.$e->getLine();
            }

            return Response::json($payload, $status, $headers);
        }

        return new Response($this->renderHtml($e, $request, $status), $status, $headers + ['Content-Type' => Response::HTML]);
    }

    public function report(Throwable $e, ?Request $request = null): void
    {
        $status = $e instanceof HttpException ? $e->status() : 500;
        $level = $status >= 500 ? 'error' : 'notice';
        $context = ['status' => $status];

        if ($request !== null) {
            $context += [
                'method' => $request->method(),
                'uri' => $request->fullUrl(),
                'exception' => $e::class,
            ];
        }

        if ($e instanceof \PDOException || $e instanceof \SimpleMvc\Exceptions\DatabaseException) {
            // El mensaje de PDO puede contener fragmentos de la consulta.
            $context['sql'] = method_exists($e, 'sql') ? (string) $e->sql() : '';
        }

        $this->logger->log($level, $e->getMessage(), $context);
    }

    private function exposeDetails(Throwable $e): bool
    {
        // Los 4xx son del cliente: su mensaje ya es informativo y seguro.
        return $this->config->isDebug() && (!$e instanceof HttpException || $e->status() >= 500);
    }

    private function renderHtml(Throwable $e, Request $request, int $status): string
    {
        $message = $this->exposeDetails($e) ? $e->getMessage() : self::genericMessage($status);

        if ($this->view !== null) {
            $template = $status === 404 ? 'errors.404' : 'errors.500';

            if ($this->view->exists($template)) {
                try {
                    return $this->view->render($template, [
                        'title' => $status === 404 ? '404 — Página no encontrada' : $status.' — Error',
                        'status' => $status,
                        'message' => $message,
                        'exception' => $this->exposeDetails($e) ? $e : null,
                        'frames' => $this->exposeDetails($e) ? $this->trace($e) : [],
                        'request' => [
                            'method' => $request->method(),
                            'uri' => $request->fullUrl(),
                            'query' => $request->query(),
                            'body' => $request->isReadOnly() ? [] : $request->body(),
                        ],
                    ]);
                } catch (ViewNotFoundException $nested) {
                    $this->logger->warning('No se pudo renderizar la plantilla de error: {message}', [
                        'message' => $nested->getMessage(),
                    ]);
                }
            }
        }

        $body = '<h1>'.$status.'</h1><p>'.e($message).'</p>';

        if ($this->exposeDetails($e)) {
            $body .= '<pre>'.e($e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString()).'</pre>';
        }

        return $body;
    }

    /**
     * Trazas reducidas y sin argumentos para no filtrar secretos en el HTML.
     *
     * @return array<int, array{file: string, line: int, call: string}>
     */
    private function trace(Throwable $e, int $limit = 15): array
    {
        $frames = [];
        $raw = array_slice($e->getTrace(), 0, $limit);

        foreach ($raw as $frame) {
            $call = ($frame['class'] ?? '').($frame['type'] ?? '->').($frame['function'] ?? '?');

            $frames[] = [
                'file' => (string) ($frame['file'] ?? '[internal]'),
                'line' => (int) ($frame['line'] ?? 0),
                'call' => $call.'()',
            ];
        }

        return $frames;
    }

    private static function genericMessage(int $status): string
    {
        return HttpException::defaultMessage($status);
    }
}
