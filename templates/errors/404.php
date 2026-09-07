<?php
/**
 * @var int $status
 * @var string $message
 * @var array{method: string, uri: string, query: array, body: array} $request
 */
$title = '404 — Página no encontrada';
?>
<section class="error-page">
    <p class="error-code">404</p>
    <h1>Página no encontrada</h1>
    <p class="muted"><?= e($message) ?></p>

    <div class="card">
        <h2 class="h4">Rutas registradas</h2>
        <p class="muted small">
            El router no encontró coincidencia. Si instalaste el proyecto en un subdirectorio,
            recuerda que el prefijo se autodetecta (config <code>app.base_path</code>).
        </p>
        <ul class="ticks">
            <?php
            $routes = app(\SimpleMvc\Core\Router::class)->routes();
            $shown = 0;

            foreach ($routes as $route):
                if ($shown++ >= 12) {
                    break;
                }
                ?>
                <li>
                    <code><?= e(implode('|', $route->allowedMethods())) ?></code>
                    <a href="<?= e(url($route->uri())) ?>"><?= e($route->uri()) ?></a>
                    <?php if ($route->getName() !== null): ?>
                        <span class="muted">· <?= e($route->getName()) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="cta-row">
        <a class="btn btn-primary" href="<?= e(url('/')) ?>">Ir al inicio</a>
        <a class="btn" href="<?= e(url('/productos')) ?>">Ver productos</a>
    </div>
</section>
