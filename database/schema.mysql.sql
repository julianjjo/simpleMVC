-- Esquema para MySQL / MariaDB.
--   CREATE DATABASE mvc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   php bin/console.php migrate      (con DB_DRIVER=mysql en .env)
--
-- utf8mb4 en la tabla y `charset=utf8mb4` en el DSN: el original usaba mysqli
-- sin set_charset, así que los acentos dependían del default del servidor.

CREATE TABLE IF NOT EXISTS productos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre      VARCHAR(120) NOT NULL,
    slug        VARCHAR(140) NOT NULL,
    descripcion TEXT NOT NULL,
    precio      DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock       INT NOT NULL DEFAULT 0,
    categoria   VARCHAR(40) NOT NULL DEFAULT 'otros',
    destacado   TINYINT(1) NOT NULL DEFAULT 0,
    creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_productos_slug (slug),
    KEY idx_productos_categoria (categoria),
    KEY idx_productos_destacado (destacado)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
