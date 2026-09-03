-- Esquema para SQLite (demo por defecto: cero configuración).
-- Aplicar con: php bin/console.php migrate

CREATE TABLE IF NOT EXISTS productos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre      TEXT    NOT NULL,
    slug        TEXT    NOT NULL UNIQUE,
    descripcion TEXT    NOT NULL DEFAULT '',
    precio      REAL    NOT NULL DEFAULT 0,
    stock       INTEGER NOT NULL DEFAULT 0,
    categoria   TEXT    NOT NULL DEFAULT 'otros',
    destacado   INTEGER NOT NULL DEFAULT 0,
    creado_en   TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_productos_categoria ON productos (categoria);
CREATE INDEX IF NOT EXISTS idx_productos_nombre ON productos (nombre);
CREATE INDEX IF NOT EXISTS idx_productos_destacado ON productos (destacado);
