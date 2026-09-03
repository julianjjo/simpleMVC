<?php
/**
 * Layout envuelto alrededor de cada plantilla.
 *
 * El `TemplateBase.php` original usaba `<?php $title ?>` (imprime nada) y
 * `<?php $content ?>`; aquí se usa `<?= ... ?>` y `e()` para todo lo que sea
 * dato dinámico.
 *
 * @var string $content
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($title) && $title !== '' ? $title : $app_name) ?> · <?= e($app_name) ?></title>
    <meta name="description" content="Micro-framework MVC en PHP 8.2+: router con verbos HTTP y middleware, PDO con consultas preparadas, vistas, validación y CSRF.">
    <link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body>
<a class="skip-link" href="#contenido">Saltar al contenido</a>

<header class="topbar">
    <div class="wrap topbar-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand-mark">&lt;/&gt;</span>
            <span class="brand-name"><?= e($app_name) ?></span>
            <span class="brand-tag">PHP <?= e(PHP_VERSION) ?></span>
        </a>

        <nav class="nav" aria-label="Principal">
            <?php
            $links = [
                '/' => 'Inicio',
                '/productos' => 'Productos',
                '/productos/nuevo' => 'Nuevo',
                '/api/v1/productos' => 'API JSON',
                '/salud' => 'Salud',
            ];
            ?>
            <?php foreach ($links as $href => $label): ?>
                <?php $isActive = $current_path === $href || ($href !== '/' && str_starts_with($current_path, rtrim($href, '/').'/')); ?>
                <a class="nav-link<?= $isActive ? ' is-active' : '' ?>"
                   href="<?= e(url($href)) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>

<main id="contenido" class="wrap stack">
    <?php if (!empty($flashes) && is_array($flashes)): ?>
        <?php foreach (['success', 'error', 'warning', 'info'] as $type): ?>
            <?php if (isset($flashes[$type]) && $flashes[$type] !== ''): ?>
                <div class="flash flash-<?= e($type) ?>" role="status"><?= e((string) $flashes[$type]) ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?= $content ?>
</main>

<footer class="footer">
    <div class="wrap footer-inner">
        <p>
            <strong>simpleMVC</strong> — micro-framework sin dependencias.
            Rutas amigables, verbos HTTP, middleware, PDO, vistas, validación y CSRF.
        </p>
        <p class="muted">
            <?= e(strtoupper((string) config('app.env', 'dev'))) ?>
            · DB <?= e((string) config('database.driver', 'sqlite')) ?>
            · debug <?= !empty($debug) ? 'sí' : 'no' ?>
            <?= !empty($debug) && !empty($request_uri) ? ' · '.e($request_uri) : '' ?>
        </p>
    </div>
</footer>
</body>
</html>
