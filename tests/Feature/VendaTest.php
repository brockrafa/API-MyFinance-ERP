<?php

namespace Tests\Feature;

use App\Models\ItemVenda;
use App\Models\LancamentoFinanceiro;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CriaCenarioDeVenda;
use Tests\TestCase;

class VendaTest extends TestCase
{
    use RefreshDatabase, CriaCenarioDeVenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenario();
    }

    public function test_venda_avista_baixa_estoque_e_grava_custo_da_entrada(): void
    {
        $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(3)]))->assertCreated();

        $this->assertSame(7, $this->saldo());

        $item = ItemVenda::firstOrFail();
        $this->assertEquals(20, $item->custo_unitario);
        $this->assertEquals(60, $item->custo_total);
    }

    public function test_editar_venda_ajusta_o_estoque_e_os_lancamentos(): void
    {
        $id = $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(3)]))->json('id');
        $this->assertSame(7, $this->saldo());

        $this->putJson("/api/vendas/{$id}", $this->payloadVenda([$this->itemProduto(5, 60)]))->assertNoContent();

        $this->assertSame(5, $this->saldo());
        $this->assertEquals(300, Venda::findOrFail($id)->total);
        $this->assertEquals([300.0], LancamentoFinanceiro::where('venda_id', $id)->pluck('valor')->map(fn ($v) => (float) $v)->all());
        $this->assertCount(1, ItemVenda::where('venda_id', $id)->get());
    }

    public function test_editar_venda_aceita_item_avulso(): void
    {
        $avulso = ['tipo' => 'avulso', 'descricao' => 'Taxa de entrega', 'quantidade' => 1, 'valor' => 30];
        $id = $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(1), $avulso]))->json('id');

        $avulso['valor'] = 40;
        $this->putJson("/api/vendas/{$id}", $this->payloadVenda([$this->itemProduto(1), $avulso]))->assertNoContent();

        $this->assertEquals(90, Venda::findOrFail($id)->total);
        $this->assertSame('Taxa de entrega', ItemVenda::where('venda_id', $id)->where('tipo', 'avulso')->value('descricao'));
        $this->assertSame(9, $this->saldo());
    }

    public function test_excluir_venda_devolve_o_estoque(): void
    {
        $id = $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(4)]))->json('id');
        $this->assertSame(6, $this->saldo());

        $this->deleteJson("/api/vendas/{$id}")->assertNoContent();

        $this->assertSame(10, $this->saldo());
        $this->assertNull(Venda::find($id));
    }

    public function test_venda_com_parcela_paga_nao_aceita_mudar_itens_mas_aceita_mudar_data(): void
    {
        $condicao = ['tipo' => 'prazo', 'parcelas' => 2, 'entrada' => 0, 'primeiro_vencimento' => '2026-11-10'];
        $id = $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(2)], $condicao))->json('id');

        LancamentoFinanceiro::where('venda_id', $id)->where('numero_parcela', 1)
            ->update(['status' => 'pago', 'valor_pago' => 50, 'data_pagamento' => '2026-11-10']);

        $this->putJson("/api/vendas/{$id}", $this->payloadVenda([$this->itemProduto(3)], $condicao))
            ->assertStatus(422);
        $this->assertSame(8, $this->saldo(), 'a edição recusada não pode mexer no estoque');

        $this->putJson("/api/vendas/{$id}", ['data_venda' => '2026-10-25'])->assertNoContent();
        $this->assertSame('2026-10-25', Venda::findOrFail($id)->data_venda);
    }

    public function test_venda_sem_saldo_retorna_422_e_nao_cria_nada(): void
    {
        $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(11)]))->assertStatus(422);

        $this->assertSame(10, $this->saldo());
        $this->assertSame(0, Venda::count());
    }

    public function test_listagem_de_vendas_filtra_e_pagina(): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-09-15'] as $data) {
            $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(1)], extra: ['data_venda' => $data]))->assertCreated();
        }

        $this->getJson('/api/vendas')->assertOk()->assertJsonCount(3);

        $pagina = $this->getJson('/api/vendas?page=1&per_page=2&mes=10&ano=2026')->assertOk();
        $pagina->assertJsonPath('total', 2)->assertJsonCount(2, 'data');

        $this->getJson('/api/vendas?page=1&per_page=2&busca=inexistente')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_parcela_vencida_aparece_como_atrasada_ao_listar_vendas(): void
    {
        $condicao = ['tipo' => 'prazo', 'parcelas' => 1, 'entrada' => 0, 'primeiro_vencimento' => now()->subDays(5)->toDateString()];
        $id = $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(1)], $condicao))->json('id');

        $this->getJson('/api/vendas')->assertOk();

        $this->assertSame('atrasado', LancamentoFinanceiro::where('venda_id', $id)->value('status'));
    }
}
