<?php

namespace App\Http\Controllers;

use App\Models\LancamentoFinanceiro;
use App\Models\ItemVenda;
use App\Models\DespesaRecorrente;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LancamentoFinanceiroPagarController extends Controller
{
    public function index(Request $request)
    {
        $mes = $request->query('mes', now()->format('Y-m'));
    
        $lancamentos = LancamentoFinanceiro::with([
            'cliente:id,nome',
            'venda:id,data_venda,tipo_venda,total,parcelas,valor_parcela,entrada,forma_pagamento_id',
        ])
        ->whereRaw("DATE_FORMAT(data_vencimento, '%Y-%m') = ? and tipo = 'saida'", [$mes])
        ->orderBy('data_vencimento')
        ->get();


        return response()->json($lancamentos);
    }

    /**
     * Cadastra uma nova conta a pagar avulsa.
     * Se 'parcelas' > 1, cria uma conta para cada mês a partir do vencimento.
     * Se 'ja_pago' vier true, cria a(s) conta(s) já com status pago e forma_pagamento.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'descricao'       => 'required|string|max:255',
            'fornecedor'      => 'nullable|string|max:255',
            'categoria_id'    => 'nullable|exists:categorias,id',
            'valor'           => 'required|numeric|min:0.01',
            'data_vencimento' => 'required|date_format:Y-m-d',
            'parcelas'        => 'nullable|integer|min:1',
            'observacao'      => 'nullable|string',
            'ja_pago'         => 'nullable|boolean',
            'data_pagamento'  => 'required_if:ja_pago,true|nullable|date_format:Y-m-d',
            'forma_pagamento' => 'required_if:ja_pago,true|nullable|in:pix,dinheiro,transferencia,boleto,cartao_debito,cartao_credito',
        ]);

        $jaPago  = (bool) ($data['ja_pago'] ?? false);
        $parcelas = (int) ($data['parcelas'] ?? 1);
        $base    = Carbon::parse($data['data_vencimento']);

        $criadas = [];

        DB::transaction(function () use ($data, $jaPago, $parcelas, $base, &$criadas) {
            for ($i = 0; $i < $parcelas; $i++) {
                $criadas[] = LancamentoFinanceiro::create([
                    'tipo'            => 'saida',
                    'descricao'       => $data['descricao'],
                    'fornecedor'      => $data['fornecedor'] ?? null,
                    'categoria_id'    => $data['categoria_id'] ?? null,
                    'valor'           => $data['valor'],
                    'data_vencimento' => $base->copy()->addMonths($i)->format('Y-m-d'),
                    'numero_parcela'  => $i + 1,
                    'total_parcelas'  => $parcelas,
                    'observacao'      => $data['observacao'] ?? null,
                    'status'          => $jaPago ? 'pago' : 'pendente',
                    'data_pagamento'  => $jaPago ? $data['data_pagamento'] : null,
                    'forma_pagamento' => $jaPago ? $data['forma_pagamento'] : null,
                ]);
            }
        });

        return response()->json(['message' => 'Conta(s) cadastrada(s) com sucesso.', 'contas' => $criadas], Response::HTTP_CREATED);
    }

    public function lancarRecorrentes(Request $request)
    {   
        $request->validate([
            'mes' => 'required|date_format:Y-m',
            ]);
            
        
        $mes = $request->mes;
        [$ano, $numMes] = explode('-', $mes);

        // IDs de recorrentes já lançadas neste mês
        $jaLancados = LancamentoFinanceiro::where('tipo', '=', 'saida')
            ->whereRaw("DATE_FORMAT(data_vencimento, '%Y-%m') = ?", [$mes])
            ->pluck('conta_pagar_id')
            ->toArray();

        $recorrentes = DespesaRecorrente::where('ativa', true)
            ->whereNotIn('id', $jaLancados)
            ->get();

        $lancadas = 0;
        
        DB::transaction(function () use ($recorrentes, $ano, $numMes, &$lancadas) {
            
            foreach ($recorrentes as $rec) {
                // Calcula o dia de vencimento respeitando o último dia do mês
                $dia   = min((int) $rec->dia_vencimento, Carbon::create($ano, $numMes, 1)->daysInMonth);
                $venc  = Carbon::create($ano, $numMes, $dia)->format('Y-m-d');
                LancamentoFinanceiro::create([
                    'tipo'           => 'saida',
                    'descricao'      => $rec->descricao,
                    'fornecedor'     => $rec->fornecedor,
                    'numero_parcela' => 1,
                    'total_parcelas' => 1,
                    'valor'          => $rec->valor,
                    'data_vencimento' => $venc,
                    'status'         => 'pendente',
                    'conta_pagar_id' => $rec->id,
                    'categoria_id' => $rec->categoria_id
                ]);
                $lancadas++;
            }
        });

        return response()->json(['lancadas' => $lancadas], Response::HTTP_OK);
    }

    public function pagar(Request $request, $id)
    {
        $request->validate([
            'valor_pago'      => 'required|numeric|min:0.01',
            'data_pagamento'  => 'required|date_format:Y-m-d',
            'forma_pagamento' => 'required|in:pix,dinheiro,transferencia,boleto,cartao_debito,cartao_credito',
            'observacao'      => 'nullable|string',
        ]);

        $conta = LancamentoFinanceiro::find($id);

        if (!$conta) {
            return response()->json(['errors' => "Lançamento com  com id {$id} não existe."], Response::HTTP_NOT_FOUND);
        }

        if ($conta->status === 'pago') {
            return response()->json(['errors' => 'Esta conta já foi paga.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($conta, $request) {
            $conta->valor = $request->valor_pago;
            $conta->data_pagamento = $request->data_pagamento;
            $conta->status = 'pago';
            $conta->forma_pagamento = $request->forma_pagamento;
            $conta->observacao = $request->observacao;
        });
            
        $conta->update();
        return response()->json($conta, Response::HTTP_OK);
    }

    public function update(Request $request, $id)
    {
        $conta = LancamentoFinanceiro::find($id);

        if (!$conta) {
            return response()->json(['errors' => "Conta com id {$id} não existe."], Response::HTTP_NOT_FOUND);
        }

        if ($conta->status === 'pago') {
            return response()->json(['errors' => 'Não é possível editar uma conta já paga.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validate([
            'descricao'       => 'sometimes|required|string|max:255',
            'fornecedor'      => 'nullable|string|max:255',
            'categoria_id'    => 'nullable|exists:categorias,id',
            'valor'           => 'sometimes|required|numeric|min:0.01',
            'data_vencimento' => 'sometimes|required|date_format:Y-m-d',
            'observacao'      => 'nullable|string',
        ]);

        $conta->update($data);

        return response()->json($conta, Response::HTTP_OK);
    }

    public function destroy($id)
    {
        $conta = LancamentoFinanceiro::find($id);

        if (!$conta) {
            return response()->json(['errors' => "Conta com id {$id} não existe."], Response::HTTP_NOT_FOUND);
        }

        // if ($conta->status === 'pago') {
        //     return response()->json(['errors' => 'Não é possível excluir uma conta já paga.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        // }

        $conta->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    

}