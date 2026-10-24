<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListaPaginavel;
use App\Models\Agenda;
use App\Models\Venda;
use App\Models\Cliente;
use App\Models\LancamentoFinanceiro;
use App\Services\VendaService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VendaController extends Controller
{
    use ListaPaginavel;

    public function __construct(private VendaService $vendaService)
    {
    }

    public function index(Request $request)
    {
        LancamentoFinanceiro::marcarAtrasados();

        $consulta = Venda::with([
            'cliente:id,nome',
            'formaPagamento:id,descricao',
            'produtos',
            'lancamentos:id,venda_id,valor,valor_pago,status',
        ])->select('id', 'cliente_id', 'agenda_id', 'data_venda', 'total', 'forma_pagamento_id', 'tipo_venda', 'canal', 'valor_liquido_recebido', 'custos_adicionais')
          ->when($request->filled('mes'), fn ($q) => $q->whereMonth('data_venda', (int) $request->mes))
          ->when($request->filled('ano'), fn ($q) => $q->whereYear('data_venda', (int) $request->ano))
          ->when($request->filled('canal'), fn ($q) => $q->where('canal', $request->canal))
          ->when($request->filled('forma_pagamento'), fn ($q) => $q->whereHas(
              'formaPagamento',
              fn ($f) => $f->where('descricao', $request->forma_pagamento)
          ))
          ->when($request->filled('busca'), function ($q) use ($request) {
              $termo = trim($request->busca);
              $q->where(function ($w) use ($termo) {
                  $w->where('id', $termo)
                    ->orWhereHas('cliente', fn ($c) => $c->where('nome', 'like', '%' . $termo . '%'));
              });
          });

        match ($request->query('ordenacao', 'data_desc')) {
            'data_asc' => $consulta->orderBy('data_venda')->orderBy('id'),
            'total_asc' => $consulta->orderBy('total')->orderBy('id'),
            'total_desc' => $consulta->orderByDesc('total')->orderByDesc('id'),
            default => $consulta->orderByDesc('data_venda')->orderByDesc('id'),
        };

        return $this->listar($request, $consulta, function ($venda) {
            $venda->valor_pago = $venda->lancamentos->sum(function ($lancamento) {
                return $lancamento->status === 'pago' ? $lancamento->valor_pago : 0;
            });
            $venda->valor_pendente = max($venda->receitaLiquida() - $venda->valor_pago, 0);
            $venda->taxa_canal = $venda->taxaCanal();
            $venda->unsetRelation('lancamentos');
        });
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'estoque_id' => ['required', 'integer', 'exists:estoques,id'],
                'produtos' => ['required', 'array', 'min:1'],
                'produtos.*.valor' => ['required', 'numeric', 'min:0'],
                'canal' => ['nullable', Rule::in(Venda::CANAIS)],
                'valor_liquido_recebido' => ['nullable', 'numeric', 'min:0', 'required_if:canal,mercado_livre,ecommerce'],
                'custos_adicionais' => ['nullable', 'numeric', 'min:0'],
            ], [
                'valor_liquido_recebido.required_if' => 'Informe o valor recebido após os descontos do canal.',
            ]);
            $this->validarCliente($request->cliente_id);

            $dadosVenda = [
                'cliente_id' => $request->cliente_id,
                'estoque_id' => $request->estoque_id,
                'forma_pagamento_id' => $request->forma_pagamento['id'],
                'data_venda' => $request->filled('data_venda') ? $request->data_venda : now()->toDateString(),
                'tipo_venda' => $request->condicao_pagamento['tipo'],
                'condicao_pagamento' => $request->condicao_pagamento,
                'canal' => $request->input('canal', 'venda_direta'),
                'valor_liquido_recebido' => $request->input('valor_liquido_recebido'),
                'custos_adicionais' => $request->input('custos_adicionais', 0),
            ];

            $venda = $this->vendaService->criar($dadosVenda, $request->produtos, $request->user()->empresa_id);

            DB::commit();
            return response()->json($venda, Response::HTTP_CREATED);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException | \Illuminate\Validation\ValidationException $e) {
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
        $venda->valor_pendente = max($venda->receitaLiquida() - $venda->valor_pago, 0);
        $venda->resumo_financeiro = $venda->resumoFinanceiro();

        return response()->json($venda, Response::HTTP_OK);
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $venda = Venda::find($id);

            if (!$venda) {
                DB::rollBack();
                return response()->json([
                    'errors' => "Venda com id {$id} não existe."
                ], Response::HTTP_NOT_FOUND);
            }

            $request->validate([
                'estoque_id' => ['nullable', 'integer', 'exists:estoques,id'],
                'produtos' => ['sometimes', 'array', 'min:1'],
                'produtos.*.valor' => ['required_with:produtos', 'numeric', 'min:0'],
                'canal' => ['nullable', Rule::in(Venda::CANAIS)],
                'valor_liquido_recebido' => ['nullable', 'numeric', 'min:0', 'required_if:canal,mercado_livre,ecommerce'],
                'custos_adicionais' => ['nullable', 'numeric', 'min:0'],
            ], [
                'valor_liquido_recebido.required_if' => 'Informe o valor recebido após os descontos do canal.',
            ]);

            $dadosVenda = [
                'estoque_id' => $request->estoque_id,
                'forma_pagamento_id' => $request->forma_pagamento['id'] ?? $venda->forma_pagamento_id,
                'data_venda' => $request->filled('data_venda') ? $request->data_venda : $venda->data_venda,
                'tipo_venda' => $request->condicao_pagamento['tipo'] ?? $venda->tipo_venda,
                'condicao_pagamento' => $request->condicao_pagamento,
                'canal' => $request->input('canal', $venda->canal),
                'valor_liquido_recebido' => $request->input('valor_liquido_recebido', $venda->valor_liquido_recebido),
                'custos_adicionais' => $request->input('custos_adicionais', $venda->custos_adicionais ?? 0),
            ];

            // Sem itens no corpo: só data e forma de pagamento (único caso permitido com parcelas já pagas).
            if ($request->has('produtos')) {
                $this->vendaService->atualizar($venda, $dadosVenda, $request->produtos);
            } else {
                $this->vendaService->atualizarDados($venda, $dadosVenda);
            }

            DB::commit();
            return response()->json([], Response::HTTP_NO_CONTENT);

        } catch (\Illuminate\Http\Exceptions\HttpResponseException | \Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
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

        DB::transaction(function () use ($venda) {
            $agenda = $venda->agenda_id ? Agenda::find($venda->agenda_id) : null;

            $this->vendaService->excluir($venda);

            if ($agenda) {
                $agenda->update(['venda_id' => null, 'status' => 'agendado']);
            }
        });

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function validarCliente(int $clienteId): void
    {
        if (!Cliente::where('id', $clienteId)->exists()) {
            abort(Response::HTTP_NOT_FOUND, "Cliente com id {$clienteId} não existe.");
        }
    }
}
