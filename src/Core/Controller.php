<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use SimpleMvc\Exceptions\ValidationException;

/**
 * Controlador base con las utilidades que un micro-framework sí vale la pena
 * tener: render, json, redirect, flash y validación con "redirect back".
 *
 * Las dependencias llegan por el constructor y las resuelve el contenedor
 * (autowiring), en lugar del viejo patrón `Model::getModel()`.
 */
abstract class Controller
{
    public function __construct(
        protected View $view,
        protected Request $request,
        protected Session $session
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function render(string $template, array $data = [], ?string $layout = View::AUTO_LAYOUT, int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data, $layout), $status);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function text(string $body, int $status = 200): Response
    {
        return Response::text($body, $status);
    }

    protected function redirect(string $to, int $status = 302): Response
    {
        return Response::redirect($to, $status);
    }

    protected function redirectToRoute(string $name, array $parameters = [], int $status = 302): Response
    {
        return $this->redirect(app(Router::class)->url($name, $parameters), $status);
    }

    /**
     * Vuelve a la URL de origen (Referer validado) o a una ruta de reserva.
     */
    protected function back(string $fallback = '/', int $status = 302): Response
    {
        $referer = $this->request->header('referer');
        $target = '/';

        if (is_string($referer) && $referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            $query = parse_url($referer, PHP_URL_QUERY);

            if (is_string($path) && $path !== '') {
                $target = $path.($query === null || $query === '' ? '' : '?'.$query);
            }
        }

        if ($target === '/' && $fallback !== '/') {
            $target = $fallback;
        }

        return $this->redirect($target, $status);
    }

    protected function flash(string $key, mixed $value): void
    {
        $this->session->flash($key, $value);
    }

    /**
     * Redirige hacia atrás conservando lo escrito en el formulario.
     *
     * @param  array<string, mixed>  $input
     */
    protected function withInput(array $input): self
    {
        $this->session->setOldInput($input);

        return $this;
    }

    /**
     * Valida la petición. Si falla, redirige atrás con errores + input previo;
     * si la petición pide JSON, responde 422.
     *
     * @param  array<string, string|array<int, string>>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, string>  datos validados
     */
    protected function validateOrFail(array $rules, array $messages = [], array $attributes = []): array
    {
        $validator = $this->validator($rules, $messages, $attributes);

        if ($validator->passes()) {
            return $validator->validated();
        }

        if ($this->request->wantsJson()) {
            throw (new ValidationException($validator))
                ->redirectTo('');
        }

        $this->session->flash('errors', $validator->errors());
        $this->session->setOldInput($this->request->all());

        throw (new ValidationException($validator))->redirectTo($this->refererPath());
    }

    /**
     * @param  array<string, string|array<int, string>>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    protected function validator(array $rules, array $messages = [], array $attributes = []): Validator
    {
        return Validator::make($this->request->all(), $rules, $messages, $attributes);
    }

    private function refererPath(): string
    {
        $referer = $this->request->header('referer');

        if (!is_string($referer) || $referer === '') {
            return '/';
        }

        $path = parse_url($referer, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
