<?php

namespace App\Http\Controllers;

use App\Models\Venda;
use App\Models\Cliente;
use App\Models\Produto;
use App\Models\ItemVenda;
use App\Models\Servico;
use App\Models\LancamentoFinanceiro;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class VendaController extends Controller
{
    public function index()
    {
        $vendas = Venda::with([
            'cliente:id,nome',
            'formaPagamento:id,descricao',
            'produtos',
            'lancamentos:id,venda_id,valor,valor_pago,status',
        ])->select('id', 'cliente_id', 'data_venda', 'total', 'forma_pagamento_id', 'tipo_venda')
          ->get();

        $vendas->each(function ($venda) {
            $venda->valor_pago = $venda->lancamentos->sum(function ($lancamento) {
                return $lancamento->status === 'pago' ? $lancamento->valor_pago : 0;
            });
            $venda->valor_pendente = max($venda->total - $venda->valor_pago, 0);
            $venda->unsetRelation('lancamentos');
        });

        return response()->json($vendas, Response::HTTP_OK);
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $this->validarCliente($request->cliente_id);

            $venda = $this->criarVenda($request);
            $this->salvarItens($request->produtos, $venda);
            $this->aplicarCondicaoPagamento($request, $venda);
            $venda->save();

            $this->gerarLancamentos($venda);

            DB::commit();
            return response()->json($venda, Response::HTTP_CREATED);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'errors' => 'Ocorreu um erro ao criar a venda: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show($id)
    {
        $venda = Venda::with([
            'cliente:id,nome',
            'formaPagamento:id,descricao',
            'itens.servico',
            'itens.produto',
            'lancamentos',
        ])->find($id);

        if (!$venda) {
            return response()->json([
                'errors' => "Venda com id {$id} não existe."
            ], Response::HTTP_NOT_FOUND);
        }

        $venda->valor_pago = $venda->lancamentos->sum(function ($lancamento) {
            return $lancamento->status === 'pago' ? $lancamento->valor_pago : 0;
        });
        $venda->valor_pendente = max($venda->total - $venda->valor_pago, 0);

        return response()->json($venda, Response::HTTP_OK);
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $venda = Venda::find($id);

            if (!$venda) {
                return response()->json([
                    'errors' => "Venda com id {$id} não existe."
                ], Response::HTTP_NOT_FOUND);
            }

            $venda->total = 0;
            $venda->forma_pagamento_id = $request->forma_pagamento['id'];
            $venda->data_venda = $request->filled('data_venda') ? $request->data_venda : $venda->data_venda;
            $venda->tipo_venda = $request->condicao_pagamento['tipo'];

            // Remove itens e lançamentos antigos para recriar
            ItemVenda::where('venda_id', $id)->delete();
            LancamentoFinanceiro::where('venda_id', $id)->delete();

            $this->salvarItens($request->produtos, $venda);
            $this->aplicarCondicaoPagamento($request, $venda);
            $venda->save();

            $this->gerarLancamentos($venda);

            DB::commit();
            return response()->json([], Response::HTTP_NO_CONTENT);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'errors' => 'Ocorreu um erro ao atualizar a venda: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy($id)
    {
        $venda = Venda::find($id);

        if (!$venda) {
            return response()->json([
                'errors' => "Venda com id {$id} não existe."
            ], Response::HTTP_NOT_FOUND);
        }

        $venda->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Métodos privados
    // -------------------------------------------------------------------------

    private function validarCliente(int $clienteId): void
    {
        if (!Cliente::where('id', $clienteId)->exists()) {
            abort(Response::HTTP_NOT_FOUND, "Cliente com id {$clienteId} não existe.");
        }
    }

    private function criarVenda(Request $request): Venda
    {
        $venda = new Venda();
        $venda->cliente_id        = $request->cliente_id;
        $venda->total             = 0;
        $venda->forma_pagamento_id = $request->forma_pagamento['id'];
        $venda->data_venda        = $request->filled('data_venda') ? $request->data_venda : now()->toDateString();
        $venda->tipo_venda        = $request->condicao_pagamento['tipo'];
        $venda->save();

        return $venda;
    }

    private function salvarItens(array $produtos, Venda $venda): void
    {
        foreach ($produtos as $produto) {
            $model = $produto['tipo'] === 'servico' ? Servico::find($produto['id']) : Produto::find($produto['id']);

            if (!$model) {
                abort(Response::HTTP_NOT_FOUND, "Item com id {$produto['id']} não existe.");
            }

            $item = new ItemVenda();
            $item->venda_id      = $venda->id;
            $item->tipo          = $produto['tipo'];
            $item->quantidade    = $produto['quantidade'];
            $item->valor_unitario = $produto['valor'];

            if ($produto['tipo'] === 'servico') {
                $item->servico_id = $produto['id'];
            } else {
                $item->produto_id = $produto['id'];
            }

            $item->save();
            $venda->total += $produto['quantidade'] * $produto['valor'];
        }
    }

    private function aplicarCondicaoPagamento(Request $request, Venda $venda): void
    {
        if ($venda->tipo_venda === 'prazo') {
            $condicao = $request->condicao_pagamento;

            $venda->parcelas            = $condicao['parcelas'];
            $venda->entrada             = $condicao['entrada'];
            $venda->valor_parcela       = ($venda->total - $condicao['entrada']) / $condicao['parcelas'];
            $venda->primeiro_vencimento = $condicao['primeiro_vencimento'];
        }
    }

    private function gerarLancamentos(Venda $venda): void
    {
        if ($venda->tipo_venda === 'avista') {
            // À vista: 1 parcela já paga
            LancamentoFinanceiro::create([
                'venda_id'       => $venda->id,
                'cliente_id'     => $venda->cliente_id,
                'numero_parcela' => 1,
                'total_parcelas' => 1,
                'valor'          => $venda->total,
                'valor_pago'     => $venda->total,
                'data_vencimento' => $venda->data_venda,
                'data_pagamento' => $venda->data_venda,
                'status'         => 'pago',
            ]);

            return;
        }

        // À prazo: gera entrada (se houver) + parcelas mensais
        $primeiroVencimento = Carbon::parse($venda->primeiro_vencimento);

        if ($venda->entrada > 0) {
            LancamentoFinanceiro::create([
                'venda_id'        => $venda->id,
                'cliente_id'      => $venda->cliente_id,
                'numero_parcela'  => 0, // 0 = entrada
                'total_parcelas'  => $venda->parcelas,
                'valor'           => $venda->entrada,
                'valor_pago'      => $venda->entrada,
                'data_vencimento' => $venda->data_venda,
                'data_pagamento'  => $venda->data_venda,
                'status'          => 'pago',
            ]);
        }

        for ($i = 1; $i <= $venda->parcelas; $i++) {
            LancamentoFinanceiro::create([
                'venda_id'        => $venda->id,
                'cliente_id'      => $venda->cliente_id,
                'numero_parcela'  => $i,
                'total_parcelas'  => $venda->parcelas,
                'valor'           => $venda->valor_parcela,
                'data_vencimento' => $primeiroVencimento->copy()->addMonths($i - 1),
                'data_pagamento'  => null,
                'status'          => 'pendente',
            ]);
        }
    }
}
