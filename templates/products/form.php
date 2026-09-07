<?php
/**
 * Formulario de alta y edición.
 *
 * @var string             $mode      'create' | 'edit'
 * @var \App\Models\Product|null $product
 * @var array<string,string> $categories
 * @var array<string, array<int,string>> $errors
 */
$isEdit = $mode === 'edit' && $product !== null;
$action = $isEdit ? url('/productos/'.$product->id) : url('/productos');
$title = $isEdit ? 'Editar '.$product->nombre : 'Nuevo producto';

$value = static function (string $field, mixed $fallback = '') use ($product): string {
    $current = $product?->{$field};

    return old($field, (string) ($current ?? $fallback));
};
?>
<section class="page-head">
    <div>
        <p class="crumbs"><a href="<?= e(url('/productos')) ?>">Productos</a> / <span><?= e($title) ?></span></p>
        <h1><?= e($title) ?></h1>
        <p class="muted">
            Validación con <code>Validator</code>, token CSRF y <code><?= $isEdit ? 'PUT' : 'POST' ?></code>.
            Los errores vuelven por flash y el formulario conserva lo escrito.
        </p>
    </div>
</section>

<?php if ($errors !== []): ?>
    <div class="flash flash-error">
        <strong>Revisa <?= count($errors) ?> campo<?= count($errors) === 1 ? '' : 's' ?>:</strong>
        <ul class="errors">
            <?php foreach ($errors as $field => $messages): ?>
                <?php foreach ($messages as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form class="card form" method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <div class="form-grid">
        <label class="field">
            <span>Nombre *</span>
            <input type="text" name="nombre" value="<?= e($value('nombre')) ?>" required minlength="3" maxlength="120"
                   aria-describedby<?= isset($errors['nombre']) ? '="err-nombre"' : '' ?>>
            <?php if (isset($errors['nombre'])): ?>
                <small class="field-error" id="err-nombre"><?= e($errors['nombre'][0]) ?></small>
            <?php endif; ?>
        </label>

        <label class="field">
            <span>Categoría *</span>
            <select name="categoria" required>
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= e((string) $key) ?>"<?= $value('categoria', 'perifericos') === (string) $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['categoria'])): ?>
                <small class="field-error"><?= e($errors['categoria'][0]) ?></small>
            <?php endif; ?>
        </label>

        <label class="field">
            <span>Precio *</span>
            <input type="number" name="precio" step="0.01" min="0" value="<?= e($value('precio', '0')) ?>" required>
            <?php if (isset($errors['precio'])): ?>
                <small class="field-error"><?= e($errors['precio'][0]) ?></small>
            <?php endif; ?>
        </label>

        <label class="field">
            <span>Existencias *</span>
            <input type="number" name="stock" step="1" min="0" value="<?= e($value('stock', '0')) ?>" required>
            <?php if (isset($errors['stock'])): ?>
                <small class="field-error"><?= e($errors['stock'][0]) ?></small>
            <?php endif; ?>
        </label>

        <label class="field grow">
            <span>URL amigable (opcional)</span>
            <input type="text" name="slug" value="<?= e($value('slug')) ?>" maxlength="140" placeholder="se genera del nombre si lo dejas vacío">
            <?php if (isset($errors['slug'])): ?>
                <small class="field-error"><?= e($errors['slug'][0]) ?></small>
            <?php endif; ?>
        </label>

        <label class="field grow">
            <span>Descripción</span>
            <textarea name="descripcion" rows="5" maxlength="2000"><?= e($value('descripcion')) ?></textarea>
            <?php if (isset($errors['descripcion'])): ?>
                <small class="field-error"><?= e($errors['descripcion'][0]) ?></small>
            <?php endif; ?>
        </label>

        <label class="checkbox">
            <input type="checkbox" name="destacado" value="1"<?= $value('destacado', '0') === '1' ? ' checked' : '' ?>>
            <span>Marcar como destacado</span>
        </label>
    </div>

    <div class="cta-row form-actions">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Crear producto' ?></button>
        <a class="btn btn-ghost" href="<?= e($isEdit ? url('/productos/'.$product->id) : url('/productos')) ?>">Cancelar</a>
    </div>
</form>
