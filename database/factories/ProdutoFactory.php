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
        $valor = $this->faker->randomFloat(2, 10, 500);

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
            'valor' => $valor,
            'valor_venda' => round($valor * 1.35, 2),
            'categoria_id' => Categoria::factory(),
        ];
    }
}
