<?php

namespace App\Http\Controllers;

use App\Http\Resources\EstoqueResource;
use App\Http\Resources\EstoqueSaldoResource;
use App\Http\Resources\MovimentoEstoqueResource;
use App\Models\Estoque;
use App\Models\MovimentoEstoque;
use App\Services\EstoqueService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EstoqueController extends Controller
{
    public function __construct(private EstoqueService $estoqueService)
    {
    }

    public function index()
    {
        return EstoqueResource::collection(
            Estoque::query()->where('ativo', true)->with('saldos.produto')->orderBy('nome')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'codigo' => ['required', 'string', 'max:50'],
            'cidade' => ['nullable', 'string', 'max:255'],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        $estoque = Estoque::create($data);

        return new EstoqueResource($estoque->load('saldos.produto'));
    }

    public function show(Estoque $estoque)
    {
        return new EstoqueResource(
            $estoque->load(['saldos.produto', 'movimentos.produto'])
        );
    }

    public function movimentos(Estoque $estoque)
    {
        $movimentos = MovimentoEstoque::query()
            ->where('estoque_id', $estoque->id)
            ->with(['produto', 'usuario'])
            ->latest('movimentado_em')
            ->latest('id')
            ->limit(100)
            ->get();

        return MovimentoEstoqueResource::collection($movimentos);
    }

    /**
     * Obter dados completos do dashboard de estoques.
     * Inclui saldos, últimas movimentações e estatísticas.
     */
    public function dashboard(Estoque $estoque, Request $request)
    {
        $limitMovimentos = $request->integer('limit_movimentos', 20);

        $dadosDashboard = $this->estoqueService->obterDadosDashboard($estoque, $limitMovimentos);

        return response()->json([
            'success' => true,
            'data' => [
                'estoque' => $dadosDashboard['estoque'],
                'resumo' => $dadosDashboard['resumo'],
                'saldos' => EstoqueSaldoResource::collection($dadosDashboard['saldos']),
                'ultimas_movimentacoes' => MovimentoEstoqueResource::collection(
                    $dadosDashboard['ultimas_movimentacoes']
                ),
            ],
        ]);
    }

    /**
     * Validar saldos em tempo real para múltiplos itens.
     * Útil para o formulário antes de submeter.
     */
    public function validarSaldos(Estoque $estoque, Request $request)
    {
        $data = $request->validate([
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.produto_id' => ['required', 'integer', 'exists:produtos,id'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
        ]);

        $validacoes = $this->estoqueService->validarSaldosRealTime($estoque, $data['itens']);

        return response()->json([
            'success' => true,
            'data' => $validacoes,
        ]);
    }

    public function destroyMovimento(MovimentoEstoque $movimento)
    {
        $movimento->delete();

        return response()->json(['success' => true], Response::HTTP_NO_CONTENT);
    }

    public function update(Request $request, Estoque $estoque)
    {
        $data = $request->validate([
            'nome' => ['sometimes', 'string', 'max:255'],
            'codigo' => ['sometimes', 'string', 'max:50'],
            'cidade' => ['nullable', 'string', 'max:255'],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        $estoque->update($data);

        return new EstoqueResource($estoque->fresh()->load('saldos.produto'));
    }

    public function destroy(Estoque $estoque)
    {
        if ($estoque->saldos()->where('quantidade', '>', 0)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível excluir um estoque com saldo disponível.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $estoque->update(['ativo' => false]);

        return response()->json(['success' => true], Response::HTTP_NO_CONTENT);
    }
}