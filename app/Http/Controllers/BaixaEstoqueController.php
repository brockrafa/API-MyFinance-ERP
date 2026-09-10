<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMultiItemBaixaRequest;
use App\Models\Estoque;
use App\Services\EstoqueService;
use Illuminate\Http\Response;

class BaixaEstoqueController extends Controller
{
    public function store(StoreMultiItemBaixaRequest $request, EstoqueService $service)
    {
        $estoque = Estoque::findOrFail($request->integer('estoque_id'));
        $data = $request->validated();

        $service->registrarSaida($estoque, $data);

        return response()->json([
            'success' => true,
            'message' => 'Baixa registrada com sucesso',
            'data' => [
                'quantidade_itens' => count($data['itens']),
                'motivos' => collect($data['itens'])->pluck('motivo')->unique()->values(),
            ],
        ], Response::HTTP_CREATED);
    }
}