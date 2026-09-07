<?php
// Plantilla que falla a mitad de la impresión: hay que cerrar el buffer igual.
$titulo = 'empezado';
throw new RuntimeException('planta rota');
