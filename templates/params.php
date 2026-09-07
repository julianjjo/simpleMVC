<?php
/**
 * @var array<string, string> $params
 */
$title = 'Parámetros de ruta';
?>
<section class="page-head">
    <div>
        <h1>Parámetros de ruta</h1>
        <p class="muted">
            El ejemplo clásico del README: <code>/demo/:a/:b/:c</code>. El closure o método recibe los
            parámetros en el orden en que se declararon, ya pasados por <code>array_values()</code>
            (con <code>call_user_func_array</code> y claves nombradas, PHP 8 lanza
            <em>Unknown named parameter</em>).
        </p>
    </div>
</section>

<section class="card">
    <?php if ($params === []): ?>
        <p class="muted">Prueba con <code>/demo/uno/dos/tres</code>:</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr><th>#</th><th>parámetro</th><th>valor</th></tr>
            </thead>
            <tbody>
                <?php foreach ($params as $name => $value): ?>
                    <tr>
                        <td><?= (int) array_search($name, array_keys($params), true) + 1 ?></td>
                        <td><code>:<?= e($name) ?></code></td>
                        <td><?= e($value) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="cta-row">
        <?php $demoUrl = url('/demo/uno/dos/tres'); ?>
        <a class="btn btn-primary" href="<?= e($demoUrl) ?>">probar <?= e($demoUrl) ?></a>
    </div>
</section>

<section class="card">
    <h2 class="h4">Entradas libres</h2>
    <p class="muted small">Todo lo que se pinta aquí pasa por <code>e()</code>, así que un valor como
        <code>&lt;script&gt;</code> sale como texto y no se ejecuta.</p>
    <a class="btn btn-ghost" href="<?= e(url('/demo/'.rawurlencode('<img src=x onerror=alert(1)>').'/segundo/tercero')) ?>">
        probar un valor con HTML
    </a>
</section>
