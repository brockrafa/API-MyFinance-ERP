<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProdutoFactory extends Factory
{
    protected $model = Produto::class;

    public function definition(): array
    {
        $precoVenda = $this->faker->randomFloat(2, 15, 700);

        return [
            'produto' => $this->faker->randomElement([
                'Notebook',
                'Mouse',
                'Teclado',
                'Monitor',
                'Cadeira',
                'Mesa',
                'Camera',
                'Impressora',
                'Smartphone',
                'Tablet',
            ]) . ' ' . $this->faker->randomNumber(2),
            'valor_venda' => $precoVenda,
            'categoria_id' => Categoria::inRandomOrder()->value('id') ?? Categoria::factory(),
        ];
    }
}
