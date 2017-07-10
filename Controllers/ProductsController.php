<?php

class ProductsController {

	public static function index() {
		$model = Model::getModel();
		$model->setQuery("SELECT * FROM productos");
		$productos = $model->getResult();
		return View::getTemplate('Productos', array( 'productos'	=> $productos ));
	}

	public static function show($id) {
		return '<h1>Detalles del producto: ' . $id . '</h1>';
	}

}
