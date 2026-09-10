<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstoqueSaldoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quantidade = (int) $this->quantidade;
        $custoMedio = (float) $this->custo_medio;
        $valorTotal = $quantidade * $custoMedio;

        return [
            'id' => $this->id,
            'estoque_id' => $this->estoque_id,
            'estoque' => $this->whenLoaded('estoque', function () {
                return [
                    'id' => $this->estoque->id,
                    'nome' => $this->estoque->nome,
                    'codigo' => $this->estoque->codigo,
                ];
            }),
            'produto_id' => $this->produto_id,
            'produto' => $this->whenLoaded('produto', function () {
                return [
                    'id' => $this->produto->id,
                    'nome' => $this->produto->produto,
                    'codigo' => $this->produto->codigo ?? null,
                ];
            }),
            'quantidade' => $quantidade,
            'custo_medio' => $custoMedio,
            'valor_total' => round($valorTotal, 2),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
