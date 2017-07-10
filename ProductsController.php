<?php

class ProductsController {

	public static function index() {
		return '<h1>Lista de productos</h1>';
	}

	public static function show($name) {
		return '<h1>Detalles del producto: ' . $name . '</h1>';
	}

}
