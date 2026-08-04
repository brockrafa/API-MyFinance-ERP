<?php

namespace App\Http\Controllers;

use App\Models\LancamentoFinanceiro;
use App\Models\ItemVenda;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LancamentoFinanceiroReceberController extends Controller
{
  public function index(Request $request)
    {
        $mes = $request->query('mes', now()->format('Y-m'));

        $lancamentos = LancamentoFinanceiro::with([
                'cliente:id,nome',
                'venda:id,data_venda,tipo_venda,total,parcelas,valor_parcela,entrada,forma_pagamento_id',
            ])
            ->whereRaw("DATE_FORMAT(data_vencimento, '%Y-%m') = ? and tipo = 'entrada'", [$mes])
            ->orderBy('data_vencimento')
            ->get();

        $vendaIds = $lancamentos->pluck('venda_id')->unique();

        $counts = ItemVenda::whereIn('venda_id', $vendaIds)
            ->select('venda_id', DB::raw('COUNT(*) as itens_count'))
            ->groupBy('venda_id')
            ->pluck('itens_count', 'venda_id');

            
        $itens = ItemVenda::whereIn('venda_id', $vendaIds)
            ->with(['produto:id,produto', 'servico:id,servico'])
            ->get()
            ->groupBy('venda_id');

        $lancamentos->each(function ($lancamento) use ($counts, $itens) {
            if ($lancamento->venda) {
                $lancamento->venda->itens_count = $counts[$lancamento->venda_id] ?? 0;

                $lancamento->venda->itens_descricao = optional($itens->get($lancamento->venda_id))
                    ->map(fn ($item) => $item->produto->produto ?? $item->servico->servico ?? 'Item')
                    ->implode(', ');
            }

            if ($lancamento->status === 'pendente' && $lancamento->data_vencimento->isPast()) {
                $lancamento->update(['status' => 'atrasado']);
                $lancamento->status = 'atrasado';
            }
        });

        return response()->json($lancamentos, Response::HTTP_OK);
    }
    public function show($id)
    {
        $lancamento = LancamentoFinanceiro::with([
            'cliente:id,nome',
            'venda:id,data_venda,tipo_venda,total,parcelas,valor_parcela,entrada,forma_pagamento_id',
        ])->find($id);

        if (!$lancamento) {
            return response()->json([
                'errors' => "Lançamento com id {$id} não existe."
            ], Response::HTTP_NOT_FOUND);
        }

        if ($lancamento->venda) {
            $lancamento->venda->itens_count = ItemVenda::where('venda_id', $lancamento->venda_id)->count();
        }

        return response()->json($lancamento, Response::HTTP_OK);
    }

    public function baixar(Request $request, $id)
    {
        $lancamento = LancamentoFinanceiro::find($id);

        if (!$lancamento) {
            return response()->json([
                'errors' => "Lançamento com id {$id} não existe."
            ], Response::HTTP_NOT_FOUND);
        }

        if ($lancamento->status === 'pago') {
            return response()->json([
                'errors' => 'Este lançamento já foi baixado.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lancamento->update([
            'status'         => 'pago',
            'valor_pago' => $lancamento->valor,
            'data_pagamento' => $request->filled('data_pagamento') ? $request->data_pagamento : now()->toDateString(),
        ]);

        return response()->json($lancamento, Response::HTTP_OK);
    }

    /**
     * Baixa uma parcela com valor customizado (maior, menor ou igual ao esperado).
     *
     * Body JSON:
     *   valor_pago        float   – valor efetivamente recebido
     *   estrategia        string  – "redistribuir" | "abater_ultima" | "criar_parcelas"
     *
     *   // Apenas quando estrategia = "criar_parcelas" e não há parcelas futuras:
     *   novas_parcelas    array   – [{ valor: float, data_vencimento: 'YYYY-MM-DD' }, ...]
     *
     * Regras:
     *  - valor_pago == valor → comportamento normal.
     *  - valor_pago != valor e há parcelas futuras → redistribuir ou abater_ultima.
     *  - valor_pago < valor e NÃO há parcelas futuras → criar_parcelas com novas_parcelas[].
     *  - valor_pago > valor e NÃO há parcelas futuras → sem ação extra (crédito/desconto).
     */
    public function baixarComValor(Request $request, $id)
    {
        $request->validate([
            'valor_pago'                       => 'required|numeric|min:0.01',
            'estrategia'                       => 'required|in:redistribuir,abater_ultima,criar_parcelas,sem_ajuste,manter_aberta',
            'novas_parcelas'                   => 'required_if:estrategia,criar_parcelas|array|min:1',
            'novas_parcelas.*.valor'           => 'required_if:estrategia,criar_parcelas|numeric|min:0.01',
            'novas_parcelas.*.data_vencimento' => 'required_if:estrategia,criar_parcelas|date_format:Y-m-d',
        ]);

        $lancamento = LancamentoFinanceiro::find($id);

        if (!$lancamento) {
            return response()->json([
                'errors' => "Lançamento com id {$id} não existe."
            ], Response::HTTP_NOT_FOUND);
        }

        if ($lancamento->status === 'pago') {
            return response()->json([
                'errors' => 'Este lançamento já foi baixado.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        // FIX:::: O VALOR_PAGO e o VALOR_ORIGINAL são o mesmo, logo a diferença esta sendo calculada de forma errada
        $valorPago     = (float) $request->valor_pago;
        $valorOriginal = (float) $lancamento->valor;
        $valorPagoAnterior = (float) $lancamento->valor_pago;
        $diferenca     = $valorPago - ($valorOriginal - $valorPagoAnterior);

       DB::beginTransaction();

        if($request->estrategia === 'manter_aberta') {
            //$valor_pago_parcial = $lancamento->valor_pago;
            $lancamento->update([
                'valor_pago' => $lancamento->valor_pago +  $request->valor_pago
            ]);
            DB::commit();
            return;
        }

        // 1. Baixa a parcela atual
        $lancamento->update([
            'status'         => 'pago',
            'valor'          => $valorPago + abs($lancamento->valor_pago),
            'valor_pago' => $valorPago + abs($lancamento->valor_pago),
            'data_pagamento' => $request->filled('data_pagamento') ? $request->data_pagamento : now()->toDateString(),
        ]);

        // 2. Sem diferença relevante → encerra
        if (abs($diferenca) < 0.01) {
            DB::commit();
            return;
        }

        // 3. Estratégia "sem_ajuste" → registra a baixa e ignora a diferença
        if ($request->estrategia === 'sem_ajuste') {
            DB::commit();
            return;
        }

        // 4. Estratégia "criar_parcelas" → cria novos lançamentos para cobrir o saldo
        if ($request->estrategia === 'criar_parcelas') {
            $this->criarParcelasExtras($lancamento, $request->novas_parcelas);
            DB::commit();
            return;
        }

        // 5. Redistribuir ou abater_ultima entre parcelas existentes
        $parcelasAbertas = LancamentoFinanceiro::where('venda_id', $lancamento->venda_id)
            ->whereIn('status', ['pendente', 'atrasado'])
            ->where('numero_parcela', '>', $lancamento->numero_parcela)
            ->orderBy('numero_parcela')
            ->get();

        if ($parcelasAbertas->isEmpty()) {
            return response()->json([
                'errors' => 'Não há parcelas futuras para ajustar a diferença. Use a estratégia "criar_parcelas" para gerar novas parcelas.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($request->estrategia === 'redistribuir') {
            $this->redistribuirDiferenca($parcelasAbertas, $diferenca);
        } else {
            $this->abaterNaUltimaParcela($parcelasAbertas, $diferenca);
        }

        $lancamento->refresh();
        DB::commit();
        return response()->json($lancamento, Response::HTTP_OK);
    }

    /**
     * Cria lançamentos extras para cobrir o saldo restante quando não há parcelas futuras.
     *
     * Cada item de $novasParcelas deve ter:
     *   valor           float   – valor da nova parcela
     *   data_vencimento string  – 'YYYY-MM-DD'
     *
     * O número da parcela continua a sequência existente.
     * O total_parcelas de todas as parcelas da venda é incrementado para refletir as novas.
     */
    private function criarParcelasExtras(LancamentoFinanceiro $lancamento, array $novasParcelas): void
    {
        // Descobre o maior numero_parcela atual da venda
        $maxNumeroParcela = LancamentoFinanceiro::where('venda_id', $lancamento->venda_id)
            ->max('numero_parcela');

        $totalAtual = (int) $lancamento->total_parcelas;
        $qtdNovas   = count($novasParcelas);
        $novoTotal  = $totalAtual + $qtdNovas;

        // Atualiza total_parcelas em todos os lançamentos da venda
        LancamentoFinanceiro::where('venda_id', $lancamento->venda_id)
            ->update(['total_parcelas' => $novoTotal]);

        // Cria cada nova parcela
        foreach ($novasParcelas as $index => $parcela) {
            LancamentoFinanceiro::create([
                'venda_id'        => $lancamento->venda_id,
                'cliente_id'      => $lancamento->cliente_id,
                'valor'           => round((float) $parcela['valor'], 2),
                'data_vencimento' => $parcela['data_vencimento'],
                'status'          => 'pendente',
                'numero_parcela'  => $maxNumeroParcela + $index + 1,
                'total_parcelas'  => $novoTotal,
                'data_pagamento'  => null,
            ]);
        }
    }

    /**
     * Distribui a diferença igualmente entre todas as parcelas abertas.
     * Centavos residuais vão para a última parcela.
     */
    private function redistribuirDiferenca($parcelas, float $diferenca)
    {
        $total    = $parcelas->count();
        $ajuste   = round(-$diferenca / $total, 2);
        $residual = round(-$diferenca - ($ajuste * $total), 2);

        foreach ($parcelas as $index => $parcela) {
            $novoValor = round((float) $parcela->valor + $ajuste, 2);

            if ($index === $total - 1) {
                $novoValor = round($novoValor + $residual, 2);
            }

            if ($novoValor < 0) {
                $novoValor = 0;
            }

            $parcela->update(['valor' => $novoValor]);

            if ($novoValor == 0) {
                $parcela->update([
                    'status'         => 'pago',
                    'data_pagamento' => now()->toDateString(),
                ]);
            }
        }
    }

    /**
     * Concentra toda a diferença na última parcela em aberto.
     */
    private function abaterNaUltimaParcela($parcelas, float $diferenca)
    {
        $ultima    = $parcelas->last();
        $novoValor = round((float) $ultima->valor - $diferenca, 2);

        if ($novoValor < 0) {
            $novoValor = 0;
        }

        $ultima->update(['valor' => $novoValor]);

        if ($novoValor == 0) {
            $ultima->update([
                'status'         => 'pago',
                'data_pagamento' => now()->toDateString(),
            ]);
        }
    }

    public function destroy($id)
    {
        $lancamento = LancamentoFinanceiro::find($id);

        if (!$lancamento) {
            return response()->json([
                'errors' => "Lançamento com id {$id} não existe."
            ], Response::HTTP_NOT_FOUND);
        }

        if ($lancamento->status === 'pago') {
            return response()->json([
                'errors' => 'Não é possível excluir um lançamento já pago.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lancamento->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function resumo(Request $request)
    {
        $mes = $request->query('mes', now()->format('Y-m'));

        $dados = LancamentoFinanceiro::whereRaw("DATE_FORMAT(data_vencimento, '%Y-%m') = ?", [$mes])
            ->select('status', DB::raw('COUNT(*) as quantidade'), DB::raw('SUM(valor) as total'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return response()->json([
            'pendente' => [
                'quantidade' => $dados['pendente']->quantidade ?? 0,
                'total'      => $dados['pendente']->total ?? 0,
            ],
            'pago' => [
                'quantidade' => $dados['pago']->quantidade ?? 0,
                'total'      => $dados['pago']->total ?? 0,
            ],
            'atrasado' => [
                'quantidade' => $dados['atrasado']->quantidade ?? 0,
                'total'      => $dados['atrasado']->total ?? 0,
            ],
        ], Response::HTTP_OK);
    }

    public function buscarRestanteParcelas($vendaId)
    {   
        $parcelasRestantes = LancamentoFinanceiro::where('venda_id', $vendaId)
            ->whereIn('status', ['pendente', 'atrasado'])
            ->orderBy('numero_parcela')
            ->get();

        return response()->json($parcelasRestantes, Response::HTTP_OK);
    }
    
}
