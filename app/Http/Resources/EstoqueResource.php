<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstoqueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'codigo' => $this->codigo,
            'cidade' => $this->cidade,
            'ativo' => $this->ativo,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'saldos_count' => $this->saldos_count ?? $this->saldos()->count(),
            'saldos' => EstoqueSaldoResource::collection($this->whenLoaded('saldos')),
            'movimentos' => MovimentoEstoqueResource::collection($this->whenLoaded('movimentos')),
        ];
    }
}
