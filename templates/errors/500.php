<?php
/**
 * Página de error. Con APP_DEBUG=false solo se ve el mensaje genérico: la traza,
 * los argumentos y los datos de la petición nunca se imprimen en producción.
 *
 * @var int $status
 * @var string $message
 * @var \Throwable|null $exception
 * @var array<int, array{file: string, line: int, call: string}> $frames
 * @var array{method: string, uri: string, query: array, body: array} $request
 */
$title = $status.' — Error del servidor';
?>
<section class="error-page">
    <p class="error-code"><?= (int) $status ?></p>
    <h1><?= $status >= 500 ? 'Algo se rompió en el servidor' : 'Petición no válida' ?></h1>
    <p class="muted"><?= e($message) ?></p>

    <?php if ($exception !== null): ?>
        <div class="card">
            <h2 class="h4">Detalle técnico <span class="badge warn">solo con APP_DEBUG=true</span></h2>
            <p><code><?= e($exception::class) ?></code></p>
            <p class="muted small">
                <code><?= e($exception->getFile()) ?>:<?= (int) $exception->getLine() ?></code>
            </p>

            <?php if ($frames !== []): ?>
                <ol class="frames">
                    <?php foreach ($frames as $i => $frame): ?>
                        <li>
                            <span class="muted"><?= e($frame['call']) ?></span><br>
                            <code><?= e($frame['file']) ?>:<?= (int) $frame['line'] ?></code>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 class="h4">Petición</h2>
            <p><code><?= e($request['method']) ?> <?= e($request['uri']) ?></code></p>
            <?php if ($request['query'] !== []): ?>
                <pre class="code"><code><?= e(print_r($request['query'], true)) ?></code></pre>
            <?php endif; ?>
            <?php if ($request['body'] !== []): ?>
                <pre class="code"><code><?= e(print_r($request['body'], true)) ?></code></pre>
            <?php endif; ?>
        </div>

        <?php if ($exception->getPrevious() !== null): ?>
            <div class="card">
                <h2 class="h4">Causa original</h2>
                <p><code><?= e($exception->getPrevious()::class) ?></code> — <?= e($exception->getPrevious()->getMessage()) ?></p>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted small">
            Para ver la traza completa activa <code>APP_DEBUG=true</code> en <code>.env</code>.
            El error ya quedó registrado en <code>storage/logs/app.log</code>.
        </p>
    <?php endif; ?>

    <div class="cta-row">
        <a class="btn btn-primary" href="<?= e(url('/')) ?>">Volver al inicio</a>
    </div>
</section>
