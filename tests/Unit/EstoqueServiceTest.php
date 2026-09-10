<?php

namespace Tests\Unit;

use App\Models\Empresa;
use App\Models\Estoque;
use App\Models\EstoqueSaldo;
use App\Models\Produto;
use App\Services\EstoqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstoqueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_entrada_com_frete_no_custo_medio(): void
    {
        $empresa = Empresa::create(['nome' => 'Empresa Teste', 'cnpj' => '11111111111111']);
        $produto = Produto::factory()->create([
            'empresa_id' => $empresa->id,
            'estoque' => 0,
            'valor' => 100,
        ]);
        $estoque = Estoque::create([
            'empresa_id' => $empresa->id,
            'nome' => 'CD São Paulo',
            'codigo' => 'SP',
        ]);

        app(EstoqueService::class)->registrarEntrada($estoque, [
            'tipo' => 'compra',
            'frete_total' => 100,
            'itens' => [[
                'produto_id' => $produto->id,
                'quantidade' => 10,
                'custo_unitario' => 100,
            ]],
        ]);

        $saldo = EstoqueSaldo::where('estoque_id', $estoque->id)
            ->where('produto_id', $produto->id)
            ->firstOrFail();

        $this->assertSame(10, $saldo->quantidade);
        $this->assertSame('110.0000', $saldo->custo_medio);
    }

    public function test_transferencia_move_quantidade_e_preserva_custo(): void
    {
        $empresa = Empresa::create(['nome' => 'Empresa Teste', 'cnpj' => '22222222222222']);
        $produto = Produto::factory()->create(['empresa_id' => $empresa->id, 'estoque' => 0]);
        $origem = Estoque::create(['empresa_id' => $empresa->id, 'nome' => 'São Paulo', 'codigo' => 'SP']);
        $destino = Estoque::create(['empresa_id' => $empresa->id, 'nome' => 'Rio de Janeiro', 'codigo' => 'RJ']);

        EstoqueSaldo::create([
            'empresa_id' => $empresa->id,
            'estoque_id' => $origem->id,
            'produto_id' => $produto->id,
            'quantidade' => 10,
            'custo_medio' => 125,
        ]);

        app(EstoqueService::class)->transferir($origem, $destino, [
            'itens' => [[
                'produto_id' => $produto->id,
                'quantidade' => 4,
            ]],
        ]);

        $this->assertDatabaseHas('estoque_saldos', [
            'estoque_id' => $origem->id,
            'produto_id' => $produto->id,
            'quantidade' => 6,
            'custo_medio' => 125,
        ]);
        $this->assertDatabaseHas('estoque_saldos', [
            'estoque_id' => $destino->id,
            'produto_id' => $produto->id,
            'quantidade' => 4,
            'custo_medio' => 125,
        ]);
        $this->assertDatabaseCount('movimentos_estoque', 2);
    }
}
