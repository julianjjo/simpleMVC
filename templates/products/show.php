<?php
/**
 * @var \App\Models\Product              $product
 * @var array<int,\App\Models\Product>   $related
 */
$title = $product->nombre;
?>
<section class="page-head">
    <div>
        <p class="crumbs"><a href="<?= e(url('/productos')) ?>">Productos</a> / <span><?= e($product->nombre) ?></span></p>
        <h1><?= e($product->nombre) ?></h1>
        <p class="muted">
            <?= e($product->categoriaEtiqueta()) ?>
            <?php if ($product->creadoEn !== null): ?>
                · alta el <?= e($product->creadoEn->format('d/m/Y')) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="cta-row">
        <a class="btn" href="<?= e(route('products.edit', ['id' => $product->id])) ?>">Editar</a>
        <form method="post" action="<?= e(route('products.delete', ['id' => $product->id])) ?>"
              onsubmit="return confirm('¿Eliminar «<?= e($product->nombre) ?>»?');">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit">Eliminar</button>
        </form>
    </div>
</section>

<section class="grid grid-detail">
    <article class="card">
        <h2 class="h4">Descripción</h2>
        <p><?= $product->descripcion === '' ? '<span class="muted">Sin descripción.</span>' : nl2br(e($product->descripcion)) ?></p>

        <dl class="kv">
            <dt>Precio</dt>
            <dd><?= e($product->precioFormateado()) ?></dd>
            <dt>Existencias</dt>
            <dd><?= (int) $product->stock ?> unidades <?= $product->hayStock() ? '<span class="badge ok">disponible</span>' : '<span class="badge warn">agotado</span>' ?></dd>
            <dt>Categoría</dt>
            <dd><?= e($product->categoria) ?></dd>
            <dt>URL amigable</dt>
            <dd><code><?= e($product->slug) ?></code></dd>
        </dl>
    </article>

    <article class="card">
        <h2 class="h4">Así lo devuelve la API</h2>
        <p class="muted small">
            <code>GET <?= e(url('/api/v1/productos/'.rawurlencode($product->slug !== '' ? $product->slug : (string) $product->id))) ?></code>
            — mismo Record, serializado con <code>json_encode</code> sin escapar Unicode.
        </p>
<pre class="code"><code><?= e(json_encode($product->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?></code></pre>
        <a class="btn btn-ghost" href="<?= e(url('/productos/'.$product->id.'/json')) ?>">Ver respuesta JSON</a>
    </article>
</section>

<?php if ($related !== []): ?>
    <section>
        <h2 class="h4">Otros en <?= e($product->categoriaEtiqueta()) ?></h2>
        <div class="grid grid-4">
            <?php foreach ($related as $other): ?>
                <?php if ($other->id === $product->id) { continue; } ?>
                <article class="card compact">
                    <h3 class="h5"><a href="<?= e(route('products.show', ['idOrSlug' => $other->slug ?: $other->id])) ?>"><?= e($other->nombre) ?></a></h3>
                    <p class="price"><?= e($other->precioFormateado()) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
