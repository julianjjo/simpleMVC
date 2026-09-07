<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use SimpleMvc\Core\Validator;

final class ValidatorTest extends TestCase
{
    private function validator(array $data, array $rules, array $labels = []): Validator
    {
        return Validator::make($data, $rules, [], $labels);
    }

    public function testPasaConDatosCorrectos(): void
    {
        $validator = $this->validator(
            ['nombre' => 'Teclado K80', 'precio' => '1899.50', 'stock' => '3', 'email' => 'tienda@ejemplo.co'],
            ['nombre' => 'required|string|min:3|max:120', 'precio' => 'required|numeric|min:0', 'stock' => 'required|integer|min:0', 'email' => 'required|email']
        );

        self::assertTrue($validator->passes());
        self::assertSame([], $validator->errors());
        self::assertSame('Teclado K80', $validator->validated()['nombre']);
    }

    public function testObligatorioYVacios(): void
    {
        $validator = $this->validator(['nombre' => '   ', 'otro' => ''], ['nombre' => 'required', 'otro' => 'required']);

        self::assertTrue($validator->fails());
        self::assertSame('El campo Nombre es obligatorio.', $validator->firstError('nombre'), 'la etiqueta por defecto se capitaliza');
        self::assertSame('El campo Otro es obligatorio.', $validator->firstError('otro'));
    }

    public function testNullableSeSaltaLasReglas(): void
    {
        $validator = $this->validator(['slug' => ''], ['slug' => 'nullable|slug|min:5']);

        self::assertTrue($validator->passes());
        self::assertArrayNotHasKey('slug', $validator->validated());
    }

    public function testReglasNumericasUsanElValor(): void
    {
        $validator = $this->validator(['precio' => '5'], ['precio' => 'numeric|min:10|max:100']);
        self::assertTrue($validator->fails());
        self::assertStringContainsString('mayor o igual que 10', (string) $validator->firstError('precio'));

        $ok = $this->validator(['precio' => '15.5'], ['precio' => 'numeric|min:10|max:100']);
        self::assertTrue($ok->passes());
    }

    public function testLongitudesDeTexto(): void
    {
        $validator = $this->validator(['nombre' => 'ab'], ['nombre' => 'string|min:3']);
        self::assertTrue($validator->fails());

        // Contar con mb_strlen: 5 acentos cuentan como 5, no como 10 bytes.
        $acentos = $this->validator(['nombre' => 'áéíóú'], ['nombre' => 'string|max:5']);
        self::assertTrue($acentos->passes());

        $largo = $this->validator(['nombre' => 'áéíóúñññ'], ['nombre' => 'string|max:5']);
        self::assertTrue($largo->fails());
    }

    public function testEnteroRechazaDecimales(): void
    {
        self::assertTrue($this->validator(['x' => '3.5'], ['x' => 'integer'])->fails());
        self::assertTrue($this->validator(['x' => '-3'], ['x' => 'integer'])->passes());
        self::assertTrue($this->validator(['x' => '3'], ['x' => 'numeric'])->passes());
    }

    public function testInYConfirmed(): void
    {
        $validator = $this->validator(['categoria' => 'no-existe'], ['categoria' => 'in:audio,video']);
        self::assertTrue($validator->fails());
        self::assertStringContainsString('audio, video', (string) $validator->firstError('categoria'));

        $confirmed = $this->validator(['pass' => 'abc', 'pass_confirmation' => 'abd'], ['pass' => 'confirmed']);
        self::assertTrue($confirmed->fails());
    }

    public function testReglasComoArreglo(): void
    {
        $validator = $this->validator(['n' => '12'], ['n' => ['required', 'numeric', 'max:10']]);

        self::assertTrue($validator->fails());
    }

    public function testEtiquetasPersonalizadas(): void
    {
        $validator = $this->validator(['slug' => 'MAL SLUG'], ['slug' => 'slug'], ['slug' => 'URL amigable']);

        self::assertTrue($validator->fails());
        self::assertStringContainsString('URL amigable', (string) $validator->firstError('slug'));
    }

    public function testMensajesPersonalizados(): void
    {
        $validator = Validator::make(
            ['precio' => 'nada'],
            ['precio' => 'numeric'],
            ['precio.numeric' => 'El precio debe ser un número, no «:attribute».']
        );

        self::assertSame('El precio debe ser un número, no «Precio».', $validator->firstError('precio'));
    }

    public function testTrimAutomatico(): void
    {
        $validator = $this->validator(['nombre' => "  Teclado \n"], ['nombre' => 'required']);

        self::assertSame('Teclado', $validator->validated()['nombre']);
    }

    public function testSlugValido(): void
    {
        self::assertTrue($this->validator(['s' => 'teclado-mecanico-k80'], ['s' => 'slug'])->passes());
        self::assertTrue($this->validator(['s' => 'Teclado Mecánico'], ['s' => 'slug'])->fails());
    }

    public function testFirstErrorsAgrupa(): void
    {
        $validator = $this->validator(['a' => '', 'b' => 'x'], ['a' => 'required', 'b' => 'min:5', 'c' => 'required']);
        $errors = $validator->firstErrors();

        self::assertSame(['a', 'b', 'c'], array_keys($errors));
        self::assertCount(1, $validator->errors()['b']);
        self::assertTrue($validator->hasError('c'));
    }

    public function testAddErrorManual(): void
    {
        $validator = $this->validator(['x' => '1'], ['x' => 'numeric']);
        $validator->addError('x', 'Ya existe otro producto con ese id.');

        self::assertTrue($validator->fails());
        self::assertSame('Ya existe otro producto con ese id.', $validator->firstError('x'));
    }

    public function testArrayYUrl(): void
    {
        self::assertTrue($this->validator(['u' => 'https://ejemplo.com/a'], ['u' => 'url'])->passes());
        self::assertTrue($this->validator(['u' => 'no-es-url'], ['u' => 'url'])->fails());
        self::assertTrue($this->validator(['a' => ['x']], ['a' => 'array'])->passes());
    }

    public function testDate(): void
    {
        self::assertTrue($this->validator(['d' => '2026-09-03'], ['d' => 'date'])->passes());
        self::assertTrue($this->validator(['d' => 'no-es-una-fecha'], ['d' => 'date'])->fails());
    }

    public function testBetweenYSize(): void
    {
        self::assertTrue($this->validator(['n' => 'abcde'], ['n' => 'between:3,10'])->passes());
        self::assertTrue($this->validator(['n' => 'a'], ['n' => 'between:3,10'])->fails());
        self::assertTrue($this->validator(['n' => 'abc'], ['n' => 'size:3'])->passes());
    }

    public function testDataDevuelveLoOriginal(): void
    {
        $data = ['nombre' => ' x '];

        self::assertSame($data, $this->validator($data, ['nombre' => 'required'])->data());
    }
}
