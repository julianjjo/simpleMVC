<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepository;
use SimpleMvc\Core\Controller;
use SimpleMvc\Core\Request;
use SimpleMvc\Core\Response;
use SimpleMvc\Core\Session;
use SimpleMvc\Core\View;

final class HomeController extends Controller
{
    public function __construct(
        View $view,
        Request $request,
        Session $session,
        private ProductRepository $products
    ) {
        parent::__construct($view, $request, $session);
    }

    /**
     * Portada: qué ofrece el framework + estado de la demo.
     */
    public function index(): Response
    {
        return $this->render('home', [
            'title' => 'Micro-framework MVC en PHP 8',
            'total' => $this->products->count(),
            'categories' => $this->products->categoriesWithCounts(),
            'featured' => $this->products->featured(3),
        ]);
    }

    /**
     * Demostración del ejemplo histórico del README: parámetros de ruta en el
     * orden en que se declaran, incluyendo variádicos.
     */
    public function params(string $a = '', string $b = '', string $c = ''): Response
    {
        return $this->render('params', [
            'title' => 'Parámetros de ruta',
            'params' => array_filter(['a' => $a, 'b' => $b, 'c' => $c], static fn (string $v): bool => $v !== ''),
        ]);
    }
}
