# simpleMVC — atajos para el día a día.
# No hace falta GNU Make para usar el proyecto: todo esto también está como
# scripts de Composer (`composer test`, `composer setup`, …).

PHP ?= php
HOST ?= 127.0.0.1
PORT ?= 8000

.DEFAULT_GOAL := help
.PHONY: help install setup migrate seed routes test test-unit phpunit lint cs cs-fix serve serve-lan ping clean demo

help: ## Muestra esta ayuda
	@echo "simpleMVC — objetivos disponibles:"
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

install: ## Instala las dependencias de desarrollo (Composer)
	composer install --no-interaction --prefer-dist

setup: ## Crea el esquema de la BD y carga los datos de ejemplo
	$(PHP) bin/console.php setup

migrate: ## Aplica el esquema (sqlite o mysql según .env)
	$(PHP) bin/console.php migrate

seed: ## Carga los datos de ejemplo
	$(PHP) bin/console.php seed

routes: ## Lista las rutas registradas
	$(PHP) bin/console.php routes

ping: ## Comprueba la conexión a la base de datos
	$(PHP) bin/console.php ping

test: lint ## Revisa sintaxis y corre la suite (PHPUnit si existe, si no, el runner propio)
	$(PHP) tests/run.php

phpunit: ## Corre la suite con PHPUnit
	$(PHP) vendor/bin/phpunit

lint: ## Revisa la sintaxis de todos los .php
	$(PHP) bin/lint.php

cs: ## Diferencias de estilo sugeridas (requiere php-cs-fixer)
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Aplica las correcciones de estilo
	$(PHP) vendor/bin/php-cs-fixer fix

serve: ## Servidor de desarrollo en $(HOST):$(PORT)
	$(PHP) -S $(HOST):$(PORT) -t public public/router.php

serve-lan: ## Servidor de desarrollo accesible desde la red
	$(PHP) -S 0.0.0.0:$(PORT) -t public public/router.php

demo: setup serve ## Prepara la base de datos y levanta la demo
clean: ## Borra la base de datos de ejemplo y los logs
	rm -f storage/db/app.sqlite
	rm -f storage/logs/app.log
