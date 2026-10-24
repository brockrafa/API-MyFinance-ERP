<?php

namespace App\Services;

use App\Models\Agenda;
use App\Models\AgendaItem;
use App\Models\Estoque;
use App\Models\ItemVenda;
use App\Models\LancamentoFinanceiro;
use App\Models\Produto;
use App\Models\Servico;
use App\Models\Venda;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

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
        $this->aplicarCanal($dadosVenda, $venda);
        $venda->save();

        $this->salvarItens($itens, $venda);
        $this->validarValorLiquido($venda);

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

    /**
     * Recria itens, baixa de estoque e lançamentos da venda a partir dos novos dados.
     * Recusa a edição quando já existe pagamento de parcela (além da entrada/à vista),
     * para não perder o histórico do que o cliente já pagou.
     */
    public function atualizar(Venda $venda, array $dadosVenda, array $itens): Venda
    {
        if ($this->temParcelaPaga($venda)) {
            throw ValidationException::withMessages([
                'venda' => 'Esta venda já tem parcelas pagas: não é possível alterar itens ou valores. Altere apenas a data e a forma de pagamento.',
            ]);
        }

        $this->estoqueService->estornarVenda($venda);

        ItemVenda::where('venda_id', $venda->id)->delete();
        LancamentoFinanceiro::where('venda_id', $venda->id)->delete();
        $venda->unsetRelation('itens');

        $venda->estoque_id = $dadosVenda['estoque_id'] ?? $venda->estoque_id;
        $venda->forma_pagamento_id = $dadosVenda['forma_pagamento_id'];
        $venda->data_venda = $dadosVenda['data_venda'] ?? $venda->data_venda;
        $venda->tipo_venda = $dadosVenda['tipo_venda'];
        $venda->total = 0;
        $venda->parcelas = null;
        $venda->entrada = null;
        $venda->valor_parcela = null;
        $venda->primeiro_vencimento = null;
        $this->aplicarCanal($dadosVenda, $venda);
        $venda->save();

        $this->salvarItens($itens, $venda);
        $this->validarValorLiquido($venda);

        if ($venda->estoque_id) {
            $this->estoqueService->baixarVenda($venda, Estoque::findOrFail($venda->estoque_id));
        }

        $this->aplicarCondicaoPagamento($dadosVenda, $venda);
        $venda->save();

        $this->gerarLancamentos($venda);

        return $venda;
    }

    /** Altera só data e forma de pagamento, sem mexer em itens, estoque ou parcelas. */
    public function atualizarDados(Venda $venda, array $dadosVenda): Venda
    {
        $venda->forma_pagamento_id = $dadosVenda['forma_pagamento_id'] ?? $venda->forma_pagamento_id;
        $venda->data_venda = $dadosVenda['data_venda'] ?? $venda->data_venda;
        $venda->save();

        return $venda;
    }

    /** Pagamentos que não são a entrada nem o recebimento à vista indicam cobrança já em andamento. */
    public function temParcelaPaga(Venda $venda): bool
    {
        return $venda->lancamentos()
            ->where('status', 'pago')
            ->where('numero_parcela', '>', 0)
            ->when($venda->tipo_venda === 'avista', fn ($q) => $q->whereRaw('1 = 0'))
            ->exists();
    }

    /** Devolve o estoque baixado e apaga a venda (itens e lançamentos caem em cascata). */
    public function excluir(Venda $venda): void
    {
        $this->estoqueService->estornarVenda($venda);
        $venda->delete();
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
                // Custo vem das entradas de estoque (custo médio do estoque da venda, com fallback).
                $item->custo_unitario = $this->estoqueService->custoReferencia($model->id, $venda->estoque_id);
                $item->custo_total = $item->custo_unitario * $item->quantidade;
                $item->produto_id = $itemDados['id'];
            }

            $item->save();
            $venda->total += $itemDados['quantidade'] * $itemDados['valor'];
        }
    }

    /** Canal de venda e custos digitados; fora de `venda_direta` o valor líquido do canal é a receita real. */
    private function aplicarCanal(array $dadosVenda, Venda $venda): void
    {
        $canal = $dadosVenda['canal'] ?? $venda->canal ?? 'venda_direta';
        $venda->canal = $canal;
        $venda->valor_liquido_recebido = $canal === 'venda_direta' ? null : ($dadosVenda['valor_liquido_recebido'] ?? null);
        $venda->custos_adicionais = $dadosVenda['custos_adicionais'] ?? 0;
    }

    private function validarValorLiquido(Venda $venda): void
    {
        if ($venda->canal === 'venda_direta') {
            return;
        }

        if ($venda->valor_liquido_recebido === null) {
            throw ValidationException::withMessages([
                'valor_liquido_recebido' => 'Informe o valor recebido após os descontos do canal.',
            ]);
        }

        if ((float) $venda->valor_liquido_recebido > (float) $venda->total) {
            throw ValidationException::withMessages([
                'valor_liquido_recebido' => 'O valor recebido não pode ser maior que o total da venda.',
            ]);
        }
    }

    private function aplicarCondicaoPagamento(array $dadosVenda, Venda $venda): void
    {
        if ($venda->tipo_venda === 'prazo') {
            $condicao = $dadosVenda['condicao_pagamento'];

            $venda->parcelas = $condicao['parcelas'];
            $venda->entrada = $condicao['entrada'];
            // Parcelas e entrada incidem sobre o que será recebido (líquido do canal, se houver)
            $venda->valor_parcela = ($venda->receitaLiquida() - $condicao['entrada']) / $condicao['parcelas'];
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
                'valor' => $venda->receitaLiquida(),
                'valor_pago' => $venda->receitaLiquida(),
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

        // Cada serviço que gera agendamento vira uma agenda própria com os seus itens;
        // produtos e itens avulsos da venda acompanham somente a primeira agenda.
        foreach ($servicosGeramAgendamento->values() as $indice => $servico) {
            $agenda = Agenda::create([
                'cliente_id' => $venda->cliente_id,
                'venda_id' => $venda->id,
                'data_agendamento' => null,
                'hora_agendamento' => null,
                'status' => 'aberto',
                'observacao' => 'Gerado automaticamente pela venda #' . $venda->id . ' — pendente de horário e profissional.',
                'empresa_id' => $empresaId,
            ]);

            foreach ($venda->itens as $itemVenda) {
                $dados = match (true) {
                    $itemVenda->tipo === 'servico' && $itemVenda->servico_id === $servico->id => [
                        'tipo' => 'servico',
                        'servico_id' => $itemVenda->servico_id,
                        'descricao' => $itemVenda->servico->servico ?? null,
                    ],
                    $indice === 0 && $itemVenda->tipo === 'produto' => [
                        'tipo' => 'produto',
                        'produto_id' => $itemVenda->produto_id,
                        'descricao' => $itemVenda->produto->produto ?? null,
                    ],
                    $indice === 0 && $itemVenda->tipo === 'avulso' => [
                        'tipo' => 'avulso',
                        'descricao' => $itemVenda->descricao ?? 'Item avulso',
                    ],
                    default => null,
                };

                if ($dados === null) {
                    continue;
                }

                AgendaItem::create($dados + [
                    'agenda_id' => $agenda->id,
                    'quantidade' => $itemVenda->quantidade,
                    'valor_unitario' => $itemVenda->valor_unitario,
                    'empresa_id' => $empresaId,
                ]);
            }
        }
    }
}
