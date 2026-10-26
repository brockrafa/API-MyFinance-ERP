<?php

namespace App\Services;

use App\Models\AjusteCustoEstoque;
use App\Models\EntradaEstoque;
use App\Models\Estoque;
use App\Models\EstoqueSaldo;
use App\Models\ItemEntradaEstoque;
use App\Models\ItemTransferenciaEstoque;
use App\Models\ItemVenda;
use App\Models\MovimentoEstoque;
use App\Models\TransferenciaEstoque;
use App\Models\Venda;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correção retroativa do custo de uma entrada de estoque.
 *
 * O estoque usa custo médio. Para corrigir o custo de uma entrada, o histórico do produto é
 * refeito na ordem em que os movimentos aconteceram (todas as entradas, saídas, transferências
 * e estornos, em todos os estoques), trocando só o custo da entrada corrigida. Assim o custo médio
 * e o custo de cada saída posterior ficam como teriam sido se a entrada tivesse sido lançada certa.
 *
 * Nada é apagado: as saídas e os saldos são ajustados e fica uma linha em ajustes_custo_estoque.
 * As vendas que o usuário não escolher reprecificar mantêm o custo que já tinham.
 */
class CustoEstoqueService
{
    /** Diferença mínima de custo (R$) para considerar que um movimento mudou. */
    private const TOLERANCIA = 0.0001;

    /** Diferença aceita entre o custo médio refeito e o salvo, por arredondamentos antigos. */
    private const TOLERANCIA_CONFERENCIA = 0.01;

    /** Dados atuais da entrada para preencher o formulário de correção. */
    public function detalhes(MovimentoEstoque $movimento): array
    {
        $this->validarElegivel($movimento);

        $historico = $this->historico($movimento->produto_id);
        $entradas = $this->dadosDasEntradas($historico);
        $dados = $entradas[$movimento->id] ?? null;
        $quantidade = (int) $movimento->quantidade;
        $totalAtual = $this->totalDaEntrada($movimento, $dados);

        return [
            'movimento_id' => $movimento->id,
            'produto_id' => $movimento->produto_id,
            'estoque_id' => $movimento->estoque_id,
            'quantidade' => $quantidade,
            'data' => $this->dataDaEntrada($movimento, $dados)->toDateString(),
            // Sem item de entrada (saldo inicial antigo) só existe o custo unitário já com tudo incluído
            'tem_item' => $dados !== null,
            'custo_unitario' => $dados ? round((float) $dados['custo_unitario'], 4) : round((float) $movimento->custo_unitario, 4),
            'frete_item' => $dados ? round((float) $dados['frete_rateado'], 4) : 0.0,
            'custo_unitario_com_frete' => $quantidade > 0 ? round($totalAtual / $quantidade, 4) : 0.0,
        ];
    }

    /** Mostra o que mudaria, sem gravar nada. */
    public function prever(MovimentoEstoque $entrada, float $novoCusto, ?float $novoFrete = null): array
    {
        $calculo = $this->calcular($entrada, $novoCusto, $novoFrete);

        return $this->montarPrevia($calculo);
    }

    /**
     * Aplica a correção. $vendaIds são as vendas que devem ter o custo refeito; as demais vendas
     * afetadas mantêm o custo que já tinham (o custo médio do estoque é sempre corrigido).
     */
    public function aplicar(MovimentoEstoque $entrada, float $novoCusto, ?float $novoFrete, string $motivo, array $vendaIds): array
    {
        return DB::transaction(function () use ($entrada, $novoCusto, $novoFrete, $motivo, $vendaIds): array {
            // Trava os saldos do produto para ninguém movimentar enquanto o histórico é refeito
            EstoqueSaldo::where('produto_id', $entrada->produto_id)->lockForUpdate()->get();

            $calculo = $this->calcular($entrada->fresh(), $novoCusto, $novoFrete);
            $afetadas = array_keys($calculo['vendas_afetadas']);
            $escolhidas = array_values(array_unique(array_map('intval', $vendaIds)));

            $invalidas = array_diff($escolhidas, $afetadas);
            if ($invalidas) {
                throw ValidationException::withMessages([
                    'vendas' => 'A venda ' . implode(', ', $invalidas) . ' não é afetada por esta correção.',
                ]);
            }

            $this->gravarEntrada($calculo);
            $atualizados = $this->gravarCustosDosMovimentos($calculo, $escolhidas);
            $this->reprecificarItensDeVenda($calculo, $escolhidas);
            $this->atualizarSaldos($calculo);

            AjusteCustoEstoque::create([
                'movimento_estoque_id' => $entrada->id,
                'estoque_id' => $entrada->estoque_id,
                'produto_id' => $entrada->produto_id,
                'usuario_id' => Auth::id(),
                'custo_anterior' => $calculo['custo_anterior_unitario'],
                'custo_novo' => $calculo['custo_novo_unitario'],
                'motivo' => $motivo,
                'vendas_reprecificadas' => count($escolhidas),
                'vendas_mantidas' => count($afetadas) - count($escolhidas),
            ]);

            return [
                'custo_anterior' => $calculo['custo_anterior_unitario'],
                'custo_novo' => $calculo['custo_novo_unitario'],
                'vendas_reprecificadas' => count($escolhidas),
                'vendas_mantidas' => count($afetadas) - count($escolhidas),
                'movimentos_atualizados' => $atualizados,
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cálculo
    // ─────────────────────────────────────────────────────────────────────────

    private function calcular(MovimentoEstoque $entrada, float $novoCusto, ?float $novoFrete): array
    {
        $this->validarElegivel($entrada);

        if ($novoCusto <= 0) {
            throw ValidationException::withMessages(['custo_unitario' => 'Informe um custo unitário maior que zero.']);
        }

        $historico = $this->historico($entrada->produto_id);
        $entradas = $this->dadosDasEntradas($historico);
        $transferencias = $this->dadosDasTransferencias($historico);
        $dados = $entradas[$entrada->id] ?? null;

        $this->conferirHistorico($historico, $entradas, $transferencias, $entrada->produto_id);

        $freteAtual = $dados ? (float) $dados['frete_rateado'] : 0.0;
        $freteNovo = $dados ? ($novoFrete ?? $freteAtual) : 0.0;
        $quantidade = (int) $entrada->quantidade;

        $totalAtual = $this->totalDaEntrada($entrada, $dados);
        $totalNovo = $quantidade * $novoCusto + $freteNovo;

        $base = $this->reproduzir($historico, $entradas, $transferencias, []);
        $novo = $this->reproduzir($historico, $entradas, $transferencias, [$entrada->id => $totalNovo]);

        return [
            'entrada' => $entrada,
            'dados_entrada' => $dados,
            'historico' => $historico,
            'novo_custo' => $novoCusto,
            'frete_atual' => $freteAtual,
            'frete_novo' => $freteNovo,
            'custo_anterior_unitario' => round($totalAtual / $quantidade, 4),
            'custo_novo_unitario' => round($totalNovo / $quantidade, 4),
            'base' => $base,
            'novo' => $novo,
            'vendas_afetadas' => $this->agruparVendasAfetadas($historico, $base, $novo),
        ];
    }

    /**
     * Refaz o histórico do produto. Replica exatamente o que o EstoqueService faz em cada operação,
     * para que, sem correção, o resultado seja igual ao saldo gravado.
     *
     * @param array<int,float> $totais  custo total (com frete) a usar em entradas específicas
     * @return array{custos: array<int,float>, estados: array<int,array{qtd:int,custo:float}>}
     */
    private function reproduzir(Collection $historico, array $entradas, array $transferencias, array $totais): array
    {
        $estados = [];
        $custos = [];
        $custoNaSaida = []; // [transferencia_id] => custo médio da origem no momento da saída

        foreach ($historico as $mov) {
            /** @var MovimentoEstoque $mov */
            $estado = $estados[$mov->estoque_id] ?? ['qtd' => 0, 'custo' => 0.0];
            $quantidade = (int) $mov->quantidade;

            switch ($mov->tipo) {
                case 'entrada':
                case 'saldo_inicial':
                    $total = $totais[$mov->id] ?? $this->totalDaEntrada($mov, $entradas[$mov->id] ?? null);
                    $custos[$mov->id] = $total / $quantidade;
                    $estado = $this->somarEntrada($estado, $quantidade, $total);
                    break;

                case 'transferencia_entrada':
                    $base = $custoNaSaida[$mov->origem_id] ?? (float) $mov->custo_unitario;
                    $unitario = $base + (($transferencias[$mov->id]['frete_rateado'] ?? 0.0) / $quantidade);
                    $custos[$mov->id] = $unitario;
                    $estado = $this->somarEntrada($estado, $quantidade, $quantidade * $unitario);
                    break;

                case 'transferencia_saida':
                    $custos[$mov->id] = $estado['custo'];
                    $custoNaSaida[$mov->origem_id] = $estado['custo'];
                    $estado['qtd'] += $quantidade;
                    break;

                case 'estorno':
                    // O estorno mantém o custo do movimento original e não mexe no custo médio
                    $custos[$mov->id] = $custos[$mov->origem_id] ?? (float) $mov->custo_unitario;
                    $estado['qtd'] += $quantidade;
                    break;

                default: // venda, baixa_manual
                    $custos[$mov->id] = $estado['custo'];
                    $estado['qtd'] += $quantidade;
                    break;
            }

            $estados[$mov->estoque_id] = $estado;
        }

        return ['custos' => $custos, 'estados' => $estados];
    }

    private function somarEntrada(array $estado, int $quantidade, float $total): array
    {
        $novaQuantidade = $estado['qtd'] + $quantidade;

        return [
            'qtd' => $novaQuantidade,
            'custo' => $novaQuantidade > 0 ? round(($estado['qtd'] * $estado['custo'] + $total) / $novaQuantidade, 4) : $estado['custo'],
        ];
    }

    /** Custo total (com frete) de uma entrada, como foi usado no cálculo do custo médio. */
    private function totalDaEntrada(MovimentoEstoque $mov, ?array $dados): float
    {
        $quantidade = (int) $mov->quantidade;

        if ($dados) {
            return $quantidade * (float) $dados['custo_unitario'] + (float) $dados['frete_rateado'];
        }

        return $quantidade * (float) $mov->custo_unitario;
    }

    private function dataDaEntrada(MovimentoEstoque $mov, ?array $dados)
    {
        return $dados && $dados['data'] ? $dados['data'] : $mov->movimentado_em->copy()->startOfDay();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Leitura do histórico
    // ─────────────────────────────────────────────────────────────────────────

    private function historico(int $produtoId): Collection
    {
        return MovimentoEstoque::query()
            ->where('produto_id', $produtoId)
            ->orderBy('movimentado_em')
            ->orderBy('id')
            ->get();
    }

    /** Liga cada movimento de entrada ao seu item (custo e frete) pela ordem de criação. */
    private function dadosDasEntradas(Collection $historico): array
    {
        $movimentos = $historico->where('tipo', 'entrada')->where('origem_tipo', EntradaEstoque::class);
        if ($movimentos->isEmpty()) {
            return [];
        }

        $entradas = EntradaEstoque::whereIn('id', $movimentos->pluck('origem_id')->unique())->get()->keyBy('id');
        $itens = ItemEntradaEstoque::whereIn('entrada_estoque_id', $entradas->keys())
            ->where('produto_id', $movimentos->first()->produto_id)
            ->orderBy('id')
            ->get()
            ->groupBy('entrada_estoque_id');

        $resultado = [];
        foreach ($movimentos->groupBy('origem_id') as $entradaId => $doGrupo) {
            $itensDaEntrada = $itens->get($entradaId, collect())->values();
            foreach ($doGrupo->sortBy('id')->values() as $indice => $mov) {
                $item = $itensDaEntrada->get($indice);
                if ($item) {
                    $resultado[$mov->id] = [
                        'item' => $item,
                        'entrada' => $entradas->get($entradaId),
                        'custo_unitario' => (float) $item->custo_unitario,
                        'frete_rateado' => (float) $item->frete_rateado,
                        'data' => $entradas->get($entradaId)?->data_entrada?->copy()->startOfDay(),
                    ];
                }
            }
        }

        return $resultado;
    }

    private function dadosDasTransferencias(Collection $historico): array
    {
        $movimentos = $historico->where('tipo', 'transferencia_entrada')->where('origem_tipo', TransferenciaEstoque::class);
        if ($movimentos->isEmpty()) {
            return [];
        }

        $itens = ItemTransferenciaEstoque::whereIn('transferencia_estoque_id', $movimentos->pluck('origem_id')->unique())
            ->where('produto_id', $movimentos->first()->produto_id)
            ->orderBy('id')
            ->get()
            ->groupBy('transferencia_estoque_id');

        $resultado = [];
        foreach ($movimentos->groupBy('origem_id') as $transferenciaId => $doGrupo) {
            $itensDaTransferencia = $itens->get($transferenciaId, collect())->values();
            foreach ($doGrupo->sortBy('id')->values() as $indice => $mov) {
                $item = $itensDaTransferencia->get($indice);
                $resultado[$mov->id] = ['frete_rateado' => $item ? (float) $item->frete_rateado : 0.0];
            }
        }

        return $resultado;
    }

    /** Recusa a correção se o histórico refeito não reproduz o saldo atual (dados antigos inconsistentes). */
    private function conferirHistorico(Collection $historico, array $entradas, array $transferencias, int $produtoId): void
    {
        $refeito = $this->reproduzir($historico, $entradas, $transferencias, [])['estados'];

        foreach (EstoqueSaldo::where('produto_id', $produtoId)->get() as $saldo) {
            $estado = $refeito[$saldo->estoque_id] ?? ['qtd' => 0, 'custo' => 0.0];
            $quantidadeDiverge = (int) $saldo->quantidade !== $estado['qtd'];
            $custoDiverge = (int) $saldo->quantidade > 0
                && abs((float) $saldo->custo_medio - $estado['custo']) > self::TOLERANCIA_CONFERENCIA;

            if ($quantidadeDiverge || $custoDiverge) {
                throw ValidationException::withMessages([
                    'movimento' => 'O histórico deste produto não bate com o saldo atual do estoque, '
                        . 'então o custo não pode ser recalculado com segurança. Confira os saldos antes de corrigir.',
                ]);
            }
        }
    }

    private function validarElegivel(MovimentoEstoque $mov): void
    {
        $ehEntrada = in_array($mov->tipo, ['entrada', 'saldo_inicial'], true) && (int) $mov->quantidade > 0;

        if (!$ehEntrada) {
            throw ValidationException::withMessages(['movimento' => 'Só é possível corrigir o custo de movimentos de entrada.']);
        }

        if ($mov->estaEstornado()) {
            throw ValidationException::withMessages(['movimento' => 'Esta entrada foi estornada e não pode ser corrigida.']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resultado e gravação
    // ─────────────────────────────────────────────────────────────────────────

    /** Vendas cujo custo muda: [venda_id => [custo_atual, custo_novo, movimentos...]] */
    private function agruparVendasAfetadas(Collection $historico, array $base, array $novo): array
    {
        $afetadas = [];

        foreach ($historico->where('tipo', 'venda')->whereNull('estornado_em') as $mov) {
            $antes = $base['custos'][$mov->id] ?? (float) $mov->custo_unitario;
            $depois = $novo['custos'][$mov->id];

            if (abs($depois - $antes) <= self::TOLERANCIA) {
                continue;
            }

            $quantidade = abs((int) $mov->quantidade);
            $afetadas[$mov->origem_id]['movimentos'][] = $mov->id;
            $afetadas[$mov->origem_id]['delta'] = ($afetadas[$mov->origem_id]['delta'] ?? 0.0) + $quantidade * ($depois - (float) $mov->custo_unitario);
        }

        return $afetadas;
    }

    private function montarPrevia(array $calculo): array
    {
        /** @var MovimentoEstoque $entrada */
        $entrada = $calculo['entrada'];
        $historico = $calculo['historico'];
        $dataEntrada = $this->dataDaEntrada($entrada, $calculo['dados_entrada']);

        $vendas = Venda::query()
            ->with(['cliente:id,nome', 'itens'])
            ->whereIn('id', array_keys($calculo['vendas_afetadas']))
            ->orderBy('data_venda')->orderBy('id')
            ->get()
            ->map(function (Venda $venda) use ($calculo, $dataEntrada) {
                $delta = round($calculo['vendas_afetadas'][$venda->id]['delta'], 2);
                $resumo = $venda->resumoFinanceiro();
                $antesDaEntrada = $venda->data_venda < $dataEntrada->toDateString();

                return [
                    'venda_id' => $venda->id,
                    'data_venda' => (string) $venda->data_venda,
                    'cliente' => $venda->cliente?->nome,
                    'canal' => $resumo['canal'],
                    'total' => $resumo['total'],
                    'custo_atual' => $resumo['custo'],
                    'custo_novo' => round($resumo['custo'] + $delta, 2),
                    'margem_atual' => $resumo['margem'],
                    'margem_nova' => round($resumo['margem'] - $delta, 2),
                    'antes_da_entrada' => $antesDaEntrada,
                    // A venda com data anterior à entrada provavelmente foi lançada depois, de forma retroativa
                    'selecionada_padrao' => !$antesDaEntrada,
                ];
            })
            ->values();

        $saldos = EstoqueSaldo::where('produto_id', $entrada->produto_id)->get()->keyBy('estoque_id');
        $nomes = Estoque::whereIn('id', array_keys($calculo['novo']['estados']))->pluck('nome', 'id');

        $estoques = [];
        foreach ($calculo['novo']['estados'] as $estoqueId => $estado) {
            $antes = $calculo['base']['estados'][$estoqueId]['custo'] ?? 0.0;
            if ($estado['qtd'] <= 0 && abs($estado['custo'] - $antes) <= self::TOLERANCIA) {
                continue;
            }

            $estoques[] = [
                'estoque_id' => $estoqueId,
                'nome' => $nomes->get($estoqueId),
                'quantidade' => $estado['qtd'],
                'custo_medio_atual' => round((float) ($saldos->get($estoqueId)?->custo_medio ?? $antes), 4),
                'custo_medio_novo' => round($estado['custo'], 4),
            ];
        }

        $outros = $historico->filter(fn (MovimentoEstoque $mov) => in_array($mov->tipo, ['baixa_manual', 'transferencia_saida', 'transferencia_entrada'], true)
            && abs($calculo['novo']['custos'][$mov->id] - ($calculo['base']['custos'][$mov->id] ?? 0)) > self::TOLERANCIA)->count();

        return [
            'movimento_id' => $entrada->id,
            'produto' => $entrada->produto?->produto,
            'quantidade' => (int) $entrada->quantidade,
            'data_entrada' => $dataEntrada->toDateString(),
            'custo_anterior' => $calculo['custo_anterior_unitario'],
            'custo_novo' => $calculo['custo_novo_unitario'],
            'sem_alteracao' => abs($calculo['custo_anterior_unitario'] - $calculo['custo_novo_unitario']) <= self::TOLERANCIA,
            'estoques' => $estoques,
            'vendas' => $vendas->all(),
            'outros_movimentos_afetados' => $outros,
        ];
    }

    private function gravarEntrada(array $calculo): void
    {
        /** @var MovimentoEstoque $entrada */
        $entrada = $calculo['entrada'];
        $dados = $calculo['dados_entrada'];

        if ($dados) {
            $dados['item']->update([
                'custo_unitario' => $calculo['novo_custo'],
                'frete_rateado' => $calculo['frete_novo'],
            ]);

            $diferencaFrete = $calculo['frete_novo'] - $calculo['frete_atual'];
            if (abs($diferencaFrete) > self::TOLERANCIA) {
                $dados['entrada']->update(['frete_total' => (float) $dados['entrada']->frete_total + $diferencaFrete]);
            }
        }
    }

    /**
     * Grava o custo refeito nos movimentos. Vendas ainda vivas que o usuário não escolheu mantêm o custo.
     *
     * @return int quantidade de movimentos atualizados
     */
    private function gravarCustosDosMovimentos(array $calculo, array $vendasEscolhidas): int
    {
        $atualizados = 0;
        $entradaId = $calculo['entrada']->id;

        foreach ($calculo['historico'] as $mov) {
            $novoCusto = $calculo['novo']['custos'][$mov->id];
            $mudou = abs($novoCusto - (float) $mov->custo_unitario) > self::TOLERANCIA;

            if (!$mudou && $mov->id !== $entradaId) {
                continue;
            }

            $vendaViva = $mov->tipo === 'venda' && $mov->estornado_em === null;
            if ($vendaViva && !in_array((int) $mov->origem_id, $vendasEscolhidas, true)) {
                continue;
            }

            $mov->update([
                'custo_unitario' => $novoCusto,
                'custo_total' => $mov->quantidade * $novoCusto,
            ]);
            $atualizados++;

            if ($mov->tipo === 'transferencia_saida') {
                ItemTransferenciaEstoque::where('transferencia_estoque_id', $mov->origem_id)
                    ->where('produto_id', $mov->produto_id)
                    ->update(['custo_unitario' => $novoCusto]);
            }
        }

        return $atualizados;
    }

    private function reprecificarItensDeVenda(array $calculo, array $vendasEscolhidas): void
    {
        foreach ($calculo['historico']->where('tipo', 'venda')->whereNull('estornado_em') as $mov) {
            if (!in_array((int) $mov->origem_id, $vendasEscolhidas, true)) {
                continue;
            }

            $novoCusto = $calculo['novo']['custos'][$mov->id];

            ItemVenda::where('venda_id', $mov->origem_id)->where('produto_id', $mov->produto_id)->get()
                ->each(fn (ItemVenda $item) => $item->update([
                    'custo_unitario' => $novoCusto,
                    'custo_total' => $item->quantidade * $novoCusto,
                ]));
        }
    }

    /** O custo médio do saldo muda só onde a correção mudou o resultado. */
    private function atualizarSaldos(array $calculo): void
    {
        foreach ($calculo['novo']['estados'] as $estoqueId => $estado) {
            $antes = $calculo['base']['estados'][$estoqueId]['custo'] ?? 0.0;

            if (abs($estado['custo'] - $antes) <= self::TOLERANCIA) {
                continue;
            }

            EstoqueSaldo::where('estoque_id', $estoqueId)
                ->where('produto_id', $calculo['entrada']->produto_id)
                ->update(['custo_medio' => $estado['custo']]);
        }
    }
}
