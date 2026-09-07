<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Product;
use RuntimeException;
use App\Repositories\ProductRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SimpleMvc\Core\Database;
use SimpleMvc\Exceptions\NotFoundHttpException;
use Tests\Support\InteractsWithDatabase;

final class ProductRepositoryTest extends TestCase
{
    use InteractsWithDatabase;

    private Database $db;

    private ProductRepository $repo;

    protected function setUp(): void
    {
        $this->db = $this->sqliteDatabase();
        $this->repo = new ProductRepository($this->db);
    }

    public function testElSeedDelProyectoInsertaProductos(): void
    {
        $seeder = require dirname(__DIR__).'/database/seeds.php';

        self::assertSame(14, $seeder($this->db));
        self::assertSame(0, $seeder($this->db), 'repetir el seed no duplica');
        self::assertSame(14, $seeder($this->db, true));
        self::assertSame(14, $this->repo->count());
    }

    public function testHidratacionTipada(): void
    {
        $seeder = require dirname(__DIR__).'/database/seeds.php';
        $seeder($this->db);

        $product = $this->repo->findBySlug('teclado-mecanico-inalambrico-k80');

        self::assertInstanceOf(Product::class, $product);
        self::assertIsInt($product->id);
        self::assertSame('Teclado mecánico inalámbrico K80', $product->nombre);
        self::assertSame(189900.0, $product->precio);
        self::assertSame(24, $product->stock);
        self::assertTrue($product->destacado);
        self::assertInstanceOf(DateTimeImmutable::class, $product->creadoEn);
        self::assertSame('Periféricos', $product->categoriaEtiqueta());
        self::assertTrue($product->hayStock());
        self::assertStringContainsString('COP', $product->precioFormateado());
        self::assertStringContainsString('Switches', $product->resumen(20));
    }

    public function testFromRowFallaSiFaltaUnCampoObligatorio(): void
    {
        $producto = Product::fromRow(['id' => 1, 'nombre' => 'X', 'precio' => '9.5', 'destacado' => '1']);

        self::assertSame(9.5, $producto->precio);
        self::assertTrue($producto->destacado);
        self::assertSame('', $producto->slug, 'los campos con default se omiten');
        self::assertNull($producto->creadoEn);
    }

    public function testCreateGeneraSlugYConservaTipos(): void
    {
        $product = $this->repo->create([
            'nombre' => 'Hub USB-C',
            'descripcion' => 'Puertos varios',
            'precio' => '89900,50',
            'stock' => '7',
            'categoria' => 'perifericos',
            'destacado' => '1',
            'id' => 999,               // inyectado: debe ignorarse
        ]);

        self::assertSame('hub-usb-c', $product->slug);
        self::assertSame(89900.5, $product->precio);
        self::assertSame(7, $product->stock);
        self::assertTrue($product->destacado);
        self::assertNotSame(999, $product->id, 'el id lo pone la base de datos');

        self::assertIsString($product->descripcion);
        self::assertInstanceOf(DateTimeImmutable::class, $product->creadoEn);
    }

    public function testSlugUnicoConColisiones(): void
    {
        $first = $this->repo->create(['nombre' => 'Monitor X', 'precio' => 1, 'stock' => 1, 'categoria' => 'monitores']);
        $second = $this->repo->create(['nombre' => 'Monitor X', 'precio' => 2, 'stock' => 1, 'categoria' => 'monitores']);
        $third = $this->repo->create(['nombre' => 'Monitor X', 'precio' => 3, 'stock' => 1, 'categoria' => 'monitores']);

        self::assertSame('monitor-x', $first->slug);
        self::assertSame('monitor-x-2', $second->slug);
        self::assertSame('monitor-x-3', $third->slug);

        // Actualizar un producto con su propio slug no debe chocar consigo mismo.
        $updated = $this->repo->update($second->id, ['slug' => 'monitor-x-2', 'nombre' => 'Monitor X plus']);
        self::assertSame('monitor-x-2', $updated?->slug);
        self::assertSame('Monitor X plus', $updated?->nombre);
    }

    public function testUnaFechaRotaSeSeñalaEnLugarDeCallarse(): void
    {
        // Regla deliberada: si una fila trae un creado_en ilegible (los datos del
        // tutorial antiguo podían traer cualquier cosa), Record lo dice en vez de
        // convertirlo en null y perder la fecha para siempre.
        $this->db->statement("INSERT INTO productos (nombre, slug, precio, stock, creado_en) VALUES ('Roto', 'roto', 1, 1, 'ayer-ish')");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no es una fecha válida/');

        $this->repo->findBySlug('roto');
    }

    public function testUpdateYCambioDeSlug(): void
    {
        $product = $this->repo->create(['nombre' => 'Viejo nombre', 'precio' => 10, 'stock' => 1, 'categoria' => 'audio']);

        $updated = $this->repo->update($product->id, ['nombre' => 'Nuevo nombre', 'precio' => '20.5']);

        self::assertSame('Nuevo nombre', $updated?->nombre);
        self::assertSame(20.5, $updated?->precio);
        self::assertSame('nuevo-nombre', $updated?->slug, 'sin slug explícito se regenera desde el nombre');
        self::assertSame(1, $updated?->stock, 'los campos no enviados no se tocan');
    }

    public function testUpdateVacioNoEscribe(): void
    {
        $product = $this->repo->create(['nombre' => 'Intacto', 'precio' => 5, 'stock' => 5, 'categoria' => 'audio']);

        self::assertSame('Intacto', $this->repo->update($product->id, [])?->nombre);
    }

    public function testDelete(): void
    {
        $product = $this->repo->create(['nombre' => 'Borrable', 'precio' => 1, 'stock' => 1, 'categoria' => 'otros']);

        self::assertTrue($this->repo->delete($product->id));
        self::assertFalse($this->repo->delete($product->id));
        self::assertNull($this->repo->find($product->id));
    }

    public function testFindOrFail(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->repo->findOrFail(4242);
    }

    public function testBusquedaFiltroOrdenYDisponibilidad(): void
    {
        foreach ([
            ['Teclado inalámbrico', 'perifericos', 100000.0, 4, 'no'],
            ['Teclado con cable', 'perifericos', 50000.0, 0, 'no'],
            ['Monitor 4K', 'monitores', 900000.0, 2, 'si'],
            ['Altavoz BT', 'audio', 30000.0, 9, 'no'],
        ] as $row) {
            $this->seedProducts($this->db, [$row]);
        }

        $todos = $this->repo->searchFiltered();
        self::assertSame(4, $todos->total());

        $teclados = $this->repo->searchFiltered(term: 'teclado');
        self::assertSame(2, $teclados->total());

        $porCategoria = $this->repo->searchFiltered(category: 'audio');
        self::assertSame(1, $porCategoria->total());
        self::assertSame('Altavoz BT', $porCategoria->items()[0]->nombre);

        $disponibles = $this->repo->searchFiltered(onlyAvailable: true);
        self::assertSame(3, $disponibles->total());

        $porPrecio = $this->repo->searchFiltered(sort: 'precio', direction: 'desc');
        self::assertSame('Monitor 4K', $porPrecio->items()[0]->nombre);

        // Un orden inyectado desde la query no puede colarse en el SQL.
        $raro = $this->repo->searchFiltered(sort: 'nombre; DROP TABLE productos');
        self::assertSame(4, $raro->total(), 'se ignora el orden inválido y usa el default');
    }

    public function testBusquedaNoEncuentraNadaConPatrones(): void
    {
        $this->seedProducts($this->db, [['Caja % especial', 'otros', 10.0, 1, 'no']]);

        self::assertSame(1, $this->repo->searchFiltered(term: '% especial')->total());
        self::assertSame(0, $this->repo->searchFiltered(term: 'cualquier%cosa')->total());
    }

    public function testPaginacion(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            $this->seedProducts($this->db, [["Producto $i", 'otros', (float) $i, 1, 'no']]);
        }

        $primera = $this->repo->searchFiltered(perPage: 5, page: 1);
        $tercera = $this->repo->searchFiltered(perPage: 5, page: 3);

        self::assertSame(12, $primera->total());
        self::assertSame(5, $primera->perPage());
        self::assertCount(5, $primera->items());
        self::assertCount(2, $tercera->items());
        self::assertSame('Producto 1', $primera->items()[0]->nombre);
        self::assertSame(3, $tercera->currentPage());
    }

    public function testDestacadosYConteoPorCategoria(): void
    {
        $this->seedProducts($this->db, [
            ['A caro', 'audio', 100.0, 1, 'si'],
            ['B barato', 'audio', 10.0, 1, 'si'],
            ['C normal', 'otros', 50.0, 1, 'no'],
        ]);

        $featured = $this->repo->featured(5);
        self::assertCount(2, $featured);
        self::assertSame('A caro', $featured[0]->nombre, 'ordenado por precio descendente');

        $counts = $this->repo->categoriesWithCounts();
        self::assertSame(['audio' => 2, 'otros' => 1], $counts);
    }

    public function testAllLimitado(): void
    {
        $this->seedProducts($this->db, [['Z', 'otros', 1.0, 1, 'no'], ['A', 'otros', 2.0, 1, 'no']]);

        $all = $this->repo->all();
        self::assertSame(['A', 'Z'], [$all[0]->nombre, $all[1]->nombre], 'orden alfabético');

        self::assertCount(1, $this->repo->all(1));
    }

    public function testSerializacionJson(): void
    {
        $product = $this->repo->create(['nombre' => 'Cámara Web', 'precio' => 123.45, 'stock' => 2, 'categoria' => 'perifericos']);
        $array = $product->toArray();

        self::assertSame('Cámara Web', $array['nombre']);
        self::assertSame(123.45, $array['precio']);
        self::assertIsBool($array['destacado']);
        self::assertArrayHasKey('creadoEn', $array);

        $json = json_encode($product, JSON_UNESCAPED_UNICODE);

        self::assertStringContainsString('"nombre":"Cámara Web"', (string) $json);
        self::assertTrue($product->has('nombre'));
        self::assertSame('Cámara Web', $product->get('nombre'));
        self::assertNull($product->get('inexistente'));
    }

    public function testPersistableFiltraColumnesDesconocidas(): void
    {
        $product = $this->repo->create([
            'nombre' => 'Filtrado',
            'precio' => 1,
            'stock' => 1,
            'categoria' => 'otros',
            'tabla_secreta' => 'no debería llegar',
        ]);

        self::assertSame('Filtrado', $product->nombre);
        self::assertSame('', $product->descripcion);
    }
}
