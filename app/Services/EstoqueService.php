<?php

namespace App\Services;

use App\Models\EntradaEstoque;
use App\Models\Estoque;
use App\Models\EstoqueSaldo;
use App\Models\ItemEntradaEstoque;
use App\Models\ItemTransferenciaEstoque;
use App\Models\MovimentoEstoque;
use App\Models\Produto;
use App\Models\TransferenciaEstoque;
use App\Models\Venda;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EstoqueService
{
    public function registrarSaida(Estoque $estoque, array $data): void
    {
        DB::transaction(function () use ($estoque, $data): void {
            foreach ($data['itens'] as $itemData) {
                $produto = Produto::find($itemData['produto_id']);

                if (!$produto) {
                    throw ValidationException::withMessages([
                        'itens' => "Produto {$itemData['produto_id']} não pertence à empresa atual.",
                    ]);
                }

                $saldo = $this->obterSaldoComLock($estoque, $produto);
                if ($saldo->quantidade < $itemData['quantidade']) {
                    throw ValidationException::withMessages([
                        'itens' => "Saldo insuficiente para o produto {$produto->produto}.",
                    ]);
                }

                $custoUnitario = (float) $saldo->custo_medio;
                $saldo->decrement('quantidade', $itemData['quantidade']);

                $this->registrarMovimento(
                    estoque: $estoque,
                    produto: $produto,
                    tipo: 'baixa_manual',
                    quantidade: -$itemData['quantidade'],
                    custoUnitario: $custoUnitario,
                    origemTipo: 'baixa_manual',
                    origemId: null,
                    observacao: $data['observacao'] ?? null,
                );
            }
        });
    }

    public function baixarVenda(Venda $venda, Estoque $estoque): void
    {
        foreach ($venda->load('itens.produto')->itens as $item) {
            if ($item->tipo === 'servico' || !$item->produto_id) {
                continue;
            }

            $saldo = $this->obterSaldoComLock($estoque, $item->produto);
            if ($saldo->quantidade < $item->quantidade) {
                throw ValidationException::withMessages([
                    'estoque_id' => "Saldo insuficiente para o produto {$item->produto->produto}.",
                ]);
            }

            $custoUnitario = (float) $saldo->custo_medio;
            $saldo->decrement('quantidade', $item->quantidade);
            $item->update([
                'custo_unitario' => $custoUnitario,
                'custo_total' => $item->quantidade * $custoUnitario,
            ]);

            $this->registrarMovimento(
                estoque: $estoque,
                produto: $item->produto,
                tipo: 'venda',
                quantidade: -$item->quantidade,
                custoUnitario: $custoUnitario,
                origemTipo: Venda::class,
                origemId: $venda->id,
                observacao: "Baixa da venda {$venda->id}",
            );
        }
    }

    public function registrarEntrada(Estoque $estoque, array $data): EntradaEstoque
    {
        return DB::transaction(function () use ($estoque, $data): EntradaEstoque {
            $itens = $data['itens'];
            $valorItens = collect($itens)->sum(
                fn (array $item): float => $item['quantidade'] * $item['custo_unitario']
            );
            $freteTotal = (float) ($data['frete_total'] ?? 0);
            $fretesRateados = $this->ratearFrete($itens, $valorItens, $freteTotal);

            $entrada = EntradaEstoque::create([
                'estoque_id' => $estoque->id,
                'usuario_id' => Auth::id(),
                'tipo' => $data['tipo'],
                'frete_total' => $freteTotal,
                'data_entrada' => $data['data_entrada'] ?? now()->toDateString(),
                'observacao' => $data['observacao'] ?? null,
            ]);

            foreach ($itens as $index => $itemData) {
                $produto = Produto::find($itemData['produto_id']);

                if (!$produto) {
                    throw ValidationException::withMessages([
                        'itens' => "Produto {$itemData['produto_id']} não pertence à empresa atual.",
                    ]);
                }

                $freteRateado = $fretesRateados[$index];
                $custoTotal = ($itemData['quantidade'] * $itemData['custo_unitario']) + $freteRateado;
                $saldo = $this->obterSaldoComLock($estoque, $produto);
                $quantidadeAnterior = $saldo->quantidade;
                $valorAnterior = $quantidadeAnterior * (float) $saldo->custo_medio;
                $quantidadeNova = $quantidadeAnterior + $itemData['quantidade'];

                $saldo->update([
                    'quantidade' => $quantidadeNova,
                    'custo_medio' => round(($valorAnterior + $custoTotal) / $quantidadeNova, 4),
                ]);

                ItemEntradaEstoque::create([
                    'entrada_estoque_id' => $entrada->id,
                    'produto_id' => $produto->id,
                    'quantidade' => $itemData['quantidade'],
                    'custo_unitario' => $itemData['custo_unitario'],
                    'frete_rateado' => $freteRateado,
                ]);

                $this->registrarMovimento(
                    estoque: $estoque,
                    produto: $produto,
                    tipo: 'entrada',
                    quantidade: $itemData['quantidade'],
                    custoUnitario: $custoTotal / $itemData['quantidade'],
                    origemTipo: EntradaEstoque::class,
                    origemId: $entrada->id,
                    observacao: $data['observacao'] ?? null,
                );
            }

            return $entrada->load('itens.produto');
        });
    }

    public function transferir(Estoque $origem, Estoque $destino, array $data): TransferenciaEstoque
    {
        if ($origem->id === $destino->id) {
            throw ValidationException::withMessages([
                'estoque_destino_id' => 'O estoque de destino deve ser diferente da origem.',
            ]);
        }

        return DB::transaction(function () use ($origem, $destino, $data): TransferenciaEstoque {
            $itens = $data['itens'];
            $freteTotal = (float) ($data['frete_total'] ?? 0);
            $valorItens = collect($itens)->sum(function (array $item) use ($origem): float {
                $produto = Produto::findOrFail($item['produto_id']);
                $saldo = $this->obterSaldoComLock($origem, $produto);
                return $item['quantidade'] * (float) $saldo->custo_medio;
            });
            $fretesRateados = $this->ratearFrete($itens, $valorItens, $freteTotal, $origem);

            $transferencia = TransferenciaEstoque::create([
                'estoque_origem_id' => $origem->id,
                'estoque_destino_id' => $destino->id,
                'usuario_id' => Auth::id(),
                'frete_total' => $freteTotal,
                'data_transferencia' => $data['data_transferencia'] ?? now()->toDateString(),
                'observacao' => $data['observacao'] ?? null,
            ]);

            foreach ($itens as $index => $itemData) {
                $produto = Produto::find($itemData['produto_id']);

                if (!$produto) {
                    throw ValidationException::withMessages([
                        'itens' => "Produto {$itemData['produto_id']} não pertence à empresa atual.",
                    ]);
                }

                $saldoOrigem = $this->obterSaldoComLock($origem, $produto);
                if ($saldoOrigem->quantidade < $itemData['quantidade']) {
                    throw ValidationException::withMessages([
                        'itens' => "Saldo insuficiente para o produto {$produto->produto} no estoque de origem.",
                    ]);
                }

                $custoUnitario = (float) $saldoOrigem->custo_medio;
                $freteRateado = $fretesRateados[$index];
                $custoUnitarioDestino = $custoUnitario + ($freteRateado / $itemData['quantidade']);
                $saldoDestino = $this->obterSaldoComLock($destino, $produto);
                $quantidadeDestino = $saldoDestino->quantidade + $itemData['quantidade'];
                $valorDestino = ($saldoDestino->quantidade * (float) $saldoDestino->custo_medio)
                    + ($itemData['quantidade'] * $custoUnitarioDestino);

                $saldoOrigem->decrement('quantidade', $itemData['quantidade']);
                $saldoDestino->update([
                    'quantidade' => $quantidadeDestino,
                    'custo_medio' => round($valorDestino / $quantidadeDestino, 4),
                ]);

                ItemTransferenciaEstoque::create([
                    'transferencia_estoque_id' => $transferencia->id,
                    'produto_id' => $produto->id,
                    'quantidade' => $itemData['quantidade'],
                    'custo_unitario' => $custoUnitario,
                    'frete_rateado' => $freteRateado,
                ]);

                $this->registrarMovimento($origem, $produto, 'transferencia_saida', -$itemData['quantidade'], $custoUnitario, TransferenciaEstoque::class, $transferencia->id, $data['observacao'] ?? null);
                $this->registrarMovimento($destino, $produto, 'transferencia_entrada', $itemData['quantidade'], $custoUnitarioDestino, TransferenciaEstoque::class, $transferencia->id, $data['observacao'] ?? null);
            }

            return $transferencia->load('itens.produto', 'origem', 'destino');
        });
    }

    public function estornarMovimento(MovimentoEstoque $movimento): void
    {
        if ($movimento->estaEstornado()) {
            throw ValidationException::withMessages([
                'movimento' => 'Esta movimentação já foi estornada.',
            ]);
        }

        DB::transaction(function () use ($movimento): void {
            $estoque = Estoque::findOrFail($movimento->estoque_id);
            $produto = Produto::findOrFail($movimento->produto_id);

            $saldo = $this->obterSaldoComLock($estoque, $produto);
            $quantidadeEstorno = -$movimento->quantidade;

            if ($saldo->quantidade + $quantidadeEstorno < 0) {
                throw ValidationException::withMessages([
                    'movimento' => "Não é possível estornar: saldo insuficiente do produto {$produto->produto} para reverter esta movimentação.",
                ]);
            }

            $saldo->increment('quantidade', $quantidadeEstorno);

            $this->registrarMovimento(
                estoque: $estoque,
                produto: $produto,
                tipo: 'estorno',
                quantidade: $quantidadeEstorno,
                custoUnitario: (float) $movimento->custo_unitario,
                origemTipo: MovimentoEstoque::class,
                origemId: $movimento->id,
                observacao: "Estorno da movimentação #{$movimento->id}",
            );

            $movimento->update([
                'estornado_em' => now(),
                'estornado_por_id' => Auth::id(),
            ]);
        });
    }

    private function obterSaldoComLock(Estoque $estoque, Produto $produto): EstoqueSaldo
    {
        $saldo = EstoqueSaldo::query()
            ->where('estoque_id', $estoque->id)
            ->where('produto_id', $produto->id)
            ->lockForUpdate()
            ->first();

        if ($saldo) {
            return $saldo;
        }

        EstoqueSaldo::create([
            'estoque_id' => $estoque->id,
            'produto_id' => $produto->id,
            'quantidade' => 0,
            'custo_medio' => 0,
        ]);

        return EstoqueSaldo::query()
            ->where('estoque_id', $estoque->id)
            ->where('produto_id', $produto->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function registrarMovimento(
        Estoque $estoque,
        Produto $produto,
        string $tipo,
        int $quantidade,
        float $custoUnitario,
        string $origemTipo,
        ?int $origemId,
        ?string $observacao
    ): MovimentoEstoque {
        return MovimentoEstoque::create([
            'estoque_id' => $estoque->id,
            'produto_id' => $produto->id,
            'tipo' => $tipo,
            'quantidade' => $quantidade,
            'custo_unitario' => $custoUnitario,
            'custo_total' => $quantidade * $custoUnitario,
            'origem_tipo' => $origemTipo,
            'origem_id' => $origemId,
            'usuario_id' => Auth::id(),
            'observacao' => $observacao,
            'movimentado_em' => now(),
        ]);
    }

    private function ratearFrete(array $itens, float $valorItens, float $freteTotal, ?Estoque $origem = null): array
    {
        if ($freteTotal <= 0) {
            return array_fill(0, count($itens), 0.0);
        }

        if ($valorItens <= 0) {
            $quantidadeTotal = collect($itens)->sum('quantidade');
            if ($quantidadeTotal <= 0) {
                return array_fill(0, count($itens), 0.0);
            }

            return array_map(
                fn (array $item): float => round($freteTotal * ($item['quantidade'] / $quantidadeTotal), 4),
                $itens
            );
        }

        $rateios = [];
        $acumulado = 0.0;
        $ultimoIndex = count($itens) - 1;

        foreach ($itens as $index => $item) {
            $custo = $item['custo_unitario'] ?? 0;
            if ($origem) {
                $produto = Produto::findOrFail($item['produto_id']);
                $custo = (float) $this->obterSaldoComLock($origem, $produto)->custo_medio;
            }
            $valorItem = $item['quantidade'] * $custo;
            $rateio = $index === $ultimoIndex
                ? round($freteTotal - $acumulado, 4)
                : round($freteTotal * ($valorItem / $valorItens), 4);
            $rateios[$index] = $rateio;
            $acumulado += $rateio;
        }

        return $rateios;
    }

    /**
     * Valida se há saldo disponível para múltiplos itens em um estoque.
     * Retorna array com validações por item.
     */
    public function validarSaldosRealTime(Estoque $estoque, array $itens): array
    {
        $validacoes = [];

        foreach ($itens as $itemData) {
            $produto = Produto::find($itemData['produto_id']);
            
            if (!$produto) {
                $validacoes[] = [
                    'produto_id' => $itemData['produto_id'],
                    'valido' => false,
                    'mensagem' => 'Produto não encontrado',
                ];
                continue;
            }

            $saldo = EstoqueSaldo::firstOrCreate(
                [
                    'estoque_id' => $estoque->id,
                    'produto_id' => $produto->id,
                ],
                [
                    'quantidade' => 0,
                    'custo_medio' => 0,
                ]
            );

            $temSaldo = $saldo->quantidade >= ($itemData['quantidade'] ?? 0);

            $validacoes[] = [
                'produto_id' => $produto->id,
                'produto_nome' => $produto->produto,
                'quantidade_solicitada' => $itemData['quantidade'] ?? 0,
                'quantidade_disponivel' => $saldo->quantidade,
                'custo_medio' => (float) $saldo->custo_medio,
                'valido' => $temSaldo,
                'mensagem' => $temSaldo
                    ? 'Saldo disponível'
                    : "Saldo insuficiente. Disponível: {$saldo->quantidade}",
            ];
        }

        return $validacoes;
    }

    /**
     * Obtém dados para o dashboard de estoques.
     * Retorna saldos, últimas movimentações e estatísticas.
     */
    public function obterDadosDashboard(Estoque $estoque, int $limitMovimentos = 20): array
    {
        $saldos = EstoqueSaldo::where('estoque_id', $estoque->id)
            ->with('produto')
            ->where('quantidade', '>', 0)
            ->get();

        $movimentos = MovimentoEstoque::where('estoque_id', $estoque->id)
            ->with(['produto', 'usuario'])
            ->latest('movimentado_em')
            ->limit($limitMovimentos)
            ->get();

        $valorTotalEstoque = $saldos->sum(
            fn (EstoqueSaldo $saldo) => $saldo->quantidade * (float) $saldo->custo_medio
        );

        $quantidadeTotal = $saldos->sum('quantidade');

        return [
            'estoque' => [
                'id' => $estoque->id,
                'nome' => $estoque->nome,
                'codigo' => $estoque->codigo,
                'cidade' => $estoque->cidade,
            ],
            'resumo' => [
                'quantidade_total' => $quantidadeTotal,
                'valor_total' => round($valorTotalEstoque, 2),
                'quantidade_produtos_unicos' => $saldos->count(),
            ],
            'saldos' => $saldos,
            'ultimas_movimentacoes' => $movimentos,
        ];
    }
}