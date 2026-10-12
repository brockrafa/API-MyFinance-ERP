<?php

namespace App\Http\Controllers;

use App\Services\DashboardFinanceiroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Dados agregados do dashboard gerencial.
     * Cada bloco só é devolvido se o usuário tiver a permissão de visualização correspondente.
     */
    public function financeiro(Request $request, DashboardFinanceiroService $service): JsonResponse
    {
        $dados = $request->validate([
            'meses' => 'sometimes|integer|min:3|max:24',
        ]);

        $user = $request->user();

        $payload = $service->gerar(
            (int) ($dados['meses'] ?? 12),
            $user->can('financeiro.lancamentos.view'),
            $user->can('financeiro.contas-pagar.view'),
            $user->can('vendas.view'),
        );

        return response()->json($payload);
    }
}
