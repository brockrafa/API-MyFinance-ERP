<?php

namespace App\Http\Controllers;

use App\Models\Venda;
use App\Services\RelatorioMargemService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class RelatorioController extends Controller
{
    /** Margem real por venda, produto e canal no período (padrão: mês atual até hoje). */
    public function margem(Request $request, RelatorioMargemService $service)
    {
        $dados = $request->validate([
            'inicio' => ['nullable', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
            'canal' => ['nullable', Rule::in(Venda::CANAIS)],
        ]);

        $inicio = isset($dados['inicio']) ? Carbon::parse($dados['inicio']) : now()->startOfMonth();
        $fim = isset($dados['fim']) ? Carbon::parse($dados['fim']) : now();

        return response()->json($service->gerar($inicio, $fim, $dados['canal'] ?? null), Response::HTTP_OK);
    }
}
