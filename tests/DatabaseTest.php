<?php

declare(strict_types=1);

namespace Tests;

use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleMvc\Core\Database;
use SimpleMvc\Core\Paginator;
use SimpleMvc\Exceptions\DatabaseException;
use Tests\Support\InteractsWithDatabase;

final class DatabaseTest extends TestCase
{
    use InteractsWithDatabase;

    private Database $db;

    protected function setUp(): void
    {
        $this->db = $this->sqliteDatabase();

        $this->seedProducts($this->db, [
            ['Teclado mecánico', 'perifericos', 189900.0, 5, 'si'],
            ['Mouse inalámbrico', 'perifericos', 59900.0, 0, 'no'],
            ['Monitor 27" 4K', 'monitores', 980000.0, 12, 'si'],
            ['Monitor curvo 34"', 'monitores', 1250000.0, 3, 'no'],
            ['Audífonos con ANC', 'audio', 425000.0, 8, 'no'],
        ]);
    }

    // -----------------------------------------------------------------
    // Conexión
    // -----------------------------------------------------------------

    public function testConnexionYModos(): void
    {
        self::assertSame('sqlite', $this->db->driver());
        self::assertTrue($this->db->isSqlite());
        self::assertFalse($this->db->isMysql());
        self::assertSame(5, $this->db->selectOne('SELECT COUNT(*) AS c FROM productos')['c']);
    }

    public function testFallaConUnDriverDesconocido(): void
    {
        $db = new Database(new \SimpleMvc\Core\Config(['database' => ['driver' => 'oracle']]));

        $this->expectException(DatabaseException::class);
        $db->pdo();
    }

    // -----------------------------------------------------------------
    // Consultas
    // -----------------------------------------------------------------

    public function testSelectConBindings(): void
    {
        $rows = $this->db->select('SELECT nombre FROM productos WHERE stock > ? ORDER BY nombre', [4]);

        // stocks: 5, 0, 12, 3, 8
        self::assertCount(3, $rows);
        self::assertSame('Audífonos con ANC', $rows[0]['nombre']);
    }

    public function testSelectOneDevuelveNullCuandoNadaCoincide(): void
    {
        self::assertNull($this->db->selectOne('SELECT * FROM productos WHERE id = ?', [9999]));
        self::assertSame('Teclado mecánico', $this->db->scalar('SELECT nombre FROM productos WHERE stock = ?', [5]));
    }

    public function testConsultaInvalidaSeEnvuelveEnDatabaseException(): void
    {
        try {
            $this->db->select('SELECT * FROM tabla_que_no_existe');
            self::fail('Se esperaba DatabaseException.');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('Consulta fallida', $e->getMessage());
            self::assertStringContainsString('tabla_que_no_existe', $e->sql());
            self::assertInstanceOf(PDOException::class, $e->getPrevious());
        }
    }

    public function testInyeccionSqlNoFunciona(): void
    {
        // La entrada del usuario viaja como binding, no como texto del SQL.
        $malicioso = "'; DROP TABLE productos; --";

        $rows = $this->db->select('SELECT * FROM productos WHERE nombre = ?', [$malicioso]);
        self::assertSame([], $rows);

        $count = (int) $this->db->scalar('SELECT COUNT(*) FROM productos');
        self::assertSame(5, $count, 'la tabla sigue en pie');
    }

    public function testInsertUpdateDelete(): void
    {
        $id = $this->db->insert('productos', [
            'nombre' => 'Webcam 1080p',
            'slug' => 'webcam-1080p',
            'descripcion' => '',
            'precio' => 152000,
            'stock' => 2,
            'categoria' => 'perifericos',
            'destacado' => 0,
            'creado_en' => date('Y-m-d H:i:s'),
        ]);

        self::assertGreaterThan(0, $id);
        self::assertSame('Webcam 1080p', $this->db->selectOne('SELECT nombre FROM productos WHERE id = ?', [$id])['nombre']);

        self::assertSame(1, $this->db->update('productos', ['stock' => 0], ['id' => $id]));
        self::assertSame(0, (int) $this->db->scalar('SELECT stock FROM productos WHERE id = ?', [$id]));

        self::assertSame(1, $this->db->delete('productos', ['id' => $id]));
        self::assertNull($this->db->selectOne('SELECT * FROM productos WHERE id = ?', [$id]));
    }

    public function testElUpdateUneBindingsEnElOrdenCorrecto(): void
    {
        [$sql, $bindings] = $this->db->compileUpdate('productos', ['stock' => 99], ['id' => 2, 'categoria' => 'perifericos']);

        self::assertSame('UPDATE "productos" SET "stock" = ? WHERE "id" = ? AND "categoria" = ?', $sql);
        self::assertSame([99, 2, 'perifericos'], $bindings, 'primero el SET, después el WHERE');

        self::assertSame(1, $this->db->update('productos', ['stock' => 99], ['id' => 2, 'categoria' => 'perifericos']));
        self::assertSame(99, (int) $this->db->scalar('SELECT stock FROM productos WHERE id = 2'));
    }

    public function testUpdateConWhereNullUsaIsNotNull(): void
    {
        // La columna descripcion es NOT NULL en el esquema; lo que se comprueba
        // aquí es que un null en el WHERE se compile como IS NULL y no como
        // `= NULL` (que nunca casaría nada).
        self::assertSame(
            'UPDATE "productos" SET "categoria" = ? WHERE "descripcion" IS NULL',
            $this->db->compileUpdate('productos', ['categoria' => 'otros'], ['descripcion' => null])[0]
        );

        self::assertSame(
            'DELETE FROM "productos" WHERE "stock" = ?',
            $this->db->compileDelete('productos', ['stock' => 0])[0]
        );

        self::assertSame(1, $this->db->update('productos', ['categoria' => 'otros'], ['id' => 1]));
        self::assertSame(0, $this->db->update('productos', [], ['id' => 1]), 'sin columnas no hay UPDATE');
    }

    public function testTransaccionRevierte(): void
    {
        try {
            $this->db->transaction(function (Database $db): void {
                $db->statement("INSERT INTO productos (nombre, slug, precio, stock, categoria, destacado) VALUES ('Temporal', 'temporal', 1, 1, 'otros', 0)");

                throw new RuntimeException('algo falló a mitad');
            });
            self::fail('La excepción debe propagarse.');
        } catch (RuntimeException $e) {
            self::assertSame('algo falló a mitad', $e->getMessage());
        }

        self::assertSame(5, (int) $this->db->scalar('SELECT COUNT(*) FROM productos'), 'el INSERT se revirtió');
    }

    public function testTransaccionCommit(): void
    {
        $result = $this->db->transaction(fn (Database $db): int => $db->statement(
            "INSERT INTO productos (nombre, slug, precio, stock, categoria, destacado) VALUES ('Definitivo', 'definitivo', 2, 2, 'otros', 0)"
        ));

        self::assertSame(1, $result);
        self::assertSame(6, (int) $this->db->scalar('SELECT COUNT(*) FROM productos'));
    }

    // -----------------------------------------------------------------
    // QueryBuilder
    // -----------------------------------------------------------------

    public function testBuilderCompilaSqlYBindings(): void
    {
        $query = $this->db->table('productos')
            ->select('nombre', 'precio')
            ->where('stock', '>', 0)
            ->where('categoria', 'monitores')
            ->orderBy('precio', 'desc')
            ->limit(2);

        self::assertSame(
            'SELECT "nombre", "precio" FROM "productos" WHERE "stock" > ? AND "categoria" = ? ORDER BY "precio" DESC LIMIT 2',
            $query->toSql()
        );
        self::assertSame([0, 'monitores'], $query->getBindings());
        self::assertSame('SELECT "nombre", "precio" FROM "productos" WHERE "stock" > 0 AND "categoria" = \'monitores\' ORDER BY "precio" DESC LIMIT 2', $query->toRawSql());
    }

    public function testBuilderDosArgumentosSignificaIgualdad(): void
    {
        $rows = $this->db->table('productos')->where('categoria', 'audio')->get();

        self::assertCount(1, $rows);
        self::assertSame('Audífonos con ANC', $rows[0]['nombre']);
    }

    public function testOrWhere(): void
    {
        $rows = $this->db->table('productos')
            ->where('categoria', 'audio')
            ->orWhere('categoria', 'monitores')
            ->get();

        self::assertCount(3, $rows);
    }

    public function testWhereInVacioNoRevienta(): void
    {
        self::assertSame(0, $this->db->table('productos')->whereIn('categoria', [])->count());
        self::assertSame(5, $this->db->table('productos')->whereIn('categoria', [], not: true)->count());
        self::assertSame(3, $this->db->table('productos')->whereIn('categoria', ['monitores', 'audio'])->count());
    }

    public function testNullNotation(): void
    {
        // Nada es NULL en el set de datos de prueba: whereNull no devuelve filas
        // y whereNotNull, todas. Lo importante es el SQL compilado.
        self::assertSame(
            'SELECT * FROM "productos" WHERE "descripcion" IS NULL',
            $this->db->table('productos')->whereNull('descripcion')->toSql()
        );
        self::assertSame(0, $this->db->table('productos')->whereNull('descripcion')->count());
        self::assertSame(5, $this->db->table('productos')->whereNotNull('descripcion')->count());
        self::assertSame(0, $this->db->table('productos')->where('descripcion', null)->count());
        self::assertSame(5, $this->db->table('productos')->where('descripcion', '!=', null)->count());
    }

    public function testBetween(): void
    {
        self::assertSame(2, $this->db->table('productos')->whereBetween('precio', [400000, 1000000])->count());
        self::assertSame(3, $this->db->table('productos')->whereBetween('precio', [400000, 1000000], not: true)->count());

        try {
            $this->db->table('productos')->whereBetween('precio', [1]);
            self::fail('Se esperaba un error por el rango incompleto.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('[minimo, maximo]', $e->getMessage());
        }
    }

    public function testBusquedaLikeEscapaComodines(): void
    {
        $this->db->table('productos')->insert([
            'nombre' => 'Descuento 50% ya',
            'slug' => 'descuento-50',
            'descripcion' => 'termina_con_guión_',
            'precio' => 1,
            'stock' => 1,
            'categoria' => 'otros',
            'destacado' => 0,
        ]);

        // Sin escapar, el % de "50%" casaría con cualquier cosa.
        $hits = $this->db->table('productos')->whereAnyOf(['nombre'], '50%')->get();
        self::assertCount(1, $hits);
        self::assertSame('Descuento 50% ya', $hits[0]['nombre']);

        // '_' tampoco debe comportarse como "cualquier carácter".
        self::assertSame(0, $this->db->table('productos')->whereContains('nombre', '5_0')->count());
        self::assertSame(1, $this->db->table('productos')->whereContains('nombre', '50%')->count());
    }

    public function testOrdenamientoYEpaginacion(): void
    {
        $paginator = $this->db->table('productos')->orderBy('nombre')->paginate(2, 2, ['q' => 'monitor']);

        self::assertInstanceOf(Paginator::class, $paginator);
        self::assertSame(5, $paginator->total());
        self::assertSame(3, $paginator->lastPage());
        self::assertCount(2, $paginator->items());
        self::assertSame(2, $paginator->currentPage());
        self::assertSame('?q=monitor&page=2', $paginator->queryString(2));

        // La última página puede venir incompleta.
        $ultima = $this->db->table('productos')->orderBy('id')->paginate(3, 2);
        self::assertCount(1, $ultima->items());
        self::assertFalse($ultima->hasMorePages());
    }

    public function testAgregados(): void
    {
        self::assertSame(5, $this->db->table('productos')->count());
        self::assertSame(4, $this->db->table('productos')->where('stock', '>', 0)->count());
        self::assertSame(1250000.0, (float) $this->db->table('productos')->max('precio'));
        self::assertSame(59900.0, (float) $this->db->table('productos')->min('precio'));
        self::assertSame(28.0, $this->db->table('productos')->sum('stock'));
        self::assertSame(0.0, $this->db->table('productos')->where('id', 9999)->sum('stock'));
        self::assertSame(5.6, $this->db->table('productos')->avg('stock'));
        self::assertTrue($this->db->table('productos')->where('categoria', 'audio')->exists());
        self::assertFalse($this->db->table('productos')->where('categoria', 'nada')->exists());
    }

    public function testPluckYEfirst(): void
    {
        $names = $this->db->table('productos')->orderBy('id')->pluck('nombre');

        self::assertCount(5, $names);
        self::assertSame('Teclado mecánico', $names[0]);

        $byId = $this->db->table('productos')->pluck('nombre', 'id');
        self::assertSame('Teclado mecánico', $byId['1']);

        self::assertSame('Teclado mecánico', $this->db->table('productos')->orderBy('id')->first()['nombre']);
    }

    public function testFirstOrFailLanza404(): void
    {
        $this->expectException(\SimpleMvc\Exceptions\NotFoundHttpException::class);
        $this->db->table('productos')->where('id', 4242)->firstOrFail();
    }

    public function testRechazaIdentificadoresRaros(): void
    {
        foreach (['nombre; DROP TABLE productos', 'col WITH SPACE', "col\"quote", '(SELECT 1)'] as $bad) {
            try {
                $this->db->quoteIdent($bad);
                self::fail("Debería rechazar el identificador {$bad}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('no válido', $e->getMessage());
            }
        }

        self::assertSame('a.b', str_replace('"', '', $this->db->quoteIdent('a.b')));
    }

    public function testRechazaOperadoresExtranos(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->table('productos')->where('nombre', '='  . '; DELETE', 'x');
    }

    public function testInsertManyYActualizacionPorBuilder(): void
    {
        $afectadas = $this->db->table('productos')->where('categoria', 'perifericos')->update(['destacado' => 1]);

        self::assertSame(2, $afectadas);
        self::assertSame(2, $this->db->table('productos')->where('destacado', 1)->where('categoria', 'perifericos')->count());

        $this->db->unprepared('CREATE TABLE categorias_tmp (nombre TEXT NOT NULL)');
        $insertadas = $this->db->table('categorias_tmp')->insertMany([
            ['nombre' => 'Audio'],
            ['nombre' => 'Monitores'],
            ['nombre' => 'Periféricos'],
        ]);

        self::assertSame(3, $insertadas);
        self::assertSame(['Audio', 'Monitores', 'Periféricos'], $this->db->table('categorias_tmp')->orderBy('nombre')->pluck('nombre'));
        self::assertSame(0, $this->db->table('categorias_tmp')->insertMany([]));
    }

    public function testUpdateOrInsert(): void
    {
        $query = $this->db->table('productos');

        self::assertTrue($query->updateOrInsert(['slug' => 'nuevo-item'], ['nombre' => 'Nuevo item', 'precio' => 10, 'stock' => 1, 'categoria' => 'otros', 'destacado' => 0, 'descripcion' => '']));
        self::assertSame(1, $this->db->table('productos')->where('slug', 'nuevo-item')->count());

        // Segunda vez: actualiza en lugar de duplicar.
        $this->db->table('productos')->updateOrInsert(['slug' => 'nuevo-item'], ['stock' => 7]);
        self::assertSame(1, $this->db->table('productos')->where('slug', 'nuevo-item')->count());
        self::assertSame(7, (int) $this->db->scalar("SELECT stock FROM productos WHERE slug = 'nuevo-item'"));
    }

    public function testDivideSentenciasRespetandoComillas(): void
    {
        $statements = $this->db->splitStatements("INSERT INTO t VALUES ('a;b');\n-- comentario\nINSERT INTO t VALUES ('c');");

        self::assertCount(2, $statements);
        self::assertStringContainsString("'a;b'", $statements[0]);
    }

    public function testLogDeConsultasSoloEnDebug(): void
    {
        $this->db->select('SELECT 1');

        self::assertNotEmpty($this->db->queryLog());
        self::assertStringContainsString('SELECT 1', implode("\n", $this->db->queryLog()));
    }
}
