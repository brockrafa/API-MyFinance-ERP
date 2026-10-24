<?php

namespace App\Services;

use App\Models\Venda;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Margem real das vendas: o que entrou de fato (líquido do canal) menos o custo dos itens
 * (custo médio no momento da venda) e menos os custos adicionais digitados na venda.
 *
 * Por produto, a taxa do canal e os custos adicionais de cada venda são rateados entre os itens
 * proporcionalmente à receita de cada um (preço × quantidade).
 */
class RelatorioMargemService
{
    public function gerar(Carbon $inicio, Carbon $fim, ?string $canal = null): array
    {
        $vendas = Venda::query()
            ->with(['cliente:id,nome', 'itens.produto:id,produto'])
            ->whereBetween('data_venda', [$inicio->toDateString(), $fim->toDateString()])
            ->when($canal, fn ($q) => $q->where('canal', $canal))
            ->orderByDesc('data_venda')
            ->orderByDesc('id')
            ->get();

        $linhas = $vendas->map(fn (Venda $venda) => ['venda' => $venda] + $venda->resumoFinanceiro());

        return [
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString(), 'canal' => $canal],
            'resumo' => $this->somar($linhas),
            'por_canal' => $this->porCanal($linhas),
            'por_produto' => $this->porProduto($vendas),
            'vendas' => $linhas->take(500)->map(fn (array $linha) => [
                'id' => $linha['venda']->id,
                'data_venda' => $linha['venda']->data_venda,
                'cliente' => $linha['venda']->cliente?->nome,
                'canal' => $linha['canal'],
                'total' => $linha['total'],
                'taxa_canal' => $linha['taxa_canal'],
                'receita_liquida' => $linha['receita_liquida'],
                'custo' => $linha['custo'],
                'custos_adicionais' => $linha['custos_adicionais'],
                'margem' => $linha['margem'],
                'margem_percentual' => $linha['margem_percentual'],
            ])->values()->all(),
        ];
    }

    private function somar(Collection $linhas): array
    {
        $receitaBruta = round($linhas->sum('total'), 2);
        $taxas = round($linhas->sum('taxa_canal'), 2);
        $receitaLiquida = round($linhas->sum('receita_liquida'), 2);
        $custo = round($linhas->sum('custo'), 2);
        $adicionais = round($linhas->sum('custos_adicionais'), 2);
        $margem = round($receitaLiquida - $custo - $adicionais, 2);

        return [
            'vendas' => $linhas->count(),
            'receita_bruta' => $receitaBruta,
            'taxas_canal' => $taxas,
            'receita_liquida' => $receitaLiquida,
            'custo' => $custo,
            'custos_adicionais' => $adicionais,
            'margem' => $margem,
            'margem_percentual' => $receitaLiquida > 0 ? round($margem / $receitaLiquida * 100, 1) : null,
        ];
    }

    private function porCanal(Collection $linhas): array
    {
        return $linhas->groupBy('canal')
            ->map(fn (Collection $grupo, string $canal) => ['canal' => $canal] + $this->somar($grupo))
            ->sortByDesc('receita_liquida')
            ->values()
            ->all();
    }

    private function porProduto(Collection $vendas): array
    {
        $acumulado = [];

        foreach ($vendas as $venda) {
            $itens = $venda->itens;
            $receitaItens = (float) $itens->sum(fn ($i) => (float) $i->valor_unitario * (int) $i->quantidade);
            $taxa = $venda->taxaCanal();
            $adicionais = (float) $venda->custos_adicionais;

            foreach ($itens as $item) {
                $receita = (float) $item->valor_unitario * (int) $item->quantidade;
                $participacao = $receitaItens > 0 ? $receita / $receitaItens : 0;
                $custoTotal = (float) $item->custo_total;
                $custo = $custoTotal > 0 ? $custoTotal : (float) $item->custo_unitario * (int) $item->quantidade;

                // Serviços e itens avulsos entram juntos, pois não têm custo de estoque
                $chave = $item->tipo === 'produto' && $item->produto_id ? 'p' . $item->produto_id : 'outros';
                $acumulado[$chave] ??= [
                    'produto_id' => $chave === 'outros' ? null : $item->produto_id,
                    'produto' => $chave === 'outros' ? 'Serviços e itens avulsos' : ($item->produto?->produto ?? 'Produto removido'),
                    'quantidade' => 0,
                    'receita_bruta' => 0.0,
                    'taxas_canal' => 0.0,
                    'custo' => 0.0,
                    'custos_adicionais' => 0.0,
                ];

                $acumulado[$chave]['quantidade'] += (int) $item->quantidade;
                $acumulado[$chave]['receita_bruta'] += $receita;
                $acumulado[$chave]['taxas_canal'] += $taxa * $participacao;
                $acumulado[$chave]['custo'] += $custo;
                $acumulado[$chave]['custos_adicionais'] += $adicionais * $participacao;
            }
        }

        return collect($acumulado)->map(function (array $linha) {
            $receitaLiquida = $linha['receita_bruta'] - $linha['taxas_canal'];
            $margem = $receitaLiquida - $linha['custo'] - $linha['custos_adicionais'];

            return [
                'produto_id' => $linha['produto_id'],
                'produto' => $linha['produto'],
                'quantidade' => $linha['quantidade'],
                'receita_bruta' => round($linha['receita_bruta'], 2),
                'taxas_canal' => round($linha['taxas_canal'], 2),
                'receita_liquida' => round($receitaLiquida, 2),
                'custo' => round($linha['custo'], 2),
                'custos_adicionais' => round($linha['custos_adicionais'], 2),
                'margem' => round($margem, 2),
                'margem_percentual' => $receitaLiquida > 0 ? round($margem / $receitaLiquida * 100, 1) : null,
            ];
        })->sortByDesc('margem')->values()->all();
    }
}
