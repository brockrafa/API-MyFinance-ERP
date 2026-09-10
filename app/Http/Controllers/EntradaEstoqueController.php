<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMultiItemEntradaRequest;
use App\Models\Estoque;
use App\Services\EstoqueService;
use Illuminate\Http\Response;

class EntradaEstoqueController extends Controller
{
    public function store(StoreMultiItemEntradaRequest $request, EstoqueService $service)
    {
        $estoque = Estoque::findOrFail($request->integer('estoque_id'));
        $data = $request->validated();

        $entrada = $service->registrarEntrada($estoque, $data);

        return response()->json([
            'success' => true,
            'message' => 'Entrada registrada com sucesso',
            'data' => [
                'id' => $entrada->id,
                'tipo' => $entrada->tipo,
                'data_entrada' => $entrada->data_entrada,
                'quantidade_itens' => $entrada->itens->count(),
                'frete_total' => $entrada->frete_total,
            ],
        ], Response::HTTP_CREATED);
    }
}