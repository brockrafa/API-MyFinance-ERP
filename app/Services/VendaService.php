<?php

namespace App\Services;

use App\Models\Agenda;
use App\Models\Estoque;
use App\Models\EstoqueSaldo;
use App\Models\ItemVenda;
use App\Models\LancamentoFinanceiro;
use App\Models\Produto;
use App\Models\Servico;
use App\Models\Venda;
use Carbon\Carbon;

class VendaService
{
    public function __construct(private EstoqueService $estoqueService)
    {
    }

    public function criar(array $dadosVenda, array $itens, int $empresaId, ?int $agendaId = null): Venda
    {
        $venda = new Venda();
        $venda->cliente_id = $dadosVenda['cliente_id'];
        $venda->estoque_id = $dadosVenda['estoque_id'] ?? null;
        $venda->agenda_id = $agendaId;
        $venda->total = 0;
        $venda->forma_pagamento_id = $dadosVenda['forma_pagamento_id'];
        $venda->data_venda = $dadosVenda['data_venda'] ?? now()->toDateString();
        $venda->tipo_venda = $dadosVenda['tipo_venda'];
        $venda->empresa_id = $empresaId;
        $venda->save();

        $this->salvarItens($itens, $venda);

        if ($venda->estoque_id) {
            $this->estoqueService->baixarVenda($venda, Estoque::findOrFail($venda->estoque_id));
        }

        $this->aplicarCondicaoPagamento($dadosVenda, $venda);
        $venda->save();

        $this->gerarLancamentos($venda);

        if (!$agendaId) {
            $this->gerarAgendamentosDeServicos($itens, $venda, $empresaId);
        }

        return $venda;
    }

    private function salvarItens(array $itens, Venda $venda): void
    {
        foreach ($itens as $itemDados) {
            $item = new ItemVenda();
            $item->venda_id = $venda->id;
            $item->empresa_id = $venda->empresa_id;
            $item->tipo = $itemDados['tipo'];
            $item->quantidade = $itemDados['quantidade'];
            $item->valor_unitario = (float) $itemDados['valor'];

            if ($itemDados['tipo'] === 'avulso') {
                $item->descricao = $itemDados['descricao'] ?? null;
            } elseif ($itemDados['tipo'] === 'servico') {
                $model = Servico::find($itemDados['id']);
                if (!$model) {
                    abort(404, "Serviço com id {$itemDados['id']} não existe.");
                }
                $item->servico_id = $itemDados['id'];
            } else {
                $model = Produto::find($itemDados['id']);
                if (!$model) {
                    abort(404, "Produto com id {$itemDados['id']} não existe.");
                }
                $saldo = EstoqueSaldo::query()
                    ->where('estoque_id', $venda->estoque_id)
                    ->where('produto_id', $model->id)
                    ->first();
                // Produtos cadastrados antes do módulo de estoque não têm saldo/custo médio;
                // nesse caso usa o preço de custo cadastrado no produto como fallback.
                $item->custo_unitario = (float) ($saldo?->custo_medio ?: $model->valor ?? 0);
                $item->produto_id = $itemDados['id'];
            }

            $item->save();
            $venda->total += $itemDados['quantidade'] * $itemDados['valor'];
        }
    }

    private function aplicarCondicaoPagamento(array $dadosVenda, Venda $venda): void
    {
        if ($venda->tipo_venda === 'prazo') {
            $condicao = $dadosVenda['condicao_pagamento'];

            $venda->parcelas = $condicao['parcelas'];
            $venda->entrada = $condicao['entrada'];
            $venda->valor_parcela = ($venda->total - $condicao['entrada']) / $condicao['parcelas'];
            $venda->primeiro_vencimento = $condicao['primeiro_vencimento'];
        }
    }

    private function gerarLancamentos(Venda $venda): void
    {
        if ($venda->tipo_venda === 'avista') {
            LancamentoFinanceiro::create([
                'tipo' => 'entrada',
                'venda_id' => $venda->id,
                'cliente_id' => $venda->cliente_id,
                'numero_parcela' => 1,
                'total_parcelas' => 1,
                'valor' => $venda->total,
                'valor_pago' => $venda->total,
                'data_vencimento' => $venda->data_venda,
                'data_pagamento' => $venda->data_venda,
                'status' => 'pago',
            ]);

            return;
        }

        $primeiroVencimento = Carbon::parse($venda->primeiro_vencimento);

        if ($venda->entrada > 0) {
            LancamentoFinanceiro::create([
                'tipo' => 'entrada',
                'venda_id' => $venda->id,
                'cliente_id' => $venda->cliente_id,
                'numero_parcela' => 0,
                'total_parcelas' => $venda->parcelas,
                'valor' => $venda->entrada,
                'valor_pago' => $venda->entrada,
                'data_vencimento' => $venda->data_venda,
                'data_pagamento' => $venda->data_venda,
                'status' => 'pago',
            ]);
        }

        for ($i = 1; $i <= $venda->parcelas; $i++) {
            LancamentoFinanceiro::create([
                'tipo' => 'entrada',
                'venda_id' => $venda->id,
                'cliente_id' => $venda->cliente_id,
                'numero_parcela' => $i,
                'total_parcelas' => $venda->parcelas,
                'valor' => $venda->valor_parcela,
                'data_vencimento' => $primeiroVencimento->copy()->addMonths($i - 1),
                'data_pagamento' => null,
                'status' => 'pendente',
            ]);
        }
    }

    private function gerarAgendamentosDeServicos(array $itens, Venda $venda, int $empresaId): void
    {
        $servicoIds = collect($itens)->where('tipo', 'servico')->pluck('id')->unique();
        if ($servicoIds->isEmpty()) {
            return;
        }

        $servicosGeramAgendamento = Servico::whereIn('id', $servicoIds)
            ->where('gera_agendamento', true)
            ->get();

        foreach ($servicosGeramAgendamento as $servico) {
            $agenda = Agenda::create([
                'cliente_id' => $venda->cliente_id,
                'venda_id' => $venda->id,
                'data_agendamento' => null,
                'hora_agendamento' => null,
                'status' => 'aberto',
                'observacao' => 'Gerado automaticamente pela venda #' . $venda->id . ' — pendente de horário e profissional.',
                'empresa_id' => $empresaId,
            ]);

            // Adicionar os itens de venda (produtos e serviços) ao agendamento
            foreach ($venda->itens as $itemVenda) {
                if ($itemVenda->tipo === 'servico' && $itemVenda->servico_id === $servico->id) {
                    \App\Models\AgendaItem::create([
                        'agenda_id' => $agenda->id,
                        'tipo' => 'servico',
                        'servico_id' => $itemVenda->servico_id,
                        'descricao' => $itemVenda->servico->servico ?? null,
                        'quantidade' => $itemVenda->quantidade,
                        'valor_unitario' => $itemVenda->valor_unitario,
                        'empresa_id' => $empresaId,
                    ]);
                } elseif ($itemVenda->tipo === 'produto') {
                    // Incluir produtos também se estiverem na venda
                    \App\Models\AgendaItem::create([
                        'agenda_id' => $agenda->id,
                        'tipo' => 'produto',
                        'produto_id' => $itemVenda->produto_id,
                        'descricao' => $itemVenda->produto->produto ?? null,
                        'quantidade' => $itemVenda->quantidade,
                        'valor_unitario' => $itemVenda->valor_unitario,
                        'empresa_id' => $empresaId,
                    ]);
                } elseif ($itemVenda->tipo === 'avulso') {
                    // Incluir itens avulsos também
                    \App\Models\AgendaItem::create([
                        'agenda_id' => $agenda->id,
                        'tipo' => 'avulso',
                        'descricao' => $itemVenda->descricao ?? 'Item avulso',
                        'quantidade' => $itemVenda->quantidade,
                        'valor_unitario' => $itemVenda->valor_unitario,
                        'empresa_id' => $empresaId,
                    ]);
                }
            }
        }
    }
}
