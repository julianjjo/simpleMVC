<?php

require 'core/Loader.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$router = new Router($_SERVER['REQUEST_URI']);

$router->add('/rutas_amigables/', function ()
{
	return '<h1>Home</h1>';
});

$router->add('/rutas_amigables/productos', 'ProductsController::index');
$router->add('/rutas_amigables/productos/:id', 'ProductsController::show');

// /ruta/con/un/monton/de/parametros
$router->add('/rutas_amigables/:a/:b/:c/:d/:e/:f', function ($a, $b, $c, $d, $e, $f)
{
	return "$a<br>$b<br>$c<br>$d<br>$e<br>$f";
});

$router->run();
