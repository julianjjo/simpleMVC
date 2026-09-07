<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

use PDO;
use PDOException;
use PDOStatement;
use SimpleMvc\Exceptions\DatabaseException;
use RuntimeException;
use Throwable;

/**
 * Acceso a datos con PDO (MySQL o SQLite) y consultas preparadas.
 *
 * Reemplaza al `Model` original, que era un singleton con `mysqli` y un
 * `setQuery("SELECT ... " . $id)` abierto a inyección SQL. Aquí toda consulta
 * parametrizada usa placeholders y tipos de PDO.
 */
final class Database
{
    private ?PDO $pdo = null;

    private int $transactionLevel = 0;

    /** @var array<int, string> */
    private array $log = [];

    public function __construct(private Config $config)
    {
    }

    public function driver(): string
    {
        return strtolower((string) $this->config->get('database.driver', 'sqlite'));
    }

    public function isSqlite(): bool
    {
        return $this->driver() === 'sqlite';
    }

    public function isMysql(): bool
    {
        return in_array($this->driver(), ['mysql', 'mariadb'], true);
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    private function connect(): PDO
    {
        $driver = $this->driver();

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        try {
            return match (true) {
                $driver === 'sqlite' => $this->connectSqlite($options),
                $this->isMysql() => $this->connectMysql($options),
                default => throw new RuntimeException(
                    "Controlador de base de datos no soportado: {$driver} (usa sqlite o mysql)."
                ),
            };
        } catch (Throwable $e) {
            if ($e instanceof DatabaseException) {
                throw $e;
            }

            throw new DatabaseException(
                'No se pudo conectar a la base de datos ('.$driver.'): '.$e->getMessage().
                '. Revisa el .env — para la demo basta con DB_DRIVER=sqlite.',
                '',
                [],
                $e
            );
        }
    }

    /**
     * @param  array<int, mixed>  $options
     */
    private function connectSqlite(array $options): PDO
    {
        $path = (string) $this->config->get('database.sqlite.path', ':memory:');

        if ($path !== ':memory:') {
            $directory = dirname($path);

            if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new RuntimeException("No se pudo crear el directorio de la base de datos: {$directory}");
            }
        }

        $pdo = new PDO('sqlite:'.$path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }

    /**
     * @param  array<int, mixed>  $options
     */
    private function connectMysql(array $options): PDO
    {
        $host = (string) $this->config->get('database.mysql.host', '127.0.0.1');
        $port = (int) $this->config->get('database.mysql.port', 3306);
        $database = (string) $this->config->get('database.mysql.database', '');
        $charset = (string) $this->config->get('database.mysql.charset', 'utf8mb4');

        // El charset en el DSN es clave: sin él, utf8mb4 depende del servidor
        // y los acentos se guardaban como "??" (fallo clásico de mysqli sin
        // set_charset).
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset);

        if ($database !== '') {
            $dsn .= ';dbname='.$database;
        }

        return new PDO(
            $dsn,
            (string) $this->config->get('database.mysql.username', 'root'),
            (string) ($this->config->get('database.mysql.password') ?? ''),
            $options + (array) $this->config->get('database.mysql.options', [])
        );
    }

    // -----------------------------------------------------------------
    // Consultas
    // -----------------------------------------------------------------

    /**
     * @param  array<array-key, mixed>  $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * INSERT/UPDATE/DELETE. Devuelve el número de filas afectadas.
     *
     * @param  array<array-key, mixed>  $bindings
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Varias sentencias (migraciones).
     */
    public function unprepared(string $sql): void
    {
        $this->log(__METHOD__, $sql);

        try {
            $this->pdo()->exec($sql);
        } catch (PDOException $e) {
            throw new DatabaseException('Error al ejecutar el SQL: '.$e->getMessage(), $sql, [], $e);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(string $table, array $values): int
    {
        if ($values === []) {
            throw new RuntimeException('No se puede insertar un arreglo vacío.');
        }

        $columns = [];
        $placeholders = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $columns[] = $this->quoteIdent((string) $column);
            $placeholders[] = '?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdent($table),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->run($sql, $bindings);

        return (int) $this->lastInsertId();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $where
     */
    public function update(string $table, array $values, array $where): int
    {
        if ($values === []) {
            return 0;
        }

        [$sql, $bindings] = $this->compileUpdate($table, $values, $where);

        return $this->statement($sql, $bindings);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    public function delete(string $table, array $where): int
    {
        [$sql, $bindings] = $this->compileDelete($table, $where);

        return $this->statement($sql, $bindings);
    }

    /**
     * Compila un UPDATE sin ejecutarlo. Expuesto porque permite comprobar en un
     * test cómo se traduce un `where` (por ejemplo un null => IS NULL) sin tener
     * que escribir datos que violen restricciones NOT NULL.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $where
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compileUpdate(string $table, array $values, array $where): array
    {
        [$clause, $whereBindings] = $this->compileWhere($where);
        $sets = [];
        $valueBindings = [];

        foreach ($values as $column => $value) {
            $sets[] = $this->quoteIdent((string) $column).' = ?';
            $valueBindings[] = $value;
        }

        return [
            sprintf(
                'UPDATE %s SET %s%s',
                $this->quoteIdent($table),
                implode(', ', $sets),
                $clause === '' ? '' : ' WHERE '.$clause
            ),
            // Los placeholders del SET van primero: el ORDER importa más que la
            // comodidad. Al revés, el UPDATE no casa ninguna fila y parece que
            // «no hubo cambios» en lugar de fallar.
            array_merge($valueBindings, $whereBindings),
        ];
    }

    /**
     * @param  array<string, mixed>  $where
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compileDelete(string $table, array $where): array
    {
        [$clause, $bindings] = $this->compileWhere($where);

        if ($clause === '') {
            return [sprintf('DELETE FROM %s', $this->quoteIdent($table)), []];
        }

        return [sprintf('DELETE FROM %s WHERE %s', $this->quoteIdent($table), $clause), $bindings];
    }

    /**
     * @param  array<string, mixed>  $where
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function compileWhere(array $where): array
    {
        $clause = [];
        $bindings = [];

        foreach ($where as $column => $value) {
            if ($value === null) {
                $clause[] = $this->quoteIdent((string) $column).' IS NULL';

                continue;
            }

            $clause[] = $this->quoteIdent((string) $column).' = ?';
            $bindings[] = $value;
        }

        return [implode(' AND ', $clause), $bindings];
    }

    public function lastInsertId(): string|int
    {
        return $this->pdo()->lastInsertId();
    }

    /**
     * @template T
     *
     * @param  callable(Database): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($this->transactionLevel > 0) {
            ++$this->transactionLevel;

            try {
                return $callback($this);
            } finally {
                --$this->transactionLevel;
            }
        }

        $pdo->beginTransaction();
        ++$this->transactionLevel;

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        } finally {
            --$this->transactionLevel;
        }
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * Ejecuta un archivo .sql (migración o seed).
     */
    public function runSqlFile(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("El archivo SQL no existe: {$path}");
        }

        foreach ($this->splitStatements((string) file_get_contents($path)) as $statement) {
            $this->unprepared($statement);
        }
    }

    /**
     * Divide un script en sentencias, ignorando `;` dentro de comillas.
     *
     * @return array<int, string>
     */
    public function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = [];
        $current = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];

            if ($char === "'" && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inString = !$inString;
            }

            if ($char === ';' && !$inString) {
                $trimmed = trim($current);

                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }

                $current = '';

                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);

        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Identificadores (tablas/columnas) validados y citados: la única parte
     * de una consulta que no puede ir como binding, así que se blinda aquí.
     */
    public function quoteIdent(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier) !== 1) {
            throw new RuntimeException("Nombre de tabla o columna no válido: {$identifier}");
        }

        [$qualifier, $column] = [null, $identifier];

        if (str_contains($identifier, '.')) {
            [$qualifier, $column] = explode('.', $identifier, 2);
        }

        $wrap = $this->isMysql() ? '`' : '"';

        $quote = static fn (string $part): string => $wrap.$part.$wrap;

        return $qualifier === null ? $quote($column) : $quote($qualifier).'.'.$quote($column);
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    private function run(string $sql, array $bindings = []): PDOStatement
    {
        $this->log(__FUNCTION__, $sql, $bindings);

        try {
            $statement = $this->pdo()->prepare($sql);
            $this->bind($statement, $bindings);
            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            throw new DatabaseException(
                'Consulta fallida: '.$e->getMessage(),
                $sql,
                $bindings,
                $e
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    private function bind(PDOStatement $statement, array $bindings): void
    {
        $position = 1;

        foreach ($bindings as $key => $value) {
            $name = is_int($key) ? $position : $key;
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                is_float($value) => PDO::PARAM_STR,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue($name, is_bool($value) ? (int) $value : $value, $type);

            if (is_int($key)) {
                ++$position;
            }
        }
    }

    /**
     * Últimas consultas ejecutadas (depuración y tests).
     *
     * @return array<int, string>
     */
    public function queryLog(): array
    {
        return $this->log;
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    private function log(string $kind, string $sql, array $bindings = []): void
    {
        if (!$this->config->isDebug()) {
            return;
        }

        $this->log[] = trim($sql.($bindings === [] ? '' : ' -- '.json_encode($bindings, JSON_UNESCAPED_UNICODE)));

        if (count($this->log) > 200) {
            array_shift($this->log);
        }
    }
}
