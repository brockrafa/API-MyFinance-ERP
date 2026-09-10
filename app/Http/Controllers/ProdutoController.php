<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Produto;
use App\Models\Estoque;
use App\Models\EstoqueSaldo;
use App\Models\ItemVenda;
use App\Models\MovimentoEstoque;
use App\Services\EstoqueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Response;

class ProdutoController extends Controller
{
    // Validação padrão para a criação e atualização de produtos
    private $validacaoPadrao = [
        'produto' => 'required|min:4',
        'valor' => 'required|numeric',
        'valor_venda' => 'required|numeric',
        'categoria' => 'required|integer'
    ];

    public function index()
    {
        return response()->json(Produto::all(), Response::HTTP_OK);
    }

    public function store(Request $request)
    {   
        $data = $request->validate(array_merge($this->validacaoPadrao, [
            'estoque_id' => ['nullable', 'integer', 'exists:estoques,id'],
            'quantidade_inicial' => ['nullable', 'integer', 'min:0'],
        ]), $this->messages);

        $produto = DB::transaction(function () use ($data): Produto {
            $produto = Produto::create([
                'produto' => $data['produto'],
                'valor' => $data['valor'],
                'valor_venda' => $data['valor_venda'],
                'categoria' => $data['categoria'],
            ]);

            $quantidade = (int) ($data['quantidade_inicial'] ?? 0);
            if ($quantidade > 0) {
                if (empty($data['estoque_id'])) {
                    abort(422, 'Selecione um estoque para a quantidade inicial.');
                }

                app(EstoqueService::class)->registrarEntrada(Estoque::findOrFail($data['estoque_id']), [
                    'tipo' => 'saldo_inicial',
                    'frete_total' => 0,
                    'itens' => [[
                        'produto_id' => $produto->id,
                        'quantidade' => $quantidade,
                        'custo_unitario' => $produto->valor,
                    ]],
                ]);
            }

            return $produto;
        });

        return response()->json($produto, Response::HTTP_CREATED);
    }

    public function show(string $id)
    {
        $produto = Produto::find($id);

        if (!$produto) {
            return response()->json([
                'message' => 'Produto não encontrado',
                'errors' => ['id' => 'Produto com ID:'. $id. ' não existe.']
            ], Response::HTTP_NOT_FOUND); 
        }

        return response()->json($produto, Response::HTTP_OK);  // Retorna o produto com status 200
    }

    public function update(Request $request, string $id)
    {
        $produto = Produto::find($id);
        if (!$produto) {
            return response()->json([
                'message' => 'Produto não encontrado',
                'errors' => ['id' => 'Produto com ID:'. $id. ' não existe.']
            ], Response::HTTP_NOT_FOUND);
        }
        $request->validate($this->validacaoPadrao,$this->messages);
        $produto->update($request->only([
            'produto',
            'valor',
            'valor_venda',
            'categoria',
        ]));
        return response()->noContent();
    }

    public function destroy(string $id)
    {
        $produto = Produto::find($id);

        if (!$produto) {
            return response()->json([
                'message' => 'Produto não encontrado',
                'errors' => ['id' => 'Produto com ID:'. $id. ' não existe.']
            ], Response::HTTP_NOT_FOUND);  
        }

        $possuiSaldo = EstoqueSaldo::query()
            ->where('produto_id', $produto->id)
            ->where('quantidade', '>', 0)
            ->exists();
        $possuiMovimentos = MovimentoEstoque::query()
            ->where('produto_id', $produto->id)
            ->exists();
        $possuiVendas = ItemVenda::query()
            ->where('produto_id', $produto->id)
            ->exists();

        if ($possuiSaldo || $possuiMovimentos || $possuiVendas) {
            return response()->json([
                'message' => 'Este produto não pode ser excluído porque possui saldo, movimentações ou vendas relacionadas.',
                'errors' => [
                    'produto' => 'Inative o produto ou faça os ajustes necessários antes de removê-lo.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $produto->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT); 
    }
}
