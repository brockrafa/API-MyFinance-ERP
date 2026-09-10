<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMultiItemTransferenciaRequest;
use App\Models\Estoque;
use App\Services\EstoqueService;
use Illuminate\Http\Response;

class TransferenciaEstoqueController extends Controller
{
    public function store(StoreMultiItemTransferenciaRequest $request, EstoqueService $service)
    {
        $data = $request->validated();

        $origem = Estoque::findOrFail($data['estoque_origem_id']);
        $destino = Estoque::findOrFail($data['estoque_destino_id']);

        $transferencia = $service->transferir($origem, $destino, $data);

        return response()->json([
            'success' => true,
            'message' => 'Transferência registrada com sucesso',
            'data' => [
                'id' => $transferencia->id,
                'estoque_origem' => $origem->nome,
                'estoque_destino' => $destino->nome,
                'quantidade_itens' => $transferencia->itens->count(),
                'frete_total' => $transferencia->frete_total,
            ],
        ], Response::HTTP_CREATED);
    }
}