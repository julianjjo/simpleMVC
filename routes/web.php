<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Rutas
|--------------------------------------------------------------------------
|
| Devuelve un closure que recibe el Router. Sintaxis de parámetros:
|   :nombre            cualquier segmento (una o varias letras/números)
|   :nombre(\d+)       con restricción de patrón
|   ->where('id','\d+') la misma restricción, encadenada
|
| La API del proyecto original sigue disponible: `add()` acepta GET/POST/HEAD
| y `'ProductsController::index'` se resuelve buscando la clase en
| App\Controllers.
|
 */

use App\Controllers\Api\ProductsController as ProductsApiController;
use App\Controllers\HomeController;
use App\Controllers\ProductsController;
use App\Middleware\RequestId;
use SimpleMvc\Core\CsrfMiddleware;
use SimpleMvc\Core\Router;

return function (Router $router): void {
    $router->middleware(RequestId::class);

    $router->get('/', [HomeController::class, 'index'])->name('home');

    // Demostración histórica: parámetros en el orden declarado.
    $router->get('/demo/:a/:b/:c', [HomeController::class, 'params'])->name('demo.params');

    $router->group(['middleware' => CsrfMiddleware::class], function (Router $router): void {
        $router->get('/productos', [ProductsController::class, 'index'])->name('products.index');
        $router->get('/productos/nuevo', [ProductsController::class, 'create'])->name('products.create');
        $router->post('/productos', [ProductsController::class, 'store'])->name('products.store');

        // Un solo grupo para las rutas con :idOrSlug; el where evita que
        // /productos/abc entre por la rama de id numérico.
        $router->match(['GET', 'HEAD'], '/productos/:idOrSlug([A-Za-z0-9_\-]+)', [ProductsController::class, 'show'])
            ->name('products.show');

        $router->get('/productos/:id(\d+)/editar', [ProductsController::class, 'edit'])->name('products.edit');

        // PUT y DELETE llegan por el campo oculto _method de los formularios.
        $router->put('/productos/:id(\d+)', [ProductsController::class, 'update'])->name('products.update');
        $router->delete('/productos/:id(\d+)', [ProductsController::class, 'destroy'])->name('products.destroy');
        $router->post('/productos/:id(\d+)/eliminar', [ProductsController::class, 'destroy'])->name('products.delete');
    });

    // Respuestas JSON.
    $router->group(['prefix' => 'api/v1', 'as' => 'api.'], function (Router $router): void {
        $router->get('/productos', [ProductsApiController::class, 'index'])->name('products.index');
        $router->get('/productos/:idOrSlug([A-Za-z0-9_\-]+)', [ProductsApiController::class, 'show'])->name('products.show');
    });

    $router->get('/salud', fn (SimpleMvc\Core\Request $request): SimpleMvc\Core\Response => SimpleMvc\Core\Response::json([
        'status' => 'ok',
        'php' => PHP_VERSION,
        'driver' => app(SimpleMvc\Core\Database::class)->driver(),
        'tiempo' => date(DATE_ATOM),
    ]))->name('health');
};
