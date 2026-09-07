<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Product;
use App\Repositories\ProductRepository;
use SimpleMvc\Core\Controller;
use SimpleMvc\Core\Request;
use SimpleMvc\Core\Response;
use SimpleMvc\Core\Session;
use SimpleMvc\Core\View;
use SimpleMvc\Exceptions\NotFoundHttpException;

/**
 * CRUD de productos.
 *
 * Cada método recibe lo que necesita por argumentos (Request, inyección del
 * repositorio) y devuelve una Response; ya no hay estado global ni `echo`
 * suelto dentro del controlador.
 */
final class ProductsController extends Controller
{
    public const PER_PAGE = 9;

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
        $filters = [
            'q' => $this->request->string('q'),
            'categoria' => $this->request->string('categoria'),
            'orden' => $this->request->string('orden', 'nombre'),
            'direccion' => strtolower($this->request->string('direccion', 'asc')) === 'desc' ? 'desc' : 'asc',
            'solo_disponibles' => $this->request->bool('solo_disponibles'),
        ];

        $page = max(1, (int) ($this->request->int('page', 1) ?? 1));

        $paginator = $this->products->searchFiltered(
            term: $filters['q'] !== '' ? $filters['q'] : null,
            category: $filters['categoria'] !== '' ? $filters['categoria'] : null,
            sort: $filters['orden'],
            direction: $filters['direccion'],
            page: $page,
            perPage: self::PER_PAGE,
            onlyAvailable: $filters['solo_disponibles'],
            appends: array_filter([
                'q' => $filters['q'],
                'categoria' => $filters['categoria'],
                'orden' => $filters['orden'] === 'nombre' ? null : $filters['orden'],
                'direccion' => $filters['direccion'] === 'asc' ? null : $filters['direccion'],
                'solo_disponibles' => $filters['solo_disponibles'] ? '1' : null,
            ], static fn (mixed $v): bool => $v !== null && $v !== false && $v !== ''),
        );

        return $this->render('products/index', [
            'title' => 'Productos',
            'active' => 'products',
            'paginator' => $paginator,
            'filters' => $filters,
            'categories' => $this->categories(),
            'sorts' => ProductRepository::SORTS,
        ]);
    }

    /**
     * Acepta id numérico o slug: /productos/3 y /productos/teclado-mecanico.
     */
    public function show(string $idOrSlug): Response
    {
        $product = ctype_digit($idOrSlug)
            ? $this->products->find((int) $idOrSlug)
            : $this->products->findBySlug($idOrSlug);

        if ($product === null) {
            throw new NotFoundHttpException("No existe el producto «{$idOrSlug}».");
        }

        // URL canónica: si llegaron por id, redirigir al slug bonito.
        if (ctype_digit($idOrSlug) && $product->slug !== '') {
            return $this->redirect(url('/productos/'.$product->slug));
        }

        return $this->render('products/show', [
            'title' => $product->nombre,
            'active' => 'products',
            'product' => $product,
            'related' => $this->related($product),
        ]);
    }

    public function create(): Response
    {
        return $this->render('products/form', [
            'title' => 'Nuevo producto',
            'active' => 'products',
            'mode' => 'create',
            'product' => null,
            'categories' => $this->categories(),
        ]);
    }

    public function store(): Response
    {
        $data = $this->validateOrFail($this->rules(), $this->ruleMessages(), $this->labels());

        $product = $this->products->create($data);
        $this->flash('success', 'Se creó «'.$product->nombre.'».');

        return $this->redirectToRoute('products.show', ['idOrSlug' => $product->slug ?: $product->id]);
    }

    public function edit(int $id): Response
    {
        return $this->render('products/form', [
            'title' => 'Editar producto',
            'active' => 'products',
            'mode' => 'edit',
            'product' => $this->products->findOrFail($id),
            'categories' => $this->categories(),
        ]);
    }

    public function update(int $id): Response
    {
        $data = $this->validateOrFail($this->rules(), $this->ruleMessages(), $this->labels());

        $product = $this->products->update($id, $data);
        $this->flash('success', 'Se actualizó «'.($product?->nombre ?? 'el producto').'».');

        return $this->redirectToRoute('products.show', ['idOrSlug' => (string) ($product?->slug ?? $id)]);
    }

    public function destroy(int $id): Response
    {
        $product = $this->products->findOrFail($id);

        if (!$this->products->delete($id)) {
            $this->flash('error', 'No se pudo eliminar «'.$product->nombre.'».');

            return $this->back('/productos');
        }

        $this->flash('success', 'Se eliminó «'.$product->nombre.'».');

        return $this->redirectToRoute('products.index');
    }

    /**
     * @return array<string, string>
     */
    private function categories(): array
    {
        $options = [];

        foreach (Product::CATEGORIES as $key) {
            $options[$key] = Product::CATEGORY_LABELS[$key];
        }

        $options['otros'] = Product::CATEGORY_LABELS['otros'];

        return $options;
    }

    private function related(Product $product): array
    {
        if ($product->categoria === '') {
            return [];
        }

        return $this->products->searchFiltered(
            category: $product->categoria,
            page: 1,
            perPage: 4,
            onlyAvailable: true
        )->items();
    }

    /**
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'nombre' => 'required|string|min:3|max:120',
            'slug' => 'nullable|slug|max:140',
            'descripcion' => 'nullable|string|max:2000',
            'precio' => 'required|numeric|min:0|max:99999999',
            'stock' => 'required|integer|min:0|max:1000000',
            'categoria' => 'required|in:'.implode(',', array_keys($this->categories())),
            'destacado' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function ruleMessages(): array
    {
        return [
            'precio.min' => 'El precio no puede ser negativo.',
            'nombre.min' => 'El nombre necesita al menos :min caracteres.',
            'categoria.in' => 'Escoge una categoría de la lista.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'nombre' => 'nombre',
            'slug' => 'URL amigable',
            'descripcion' => 'descripción',
            'precio' => 'precio',
            'stock' => 'existencias',
            'categoria' => 'categoría',
        ];
    }

    /**
     * Muestra el registro serializado tal cual lo devolvería la API: así se ve
     * que los acentos salen como "é" y no como \u00e9.
     */
    public function showJson(int $id): Response
    {
        return $this->json($this->products->findOrFail($id)->toArray());
    }
}
