<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\LancamentoFinanceiro;
use App\Models\Venda;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Visões de cobrança: quem deve, extrato do cliente e dados dos recibos.
 * Tudo parte de lancamentos_financeiros (tipo `entrada`); "vencido" é calculado pela data,
 * sem depender do campo `status`.
 */
class CobrancaService
{
    public function devedores(?Carbon $hoje = null): array
    {
        $hoje = ($hoje ?? Carbon::today())->copy()->startOfDay();

        $abertos = $this->parcelasAbertas()->groupBy('cliente_id');
        $clientes = Cliente::whereIn('id', $abertos->keys())->get(['id', 'nome', 'telefone'])->keyBy('id');

        return $abertos->map(function (Collection $parcelas, int $clienteId) use ($clientes, $hoje) {
            $vencidas = $parcelas->filter(fn (LancamentoFinanceiro $p) => $p->data_vencimento->lt($hoje));
            $maisAntiga = $vencidas->min(fn (LancamentoFinanceiro $p) => $p->data_vencimento);
            $proxima = $parcelas->reject(fn (LancamentoFinanceiro $p) => $p->data_vencimento->lt($hoje))
                ->min(fn (LancamentoFinanceiro $p) => $p->data_vencimento);
            $cliente = $clientes->get($clienteId);

            return [
                'cliente_id' => $clienteId,
                'nome' => $cliente?->nome ?? 'Cliente removido',
                'telefone' => $cliente?->telefone,
                'total_em_aberto' => round($parcelas->sum(fn ($p) => (float) $p->valor), 2),
                'total_vencido' => round($vencidas->sum(fn ($p) => (float) $p->valor), 2),
                'qtd_parcelas' => $parcelas->count(),
                'qtd_vencidas' => $vencidas->count(),
                'vencimento_mais_antigo' => $maisAntiga?->toDateString(),
                'dias_atraso' => $maisAntiga ? (int) $maisAntiga->copy()->startOfDay()->diffInDays($hoje) : 0,
                'proximo_vencimento' => $proxima?->toDateString(),
            ];
        })
            ->sortByDesc('total_em_aberto')
            ->values()
            ->all();
    }

    public function extrato(Cliente $cliente, ?Carbon $hoje = null): array
    {
        $hoje = ($hoje ?? Carbon::today())->copy()->startOfDay();

        $vendas = Venda::where('cliente_id', $cliente->id)
            ->with(['itens.produto:id,produto', 'itens.servico:id,servico', 'formaPagamento:id,descricao'])
            ->orderBy('data_venda')->orderBy('id')
            ->get();

        $lancamentos = LancamentoFinanceiro::where('tipo', 'entrada')
            ->where('cliente_id', $cliente->id)
            ->orderBy('data_vencimento')->orderBy('numero_parcela')
            ->get();

        $movimentos = collect();

        foreach ($vendas as $venda) {
            $movimentos->push([
                'data' => (string) $venda->data_venda,
                'ordem' => 0,
                'tipo' => 'compra',
                'venda_id' => $venda->id,
                'descricao' => 'Venda #' . $venda->id . ' — ' . $this->resumoItens($venda),
                'valor' => round($venda->receitaLiquida(), 2),
            ]);
        }

        foreach ($lancamentos->where('status', 'pago') as $pagamento) {
            $movimentos->push([
                'data' => ($pagamento->data_pagamento ?? $pagamento->data_vencimento)->toDateString(),
                'ordem' => 1,
                'tipo' => 'pagamento',
                'venda_id' => $pagamento->venda_id,
                'lancamento_id' => $pagamento->id,
                'descricao' => 'Pagamento ' . $this->nomeParcela($pagamento) . ' — venda #' . $pagamento->venda_id,
                'valor' => -round((float) ($pagamento->valor_pago ?? $pagamento->valor), 2),
            ]);
        }

        $saldo = 0.0;
        $movimentos = $movimentos
            ->sortBy([['data', 'asc'], ['ordem', 'asc']])
            ->values()
            ->map(function (array $mov) use (&$saldo) {
                $saldo = round($saldo + $mov['valor'], 2);

                return $mov + ['saldo' => $saldo];
            });

        $abertas = $lancamentos->where('status', '!=', 'pago')->values();
        $vencidas = $abertas->filter(fn (LancamentoFinanceiro $p) => $p->data_vencimento->lt($hoje));

        return [
            'cliente' => $cliente->only(['id', 'nome', 'telefone', 'email']),
            'resumo' => [
                'total_comprado' => round($vendas->sum(fn (Venda $v) => $v->receitaLiquida()), 2),
                'total_pago' => round($lancamentos->where('status', 'pago')->sum(fn ($p) => (float) ($p->valor_pago ?? $p->valor)), 2),
                'total_em_aberto' => round($abertas->sum(fn ($p) => (float) $p->valor), 2),
                'total_vencido' => round($vencidas->sum(fn ($p) => (float) $p->valor), 2),
                'qtd_vendas' => $vendas->count(),
                'qtd_parcelas_abertas' => $abertas->count(),
            ],
            'parcelas_abertas' => $abertas->map(fn (LancamentoFinanceiro $p) => [
                'id' => $p->id,
                'venda_id' => $p->venda_id,
                'parcela' => $this->nomeParcela($p),
                'valor' => round((float) $p->valor, 2),
                'data_vencimento' => $p->data_vencimento->toDateString(),
                'vencida' => $p->data_vencimento->lt($hoje),
                'dias_atraso' => $p->data_vencimento->lt($hoje) ? (int) $p->data_vencimento->copy()->startOfDay()->diffInDays($hoje) : 0,
            ])->all(),
            'movimentos' => $movimentos->all(),
        ];
    }

    /** Dados do recibo de uma venda (o que o cliente comprou e o que já pagou). */
    public function reciboVenda(Venda $venda): array
    {
        $venda->load(['cliente:id,nome,telefone', 'formaPagamento:id,descricao', 'itens.produto:id,produto', 'itens.servico:id,servico', 'lancamentos']);

        $abertas = $venda->lancamentos->where('status', '!=', 'pago');
        $emAberto = round($abertas->sum(fn ($p) => (float) $p->valor), 2);
        $total = round((float) $venda->total, 2);
        // Em canais (ex.: Mercado Livre) o cliente pagou o total ao canal; o líquido é assunto interno
        $pago = $emAberto <= 0 ? $total : round(max($total - $emAberto, 0), 2);

        return [
            'numero' => $venda->id,
            'data' => (string) $venda->data_venda,
            'cliente' => $venda->cliente?->only(['nome', 'telefone']),
            'forma_pagamento' => $venda->formaPagamento?->descricao,
            'itens' => $venda->itens->map(fn ($item) => [
                'descricao' => $this->descricaoItem($item),
                'quantidade' => (int) $item->quantidade,
                'valor_unitario' => round((float) $item->valor_unitario, 2),
                'subtotal' => round((float) $item->valor_unitario * (int) $item->quantidade, 2),
            ])->values()->all(),
            'total' => $total,
            'pago' => $pago,
            'em_aberto' => $emAberto,
            'parcelas_abertas' => $abertas->sortBy('data_vencimento')->map(fn ($p) => [
                'parcela' => $this->nomeParcela($p),
                'valor' => round((float) $p->valor, 2),
                'data_vencimento' => $p->data_vencimento->toDateString(),
            ])->values()->all(),
        ];
    }

    /** Dados do recibo de um pagamento (baixa de parcela). */
    public function reciboPagamento(LancamentoFinanceiro $pagamento): array
    {
        $pagamento->load(['cliente:id,nome,telefone', 'venda.formaPagamento:id,descricao']);

        $restante = LancamentoFinanceiro::where('venda_id', $pagamento->venda_id)
            ->where('tipo', 'entrada')
            ->where('status', '!=', 'pago')
            ->get();

        return [
            'numero' => $pagamento->id,
            'venda_id' => $pagamento->venda_id,
            'cliente' => $pagamento->cliente?->only(['nome', 'telefone']),
            'parcela' => $this->nomeParcela($pagamento),
            'valor_pago' => round((float) ($pagamento->valor_pago ?? $pagamento->valor), 2),
            'data_pagamento' => ($pagamento->data_pagamento ?? $pagamento->data_vencimento)->toDateString(),
            'forma_pagamento' => $pagamento->forma_pagamento ?: $pagamento->venda?->formaPagamento?->descricao,
            'restante_da_venda' => round($restante->sum(fn ($p) => (float) $p->valor), 2),
            'parcelas_restantes' => $restante->count(),
        ];
    }

    private function parcelasAbertas(): Collection
    {
        return LancamentoFinanceiro::where('tipo', 'entrada')
            ->where('status', '!=', 'pago')
            ->whereNotNull('cliente_id')
            ->get(['id', 'cliente_id', 'venda_id', 'valor', 'data_vencimento', 'numero_parcela', 'total_parcelas']);
    }

    private function nomeParcela(LancamentoFinanceiro $l): string
    {
        return (int) $l->numero_parcela === 0 ? 'da entrada' : "{$l->numero_parcela}/{$l->total_parcelas}";
    }

    private function resumoItens(Venda $venda): string
    {
        $nomes = $venda->itens->map(fn ($item) => $this->descricaoItem($item))->filter()->values();

        if ($nomes->isEmpty()) {
            return 'sem itens';
        }

        return $nomes->take(2)->implode(', ') . ($nomes->count() > 2 ? ' e mais ' . ($nomes->count() - 2) : '');
    }

    private function descricaoItem($item): string
    {
        return match ($item->tipo) {
            'servico' => $item->servico?->servico ?? 'Serviço',
            'avulso' => $item->descricao ?: 'Item avulso',
            default => $item->produto?->produto ?? 'Produto',
        };
    }
}
