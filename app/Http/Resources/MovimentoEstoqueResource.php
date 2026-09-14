<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimentoEstoqueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tipoLabels = [
            'entrada' => 'Entrada',
            'baixa_manual' => 'Baixa Manual',
            'venda' => 'Venda',
            'transferencia_saida' => 'Transferência (Saída)',
            'transferencia_entrada' => 'Transferência (Entrada)',
            'estorno' => 'Estorno',
        ];

        $tipoCores = [
            'entrada' => 'success',
            'baixa_manual' => 'danger',
            'venda' => 'warning',
            'transferencia_saida' => 'info',
            'transferencia_entrada' => 'info',
            'estorno' => 'secondary',
        ];

        return [
            'id' => $this->id,
            'estoque_id' => $this->estoque_id,
            'estoque' => $this->whenLoaded('estoque', function () {
                return [
                    'id' => $this->estoque->id,
                    'nome' => $this->estoque->nome,
                ];
            }),
            'produto_id' => $this->produto_id,
            'produto' => $this->whenLoaded('produto', function () {
                return [
                    'id' => $this->produto->id,
                    'nome' => $this->produto->produto,
                ];
            }),
            'tipo' => $this->tipo,
            'tipo_label' => $tipoLabels[$this->tipo] ?? $this->tipo,
            'tipo_cor' => $tipoCores[$this->tipo] ?? 'secondary',
            'quantidade' => (int) $this->quantidade,
            'custo_unitario' => (float) $this->custo_unitario,
            'custo_total' => round((float) $this->custo_total, 2),
            'origem_tipo' => $this->origem_tipo,
            'origem_id' => $this->origem_id,
            'usuario_id' => $this->usuario_id,
            'usuario' => $this->whenLoaded('usuario', function () {
                return [
                    'id' => $this->usuario->id,
                    'name' => $this->usuario->name,
                ];
            }),
            'observacao' => $this->observacao,
            'movimentado_em' => $this->movimentado_em?->format('Y-m-d H:i:s'),
            'movimentado_em_breve' => $this->movimentado_em?->diffForHumans(),
            'estornado_em' => $this->estornado_em?->format('Y-m-d H:i:s'),
            'esta_estornado' => $this->estornado_em !== null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
