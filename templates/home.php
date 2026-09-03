<?php
/**
 * @var int $total
 * @var array<string,int> $categories
 * @var array<int,\App\Models\Product> $featured
 */
$title = 'Micro-framework MVC en PHP 8';
?>
<section class="hero">
    <div class="hero-copy">
        <p class="kicker">De un tutorial de 2014 a un micro-framework de 2026</p>
        <h1>Rutas amigables en PHP, <span class="accent">sin jQuery de la vieja escuela</span></h1>
        <p class="lede">
            El mismo concepto del repo original —mapear <code>REQUEST_URI</code> a controladores— pero con
            namespaces PSR-4, <code>declare(strict_types=1)</code>, PDO con consultas preparadas,
            verbos HTTP, middleware, vistas con layout, validación, CSRF, paginación, logs y tests.
        </p>
        <div class="cta-row">
            <a class="btn btn-primary" href="<?= e(url('/productos')) ?>">Ver el listado de productos</a>
            <a class="btn" href="<?= e(url('/productos/nuevo')) ?>">Crear producto</a>
            <a class="btn btn-ghost" href="<?= e(url('/api/v1/productos')) ?>">Probar la API JSON</a>
        </div>
    </div>

    <div class="panel code-panel">
        <p class="panel-title">routes/web.php</p>
<pre class="code"><code>&lt;?php
return function (Router $router): void {
    $router->get('/productos', [ProductsController::class, 'index'])
        ->name('products.index');

    $router->get('/productos/:id(\\d+)', [ProductsController::class, 'show'])
        ->name('products.show');

    $router->group(['middleware' =&gt; CsrfMiddleware::class], function (Router $router) {
        $router->post('/productos', [ProductsController::class, 'store']);
    });
};</code></pre>
    </div>
</section>

<section class="grid grid-3">
    <article class="card">
        <h2>Estado de la demo</h2>
        <p class="muted small">Leído con <code>ProductRepository::count()</code> sobre
            <code>config('database.driver')</code> = <?= e((string) config('database.driver', 'sqlite')) ?>.</p>
        <dl class="kv">
            <dt>Productos</dt>
            <dd><?= (int) $total ?></dd>
            <dt>Categorías</dt>
            <dd><?= count($categories) ?></dd>
            <dt>PHP</dt>
            <dd><?= e(PHP_VERSION) ?></dd>
        </dl>

        <?php if ($categories !== []): ?>
            <ul class="chips">
                <?php foreach ($categories as $category => $count): ?>
                    <li>
                        <a href="<?= e(url('/productos?categoria='.rawurlencode((string) $category))) ?>">
                            <?= e(\App\Models\Product::CATEGORY_LABELS[$category] ?? (string) $category) ?>
                            <span class="count"><?= (int) $count ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>

    <article class="card">
        <h2>Qué se corrigió</h2>
        <ul class="ticks">
            <li><code>require 'core/Loader.php'</code> en minúsculas: reventaba en Linux.</li>
            <li>Singleton <code>Model::getModel()</code> con <code>mysqli</code> → <em>DI + PDO</em>.</li>
            <li>SQL concatenado → consultas preparadas.</li>
            <li><code>echo $producto-&gt;nombre</code> sin escapar → <code>e()</code>.</li>
            <li><code>$response instanceof Response</code>: la clase no existía.</li>
            <li>Dos <code>ob_start()</code> y ninguno liberado → buffer con <code>ob_get_clean()</code>.</li>
            <li>Credenciales en <code>Config.php</code> versionado → <code>.env</code> ignorado.</li>
        </ul>
    </article>

    <article class="card">
        <h2>Router</h2>
        <ul class="ticks">
            <li>Verbos <code>get/post/put/patch/delete</code> y <code>405</code> con header <em>Allow</em>.</li>
            <li>Restricciones: <code>:id(\d+)</code> o <code>-&gt;where('id','\d+')</code>.</li>
            <li>Grupos con <code>prefix</code>, <code>middleware</code> y <code>as</code>.</li>
            <li>Rutas nombradas: <code>route('products.show', ['idOrSlug' =&gt; $p-&gt;slug])</code>.</li>
            <li>Parámetros coercionados al tipo del método (con <code>strict_types</code> hace falta).</li>
            <li><code>fallback()</code> para el 404 propio.</li>
        </ul>
    </article>
</section>

<?php if ($featured !== []): ?>
    <section>
        <div class="section-head">
            <h2>Destacados</h2>
            <a class="small" href="<?= e(url('/productos?orden=precio&direccion=desc')) ?>">ver todos →</a>
        </div>
        <div class="grid grid-3">
            <?php foreach ($featured as $product): ?>
                <article class="card product-card">
                    <p class="muted small"><?= e($product->categoriaEtiqueta()) ?></p>
                    <h3><a href="<?= e(route('products.show', ['idOrSlug' => $product->slug !== '' ? $product->slug : $product->id])) ?>"><?= e($product->nombre) ?></a></h3>
                    <p><?= e($product->resumen(90)) ?></p>
                    <p class="price"><?= e($product->precioFormateado()) ?></p>
                    <p>
                        <?php if ($product->hayStock()): ?>
                            <span class="badge ok"><?= (int) $product->stock ?> en stock</span>
                        <?php else: ?>
                            <span class="badge warn">sin stock</span>
                        <?php endif; ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <h2> Pruébalo sin Composer </h2>
    <p class="muted">
        El proyecto se puede usar con <code>composer install</code> (autoloader PSR-4 de verdad) o sin nada
        de Composer: <code>src/Support/autoload.php</code> registra el mismo mapeo. Por eso la demo y los tests
        funcionan en cualquier PHP 8.2+.
    </p>
<pre class="code"><code>composer setup     # migra la base de datos y siembra datos de ejemplo
composer dev       # php -S 127.0.0.1:8000 -t public public/router.php
composer test      # suite completa (usa PHPUnit si vendor/ existe)</code></pre>
</section>
