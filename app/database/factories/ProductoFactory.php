<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array<int, string> $palabras */
        $palabras = $this->faker->unique()->words(3);
        $nombre = Str::title(implode(' ', $palabras));

        return [
            // Se engancha a una categoria existente en lugar de exigir una fabrica de
            // categorias: las pruebas casi siempre corren despues del CatalogoSeeder.
            'categoria_id' => fn (): int => Categoria::query()->inRandomOrder()->value('id')
                ?? Categoria::create([
                    'nombre' => 'Categoria de prueba',
                    'descripcion' => 'Categoria creada automaticamente para las pruebas.',
                ])->id,
            'nombre' => $nombre,
            'slug' => Str::slug($nombre).'-'.Str::lower(Str::random(5)),
            'descripcion' => $this->faker->paragraph(),
            // Rango tipico de la tienda en quetzales, con centavos comerciales.
            'precio' => $this->faker->randomFloat(2, 25, 4500),
            'existencias' => $this->faker->numberBetween(0, 80),
            'imagen_url' => null,
            'activo' => true,
        ];
    }

    public function agotado(): static
    {
        return $this->state(fn (array $atributos): array => [
            'existencias' => 0,
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $atributos): array => [
            'activo' => false,
        ]);
    }

    public function enCategoria(Categoria $categoria): static
    {
        return $this->state(fn (array $atributos): array => [
            'categoria_id' => $categoria->id,
        ]);
    }
}
