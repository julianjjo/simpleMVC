<?php
/**
 * @var \SimpleMvc\Core\Paginator $paginator
 * @var array<string, mixed>      $filters
 * @var array<string, string>     $categories
 * @var string[]                  $sorts
 */
$title = 'Productos';
$products = $paginator->items();
?>
<section class="page-head">
    <div>
        <h1>Listado de productos</h1>
        <p class="muted">
            <?= (int) $paginator->total() ?> resultado<?= $paginator->total() === 1 ? '' : 's' ?>
            <?php if ($paginator->total() > 0): ?>
                · mostrando <?= (int) $paginator->firstItem() ?>–<?= (int) $paginator->lastItem() ?>
            <?php endif; ?>
            · búsqueda, filtro y orden hechos con <code>QueryBuilder</code> y bindings (nada concatenado).
        </p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('/productos/nuevo')) ?>">+ Nuevo producto</a>
</section>

<form class="filters" method="get" action="<?= e(url('/productos')) ?>">
    <label class="field grow">
        <span>Buscar</span>
        <input type="search" name="q" value="<?= e((string) $filters['q']) ?>" placeholder="nombre, descripción o categoría" maxlength="80">
    </label>

    <label class="field">
        <span>Categoría</span>
        <select name="categoria">
            <option value="">Todas</option>
            <?php foreach ($categories as $key => $label): ?>
                <option value="<?= e((string) $key) ?>"<?= (string) $filters['categoria'] === (string) $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field">
        <span>Ordenar por</span>
        <select name="orden">
            <?php foreach ($sorts as $sort): ?>
                <option value="<?= e($sort) ?>"<?= $filters['orden'] === $sort ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $sort))) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="field">
        <span>Dirección</span>
        <select name="direccion">
            <option value="asc"<?= $filters['direccion'] === 'asc' ? ' selected' : '' ?>>ascendente</option>
            <option value="desc"<?= $filters['direccion'] === 'desc' ? ' selected' : '' ?>>descendente</option>
        </select>
    </label>

    <label class="checkbox">
        <input type="checkbox" name="solo_disponibles" value="1"<?= !empty($filters['solo_disponibles']) ? ' checked' : '' ?>>
        <span>Solo con stock</span>
    </label>

    <button class="btn btn-primary" type="submit">Aplicar</button>
    <a class="btn btn-ghost" href="<?= e(url('/productos')) ?>">Limpiar</a>
</form>

<?php if ($products === []): ?>
    <div class="empty">
        <h2>Nada por aquí</h2>
        <p class="muted">
            <?php if ((string) $filters['q'] !== '' || (string) $filters['categoria'] !== ''): ?>
                Ningún producto coincide con los filtros.
                <a href="<?= e(url('/productos')) ?>">Quitar filtros</a>.
            <?php else: ?>
                La tabla <code>productos</code> está vacía. Ejecuta
                <code>php bin/console.php setup</code> para crear el esquema y sembrar los datos de ejemplo.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <div class="grid grid-3">
        <?php foreach ($products as $product): ?>
            <article class="card product-card">
                <div class="card-top">
                    <span class="badge"><?= e($product->categoriaEtiqueta()) ?></span>
                    <?php if ($product->destacado): ?><span class="badge accent">destacado</span><?php endif; ?>
                </div>
                <h2 class="h4">
                    <a href="<?= e(route('products.show', ['idOrSlug' => $product->slug !== '' ? $product->slug : $product->id])) ?>">
                        <?= e($product->nombre) ?>
                    </a>
                </h2>
                <p class="muted"><?= e($product->resumen(110)) ?></p>
                <div class="card-foot">
                    <span class="price"><?= e($product->precioFormateado()) ?></span>
                    <?php if ($product->hayStock()): ?>
                        <span class="badge ok"><?= (int) $product->stock ?> uds.</span>
                    <?php else: ?>
                        <span class="badge warn">agotado</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($paginator->hasPages()): ?>
        <?php $listUrl = url('/productos'); ?>
        <nav class="pager" aria-label="Paginación">
            <?php if ($paginator->previousPage() !== null): ?>
                <a class="btn" href="<?= e($paginator->url($paginator->previousPage(), $listUrl)) ?>">← anterior</a>
            <?php endif; ?>

            <?php foreach ($paginator->window(7) as $page): ?>
                <?php if ($page === $paginator->currentPage()): ?>
                    <span class="btn is-current" aria-current="page"><?= (int) $page ?></span>
                <?php else: ?>
                    <a class="btn" href="<?= e($paginator->url($page, $listUrl)) ?>"><?= (int) $page ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($paginator->nextPage() !== null): ?>
                <a class="btn" href="<?= e($paginator->url($paginator->nextPage(), $listUrl)) ?>">siguiente →</a>
            <?php endif; ?>
        </nav>
        <p class="muted small">Página <?= (int) $paginator->currentPage() ?> de <?= (int) $paginator->lastPage() ?> · 9 por página</p>
    <?php endif; ?>
<?php endif; ?>
