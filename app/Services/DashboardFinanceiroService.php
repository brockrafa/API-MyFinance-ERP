<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\LancamentoFinanceiro;
use App\Models\Venda;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Agrega os dados do dashboard gerencial (recebimentos, despesas, fluxo de caixa e vendas).
 *
 * Observações de modelagem:
 *  - Contas a receber = lancamentos_financeiros.tipo = 'entrada'; a pagar = tipo = 'saida'.
 *  - "Em atraso" é calculado pela data (não pago e vencimento < hoje), sem depender do campo
 *    `status`, que só é atualizado quando a listagem de recebimentos é aberta.
 *  - Todas as consultas passam pelo escopo global de empresa dos models (multi-tenant).
 */
class DashboardFinanceiroService
{
    private Carbon $hoje;
    private int $meses;

    public function __construct(?Carbon $hoje = null)
    {
        $this->hoje = ($hoje ?? Carbon::today())->copy()->startOfDay();
    }

    /**
     * @param int  $meses   janela de histórico (6 a 24) incluindo o mês atual
     */
    public function gerar(int $meses, bool $receber, bool $pagar, bool $vendas): array
    {
        $this->meses = max(3, min(24, $meses));

        $resposta = [
            'gerado_em'      => now()->toIso8601String(),
            'hoje'           => $this->hoje->toDateString(),
            'mes_referencia' => $this->hoje->format('Y-m'),
            'janela_meses'   => $this->meses,
            'permissoes'     => ['receber' => $receber, 'pagar' => $pagar, 'vendas' => $vendas],
        ];

        $entradasAbertas = $receber ? $this->abertos('entrada') : collect();
        $saidasAbertas   = $pagar ? $this->abertos('saida') : collect();

        if ($receber) {
            $resposta['receber'] = $this->receber($entradasAbertas);
        }

        if ($pagar) {
            $resposta['despesas'] = $this->despesas($saidasAbertas, $receber ? ($resposta['receber']['kpis']['mes']['previsto'] ?? 0) : null);
        }

        if ($receber || $pagar) {
            $resposta['fluxo_caixa'] = $this->fluxoCaixa($entradasAbertas, $saidasAbertas);
        }

        if ($vendas) {
            $resposta['vendas'] = $this->vendas();
        }

        return $resposta;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Consultas base
    // ─────────────────────────────────────────────────────────────────────

    /** Todos os lançamentos não pagos (sem limite de data: atrasos antigos também contam). */
    private function abertos(string $tipo): Collection
    {
        return LancamentoFinanceiro::query()
            ->where('tipo', $tipo)
            ->where('status', '!=', 'pago')
            ->toBase()
            ->get(['id', 'valor', 'data_vencimento', 'cliente_id', 'categoria_id', 'fornecedor', 'descricao', 'numero_parcela', 'total_parcelas', 'conta_pagar_id']);
    }

    /** Lançamentos com vencimento na janela (histórico + 3 meses à frente), pagos ou não. */
    private function janela(string $tipo): Collection
    {
        return LancamentoFinanceiro::query()
            ->where('tipo', $tipo)
            ->whereBetween('data_vencimento', [$this->inicioJanela()->toDateString(), $this->fimJanela()->toDateString()])
            ->toBase()
            ->get(['id', 'valor', 'valor_pago', 'status', 'data_vencimento', 'data_pagamento', 'cliente_id', 'categoria_id', 'fornecedor', 'descricao', 'conta_pagar_id']);
    }

    private function inicioJanela(): Carbon
    {
        return $this->hoje->copy()->startOfMonth()->subMonths($this->meses - 1);
    }

    private function fimJanela(): Carbon
    {
        return $this->hoje->copy()->startOfMonth()->addMonths(3)->endOfMonth();
    }

    /** @return string[] lista de 'Y-m' do início da janela até 3 meses à frente */
    private function chavesMeses(): array
    {
        $chaves = [];
        $cursor = $this->inicioJanela();
        $fim = $this->fimJanela();
        while ($cursor->lte($fim)) {
            $chaves[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }
        return $chaves;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Recebimentos
    // ─────────────────────────────────────────────────────────────────────

    private function receber(Collection $abertos): array
    {
        $janela = $this->janela('entrada');
        $mesAtual = $this->hoje->format('Y-m');
        $mesAnterior = $this->hoje->copy()->subMonth()->format('Y-m');

        // ── série mensal ──
        $serie = [];
        foreach ($this->chavesMeses() as $ym) {
            $serie[$ym] = ['mes' => $ym, 'previsto' => 0.0, 'recebido' => 0.0, 'atrasado' => 0.0, 'a_vencer' => 0.0];
        }
        foreach ($janela as $l) {
            $ym = substr($l->data_vencimento, 0, 7);
            if (!isset($serie[$ym])) {
                continue;
            }
            $valor = (float) $l->valor;
            $serie[$ym]['previsto'] += $valor;
            if ($l->status === 'pago') {
                $serie[$ym]['recebido'] += (float) ($l->valor_pago ?? $l->valor);
            } elseif ($l->data_vencimento < $this->hoje->toDateString()) {
                $serie[$ym]['atrasado'] += $valor;
            } else {
                $serie[$ym]['a_vencer'] += $valor;
            }
        }
        $evolucao = array_values(array_map(fn ($m) => $this->arredondar($m), $serie));

        // ── em aberto / atraso / a vencer ──
        $hojeStr = $this->hoje->toDateString();
        $vencidos = $abertos->filter(fn ($l) => $l->data_vencimento < $hojeStr);
        $aVencer  = $abertos->filter(fn ($l) => $l->data_vencimento >= $hojeStr);

        $emAtraso = (float) $vencidos->sum('valor');
        $maiorAtraso = $vencidos->isEmpty()
            ? 0
            : (int) $this->hoje->diffInDays(Carbon::parse($vencidos->min('data_vencimento')));

        $limite7  = $this->hoje->copy()->addDays(7)->toDateString();
        $limite30 = $this->hoje->copy()->addDays(30)->toDateString();

        // ── inadimplência: atrasado / tudo que já venceu na janela ──
        $jaVenceu = (float) $janela->filter(fn ($l) => $l->data_vencimento < $hojeStr)->sum('valor');
        $atrasadoJanela = (float) $janela->filter(fn ($l) => $l->status !== 'pago' && $l->data_vencimento < $hojeStr)->sum('valor');
        $inadimplencia = $jaVenceu > 0 ? round($atrasadoJanela / $jaVenceu * 100, 1) : 0.0;

        $mes = $serie[$mesAtual] ?? ['previsto' => 0, 'recebido' => 0, 'atrasado' => 0, 'a_vencer' => 0];
        $mesAnt = $serie[$mesAnterior] ?? ['previsto' => 0, 'recebido' => 0];

        // ── aging ──
        $faixas = [
            ['faixa' => '1 a 30 dias',  'min' => 1,  'max' => 30,   'valor' => 0.0, 'qtd' => 0],
            ['faixa' => '31 a 60 dias', 'min' => 31, 'max' => 60,   'valor' => 0.0, 'qtd' => 0],
            ['faixa' => '61 a 90 dias', 'min' => 61, 'max' => 90,   'valor' => 0.0, 'qtd' => 0],
            ['faixa' => 'Mais de 90',   'min' => 91, 'max' => 99999, 'valor' => 0.0, 'qtd' => 0],
        ];
        foreach ($vencidos as $l) {
            $dias = (int) Carbon::parse($l->data_vencimento)->diffInDays($this->hoje);
            foreach ($faixas as &$f) {
                if ($dias >= $f['min'] && $dias <= $f['max']) {
                    $f['valor'] += (float) $l->valor;
                    $f['qtd']++;
                    break;
                }
            }
            unset($f);
        }
        $aging = array_map(fn ($f) => ['faixa' => $f['faixa'], 'valor' => round($f['valor'], 2), 'qtd' => $f['qtd']], $faixas);

        // ── maiores devedores / a receber por cliente ──
        $porCliente = $abertos->groupBy('cliente_id')->map(function ($itens, $clienteId) use ($hojeStr) {
            return [
                'cliente_id' => $clienteId,
                'em_aberto'  => round((float) $itens->sum('valor'), 2),
                'em_atraso'  => round((float) $itens->filter(fn ($l) => $l->data_vencimento < $hojeStr)->sum('valor'), 2),
                'qtd'        => $itens->count(),
            ];
        })->sortByDesc('em_aberto')->take(5)->values();

        $nomesClientes = Cliente::query()->whereIn('id', $porCliente->pluck('cliente_id')->filter())->pluck('nome', 'id');
        $topClientes = $porCliente->map(fn ($c) => $c + ['nome' => $nomesClientes[$c['cliente_id']] ?? 'Cliente não informado'])->all();

        // ── próximos recebimentos (atrasados primeiro, depois por vencimento) ──
        $selecionados = $this->mesclarVencidosEProximos($abertos);
        $nomesProx = Cliente::query()->whereIn('id', $selecionados->pluck('cliente_id')->filter())->pluck('nome', 'id');
        $proximos = $selecionados->map(fn ($l) => [
            'id'         => $l->id,
            'cliente'    => $nomesProx[$l->cliente_id] ?? ($l->descricao ?: 'Cliente não informado'),
            'valor'      => round((float) $l->valor, 2),
            'vencimento' => $l->data_vencimento,
            'dias'       => (int) $this->hoje->diffInDays(Carbon::parse($l->data_vencimento), false),
            'parcela'    => $l->total_parcelas > 1 ? "{$l->numero_parcela}/{$l->total_parcelas}" : null,
        ])->values()->all();

        return [
            'kpis' => [
                'em_aberto'          => round((float) $abertos->sum('valor'), 2),
                'qtd_em_aberto'      => $abertos->count(),
                'em_atraso'          => round($emAtraso, 2),
                'qtd_em_atraso'      => $vencidos->count(),
                'maior_atraso_dias'  => $maiorAtraso,
                'a_vencer_7d'        => round((float) $aVencer->filter(fn ($l) => $l->data_vencimento <= $limite7)->sum('valor'), 2),
                'a_vencer_30d'       => round((float) $aVencer->filter(fn ($l) => $l->data_vencimento <= $limite30)->sum('valor'), 2),
                'inadimplencia_pct'  => $inadimplencia,
                'mes' => [
                    'previsto' => round($mes['previsto'], 2),
                    'recebido' => round($mes['recebido'], 2),
                    'atrasado' => round($mes['atrasado'], 2),
                    'a_vencer' => round($mes['a_vencer'], 2),
                    'pct_recebido' => $mes['previsto'] > 0 ? round($mes['recebido'] / $mes['previsto'] * 100, 1) : 0.0,
                ],
                'mes_anterior' => [
                    'previsto' => round($mesAnt['previsto'], 2),
                    'recebido' => round($mesAnt['recebido'], 2),
                ],
            ],
            'evolucao'     => $evolucao,
            'aging'        => $aging,
            'top_clientes' => $topClientes,
            'proximos'     => $proximos,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Despesas
    // ─────────────────────────────────────────────────────────────────────

    private function despesas(Collection $abertos, ?float $receitaPrevistaMes): array
    {
        $janela = $this->janela('saida');
        $hojeStr = $this->hoje->toDateString();
        $mesAtual = $this->hoje->format('Y-m');
        $mesAnterior = $this->hoje->copy()->subMonth()->format('Y-m');

        $serie = [];
        foreach ($this->chavesMeses() as $ym) {
            $serie[$ym] = ['mes' => $ym, 'total' => 0.0, 'pago' => 0.0, 'atrasado' => 0.0, 'a_pagar' => 0.0, 'fixas' => 0.0];
        }
        foreach ($janela as $l) {
            $ym = substr($l->data_vencimento, 0, 7);
            if (!isset($serie[$ym])) {
                continue;
            }
            $valor = (float) $l->valor;
            $serie[$ym]['total'] += $valor;
            if ($l->conta_pagar_id) {
                $serie[$ym]['fixas'] += $valor; // vindas de despesas recorrentes
            }
            if ($l->status === 'pago') {
                $serie[$ym]['pago'] += (float) ($l->valor_pago ?? $l->valor);
            } elseif ($l->data_vencimento < $hojeStr) {
                $serie[$ym]['atrasado'] += $valor;
            } else {
                $serie[$ym]['a_pagar'] += $valor;
            }
        }

        // média móvel de 3 meses (usa só meses até o atual; futuros ficam nulos)
        $chaves = array_keys($serie);
        $evolucao = [];
        foreach ($chaves as $i => $ym) {
            $linha = $this->arredondar($serie[$ym]);
            if ($ym <= $mesAtual) {
                $janela3 = array_slice(array_column($serie, 'total'), max(0, $i - 2), min(3, $i + 1));
                $linha['media_movel'] = round(array_sum($janela3) / max(1, count($janela3)), 2);
            } else {
                $linha['media_movel'] = null;
            }
            $evolucao[] = $linha;
        }

        $mes = $serie[$mesAtual] ?? ['total' => 0, 'pago' => 0, 'atrasado' => 0, 'a_pagar' => 0, 'fixas' => 0];
        $totalAnterior = $serie[$mesAnterior]['total'] ?? 0;

        // médias de meses completos anteriores (ignora meses sem lançamento nenhum)
        $anteriores = [];
        foreach ($serie as $ym => $s) {
            if ($ym < $mesAtual) {
                $anteriores[] = $s['total'];
            }
        }
        $media = function (int $n) use ($anteriores) {
            $recorte = array_slice($anteriores, -$n);
            $recorte = array_filter($recorte, fn ($v) => $v > 0);
            return count($recorte) ? round(array_sum($recorte) / count($recorte), 2) : 0.0;
        };
        $media3 = $media(3);
        $media6 = $media(6);

        $vencidos = $abertos->filter(fn ($l) => $l->data_vencimento < $hojeStr);
        $aVencer = $abertos->filter(fn ($l) => $l->data_vencimento >= $hojeStr);
        $limite7 = $this->hoje->copy()->addDays(7)->toDateString();
        $limite30 = $this->hoje->copy()->addDays(30)->toDateString();

        // ── por categoria (mês atual × anterior) ──
        $nomesCat = Categoria::query()->pluck('nome', 'id');
        $agrupar = function (string $ym) use ($janela) {
            return $janela->filter(fn ($l) => substr($l->data_vencimento, 0, 7) === $ym)
                ->groupBy(fn ($l) => $l->categoria_id ?: 0)
                ->map(fn ($itens) => round((float) $itens->sum('valor'), 2));
        };
        $catAtual = $agrupar($mesAtual);
        $catAnterior = $agrupar($mesAnterior);
        $totalMes = (float) $catAtual->sum();

        $porCategoria = $catAtual->map(function ($valor, $catId) use ($nomesCat, $catAnterior, $totalMes) {
            $anterior = (float) ($catAnterior[$catId] ?? 0);
            return [
                'categoria_id' => $catId ?: null,
                'nome'         => $catId ? ($nomesCat[$catId] ?? 'Categoria removida') : 'Sem categoria',
                'valor'        => $valor,
                'perc'         => $totalMes > 0 ? round($valor / $totalMes * 100, 1) : 0.0,
                'anterior'     => $anterior,
                'variacao_pct' => $anterior > 0 ? round(($valor - $anterior) / $anterior * 100, 1) : null,
            ];
        })->sortByDesc('valor')->values()->all();

        // ── top fornecedores (3 meses até hoje) ──
        $desde = $this->hoje->copy()->subMonths(2)->startOfMonth()->toDateString();
        $topFornecedores = $janela
            ->filter(fn ($l) => $l->data_vencimento >= $desde && $l->data_vencimento <= $this->hoje->copy()->endOfMonth()->toDateString())
            ->groupBy(fn ($l) => trim((string) $l->fornecedor) !== '' ? trim($l->fornecedor) : 'Sem fornecedor informado')
            ->map(fn ($itens, $nome) => ['nome' => $nome, 'valor' => round((float) $itens->sum('valor'), 2), 'qtd' => $itens->count()])
            ->sortByDesc('valor')->take(5)->values()->all();

        // ── maiores despesas do mês ──
        $maiores = $janela
            ->filter(fn ($l) => substr($l->data_vencimento, 0, 7) === $mesAtual)
            ->sortByDesc('valor')->take(5)
            ->map(fn ($l) => [
                'id'         => $l->id,
                'descricao'  => $l->descricao ?: 'Sem descrição',
                'fornecedor' => $l->fornecedor,
                'valor'      => round((float) $l->valor, 2),
                'vencimento' => $l->data_vencimento,
                'status'     => $l->status === 'pago' ? 'pago' : ($l->data_vencimento < $hojeStr ? 'atrasado' : 'pendente'),
            ])->values()->all();

        // ── próximas contas a pagar ──
        $proximas = $this->mesclarVencidosEProximos($abertos)->map(fn ($l) => [
            'id'         => $l->id,
            'descricao'  => $l->descricao ?: 'Sem descrição',
            'fornecedor' => $l->fornecedor,
            'categoria'  => $l->categoria_id ? ($nomesCat[$l->categoria_id] ?? null) : null,
            'valor'      => round((float) $l->valor, 2),
            'vencimento' => $l->data_vencimento,
            'dias'       => (int) $this->hoje->diffInDays(Carbon::parse($l->data_vencimento), false),
        ])->values()->all();

        $totalMesArred = round($mes['total'], 2);

        return [
            'kpis' => [
                'mes' => [
                    'total'    => $totalMesArred,
                    'pago'     => round($mes['pago'], 2),
                    'a_pagar'  => round($mes['a_pagar'], 2),
                    'atrasado' => round($mes['atrasado'], 2),
                    'fixas'    => round($mes['fixas'], 2),
                    'fixas_pct' => $mes['total'] > 0 ? round($mes['fixas'] / $mes['total'] * 100, 1) : 0.0,
                ],
                'mes_anterior_total' => round($totalAnterior, 2),
                'variacao_pct'       => $totalAnterior > 0 ? round(($mes['total'] - $totalAnterior) / $totalAnterior * 100, 1) : null,
                'media_3m'           => $media3,
                'media_6m'           => $media6,
                'vs_media_3m_pct'    => $media3 > 0 ? round(($mes['total'] - $media3) / $media3 * 100, 1) : null,
                'em_atraso'          => round((float) $vencidos->sum('valor'), 2),
                'qtd_em_atraso'      => $vencidos->count(),
                'a_pagar_7d'         => round((float) $aVencer->filter(fn ($l) => $l->data_vencimento <= $limite7)->sum('valor'), 2),
                'a_pagar_30d'        => round((float) $aVencer->filter(fn ($l) => $l->data_vencimento <= $limite30)->sum('valor'), 2),
                'comprometimento_pct' => ($receitaPrevistaMes ?? 0) > 0 ? round($mes['total'] / $receitaPrevistaMes * 100, 1) : null,
                'resultado_projetado' => $receitaPrevistaMes !== null ? round($receitaPrevistaMes - $mes['total'], 2) : null,
            ],
            'evolucao'         => $evolucao,
            'por_categoria'    => $porCategoria,
            'top_fornecedores' => $topFornecedores,
            'maiores'          => $maiores,
            'proximas'         => $proximas,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fluxo de caixa projetado (próximos 30 dias, em faixas)
    // ─────────────────────────────────────────────────────────────────────

    private function fluxoCaixa(Collection $entradas, Collection $saidas): array
    {
        $hojeStr = $this->hoje->toDateString();

        $faixas = [
            ['rotulo' => 'Em atraso', 'ini' => null, 'fim' => $this->hoje->copy()->subDay()->toDateString()],
        ];
        foreach ([[0, 6], [7, 13], [14, 20], [21, 30]] as [$a, $b]) {
            $ini = $this->hoje->copy()->addDays($a);
            $fim = $this->hoje->copy()->addDays($b);
            $faixas[] = [
                'rotulo' => $ini->format('d/m') . ' – ' . $fim->format('d/m'),
                'ini' => $ini->toDateString(),
                'fim' => $fim->toDateString(),
            ];
        }

        $somar = function (Collection $c, ?string $ini, string $fim) {
            return round((float) $c->filter(fn ($l) => ($ini === null || $l->data_vencimento >= $ini) && $l->data_vencimento <= $fim)->sum('valor'), 2);
        };

        $acumulado = 0.0;
        $linhas = [];
        foreach ($faixas as $f) {
            $in = $somar($entradas, $f['ini'], $f['fim']);
            $out = $somar($saidas, $f['ini'], $f['fim']);
            $saldo = round($in - $out, 2);
            $acumulado = round($acumulado + $saldo, 2);
            $linhas[] = ['rotulo' => $f['rotulo'], 'entradas' => $in, 'saidas' => $out, 'saldo' => $saldo, 'acumulado' => $acumulado];
        }

        return [
            'faixas' => $linhas,
            'saldo_30d' => $acumulado,
            'total_entradas' => round(array_sum(array_column($linhas, 'entradas')), 2),
            'total_saidas' => round(array_sum(array_column($linhas, 'saidas')), 2),
            'tem_buraco' => collect($linhas)->contains(fn ($l) => $l['acumulado'] < 0),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Vendas (faturamento)
    // ─────────────────────────────────────────────────────────────────────

    private function vendas(): array
    {
        $inicio = $this->inicioJanela()->toDateString();
        $rows = Venda::query()
            ->where('data_venda', '>=', $inicio)
            ->toBase()
            ->get(['total', 'data_venda']);

        $serie = [];
        foreach ($this->chavesMeses() as $ym) {
            if ($ym <= $this->hoje->format('Y-m')) {
                $serie[$ym] = ['mes' => $ym, 'faturamento' => 0.0, 'qtd' => 0];
            }
        }
        foreach ($rows as $v) {
            $ym = substr((string) $v->data_venda, 0, 7);
            if (isset($serie[$ym])) {
                $serie[$ym]['faturamento'] += (float) $v->total;
                $serie[$ym]['qtd']++;
            }
        }
        $serie = array_values(array_map(function ($m) {
            $m['faturamento'] = round($m['faturamento'], 2);
            $m['ticket_medio'] = $m['qtd'] > 0 ? round($m['faturamento'] / $m['qtd'], 2) : 0.0;
            return $m;
        }, $serie));

        $atual = $serie[count($serie) - 1] ?? ['faturamento' => 0, 'qtd' => 0, 'ticket_medio' => 0];
        $anterior = $serie[count($serie) - 2] ?? ['faturamento' => 0, 'qtd' => 0, 'ticket_medio' => 0];

        return [
            'evolucao' => $serie,
            'mes' => $atual,
            'mes_anterior' => $anterior,
            'variacao_pct' => $anterior['faturamento'] > 0
                ? round(($atual['faturamento'] - $anterior['faturamento']) / $anterior['faturamento'] * 100, 1)
                : null,
        ];
    }

    /**
     * Lista curta e útil: até 4 vencidos (os mais antigos) + os próximos a vencer,
     * para uma pilha de atrasos antigos não esconder o que vem pela frente.
     */
    private function mesclarVencidosEProximos(Collection $abertos, int $limite = 8): Collection
    {
        $hojeStr = $this->hoje->toDateString();
        $ordenados = $abertos->sortBy('data_vencimento')->values();
        $vencidos = $ordenados->filter(fn ($l) => $l->data_vencimento < $hojeStr)->take((int) ($limite / 2));
        $proximos = $ordenados->filter(fn ($l) => $l->data_vencimento >= $hojeStr)->take($limite - $vencidos->count());
        // se faltarem próximos, completa com mais vencidos
        $faltam = $limite - $vencidos->count() - $proximos->count();
        if ($faltam > 0) {
            $vencidos = $ordenados->filter(fn ($l) => $l->data_vencimento < $hojeStr)->take($vencidos->count() + $faltam);
        }
        return $vencidos->concat($proximos)->values();
    }

    private function arredondar(array $linha): array
    {
        foreach ($linha as $k => $v) {
            if (is_float($v)) {
                $linha[$k] = round($v, 2);
            }
        }
        return $linha;
    }
}
