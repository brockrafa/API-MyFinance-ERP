<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\EstoqueSaldo;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CriaCenarioDeVenda;
use Tests\TestCase;

class ProdutoCustoTest extends TestCase
{
    use RefreshDatabase, CriaCenarioDeVenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenario();
    }

    private function dadosProduto(array $extra = []): array
    {
        return array_merge([
            'produto' => 'Caixa de som',
            'valor_venda' => 120,
            'categoria_id' => Categoria::inRandomOrder()->value('id') ?? Categoria::factory()->create(['empresa_id' => $this->empresa->id])->id,
        ], $extra);
    }

    public function test_a_tabela_de_produtos_nao_tem_mais_coluna_de_custo(): void
    {
        $this->assertFalse(Schema::hasColumn('produtos', 'valor'));
    }

    public function test_cadastra_produto_somente_com_preco_de_venda_sugerido(): void
    {
        $this->postJson('/api/produtos', $this->dadosProduto())->assertCreated();

        $this->assertDatabaseHas('produtos', ['produto' => 'Caixa de som', 'valor_venda' => 120]);
    }

    public function test_quantidade_inicial_exige_o_custo_da_entrada(): void
    {
        $this->postJson('/api/produtos', $this->dadosProduto([
            'estoque_id' => $this->estoque->id,
            'quantidade_inicial' => 5,
        ]))->assertStatus(422)->assertJsonValidationErrors('custo_inicial');

        $this->assertDatabaseMissing('produtos', ['produto' => 'Caixa de som']);
    }

    public function test_estoque_inicial_grava_o_custo_informado_como_custo_medio(): void
    {
        $id = $this->postJson('/api/produtos', $this->dadosProduto([
            'estoque_id' => $this->estoque->id,
            'quantidade_inicial' => 5,
            'custo_inicial' => 35.5,
        ]))->assertCreated()->json('id');

        $saldo = EstoqueSaldo::where('produto_id', $id)->firstOrFail();
        $this->assertSame(5, $saldo->quantidade);
        $this->assertEquals(35.5, $saldo->custo_medio);
    }

    public function test_listagem_traz_custo_medio_ponderado_das_entradas(): void
    {
        $this->darEntrada($this->produto, 10, 30); // 10 un. a 20 + 10 un. a 30 = custo médio 25

        $lista = $this->getJson('/api/produtos')->assertOk()->json();
        $produto = collect($lista)->firstWhere('id', $this->produto->id);

        $this->assertEquals(25, $produto['custo_medio']);
    }

    public function test_listagem_de_produtos_pagina_e_busca_por_nome(): void
    {
        Produto::factory()->count(3)->create(['empresa_id' => $this->empresa->id, 'estoque' => 0, 'produto' => 'Cabo USB']);

        $this->getJson('/api/produtos?page=1&per_page=2&busca=Cabo')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_atualizar_produto_nao_altera_o_custo_do_estoque(): void
    {
        $this->putJson("/api/produtos/{$this->produto->id}", $this->dadosProduto(['produto' => 'Nome novo', 'valor_venda' => 75]))
            ->assertNoContent();

        $this->assertDatabaseHas('produtos', ['id' => $this->produto->id, 'produto' => 'Nome novo', 'valor_venda' => 75]);
        $this->assertEquals(20, EstoqueSaldo::where('produto_id', $this->produto->id)->value('custo_medio'));
    }
}
