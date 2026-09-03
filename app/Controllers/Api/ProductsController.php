<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Repositories\ProductRepository;
use SimpleMvc\Core\Controller;
use SimpleMvc\Core\Request;
use SimpleMvc\Core\Response;
use SimpleMvc\Core\Session;
use SimpleMvc\Core\View;
use SimpleMvc\Exceptions\NotFoundHttpException;

/**
 * API JSON de solo lectura.
 *
 * El router viejo convertía un arreglo devuelto en JSON con `json_encode()` a
 * secas (acentos escapados, sin cabecera correcta). Aquí se responde con
 * Response::json(), que fija `application/json; charset=utf-8` y preserva
 * Unicode.
 */
final class ProductsController extends Controller
{
    public function __construct(
        View $view,
        Request $request,
        Session $session,
        private ProductRepository $products
    ) {
        parent::__construct($view, $request, $session);
    }

    public function index(): Response
    {
        $page = max(1, (int) ($this->request->int('page', 1) ?? 1));
        $perPage = min(50, max(1, (int) ($this->request->int('por_pagina', 10) ?? 10)));

        $paginator = $this->products->searchFiltered(
            term: $this->request->string('q') !== '' ? $this->request->string('q') : null,
            category: $this->request->string('categoria') !== '' ? $this->request->string('categoria') : null,
            sort: $this->request->string('orden', 'nombre'),
            direction: $this->request->string('direccion', 'asc'),
            page: $page,
            perPage: $perPage,
            onlyAvailable: $this->request->bool('solo_disponibles'),
        );

        return $this->json([
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $idOrSlug): Response
    {
        $product = ctype_digit($idOrSlug)
            ? $this->products->find((int) $idOrSlug)
            : $this->products->findBySlug($idOrSlug);

        if ($product === null) {
            throw new NotFoundHttpException("Producto «{$idOrSlug}» no encontrado.");
        }

        return $this->json(['data' => $product]);
    }
}
