<?php $title = 'Hola '.$nombre; ?>
<h1>Hola <?= e($nombre) ?></h1>
<p> <?= e($notas[0] ?? '') ?> </p>
