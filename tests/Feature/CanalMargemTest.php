<?php

namespace Tests\Feature;

use App\Models\LancamentoFinanceiro;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CriaCenarioDeVenda;
use Tests\TestCase;

class CanalMargemTest extends TestCase
{
    use RefreshDatabase, CriaCenarioDeVenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenario(['relatorios.margem.view']);
    }

    /** 2 un. a R$ 50 (total 100), custo de R$ 20 cada, líquido do ML de R$ 78 e R$ 5 de embalagem. */
    private function vendaMercadoLivre(array $extra = []): array
    {
        return $this->payloadVenda([$this->itemProduto(2)], extra: array_merge([
            'canal' => 'mercado_livre',
            'valor_liquido_recebido' => 78,
            'custos_adicionais' => 5,
        ], $extra));
    }

    public function test_venda_de_canal_exige_o_valor_recebido_apos_descontos(): void
    {
        $this->postJson('/api/vendas', $this->vendaMercadoLivre(['valor_liquido_recebido' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('valor_liquido_recebido');

        $this->assertSame(0, Venda::count());
        $this->assertSame(10, $this->saldo());
    }

    public function test_valor_recebido_nao_pode_ser_maior_que_o_total(): void
    {
        $this->postJson('/api/vendas', $this->vendaMercadoLivre(['valor_liquido_recebido' => 101]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('valor_liquido_recebido');

        $this->assertSame(0, Venda::count());
        $this->assertSame(10, $this->saldo());
    }

    public function test_canal_invalido_e_recusado(): void
    {
        $this->postJson('/api/vendas', $this->vendaMercadoLivre(['canal' => 'shopee']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('canal');
    }

    public function test_venda_do_mercado_livre_recebe_o_valor_liquido_e_calcula_a_margem_real(): void
    {
        $id = $this->postJson('/api/vendas', $this->vendaMercadoLivre())->assertCreated()->json('id');

        // O contas a receber é o que o ML repassa, não o preço de venda
        $this->assertEquals([78.0], LancamentoFinanceiro::where('venda_id', $id)->pluck('valor')->map(fn ($v) => (float) $v)->all());

        $resumo = $this->getJson("/api/vendas/{$id}")->assertOk()->json('resumo_financeiro');

        $this->assertSame('mercado_livre', $resumo['canal']);
        $this->assertEquals(100, $resumo['total']);
        $this->assertEquals(22, $resumo['taxa_canal']);
        $this->assertEquals(78, $resumo['receita_liquida']);
        $this->assertEquals(40, $resumo['custo']);
        $this->assertEquals(5, $resumo['custos_adicionais']);
        $this->assertEquals(33, $resumo['margem']);
        $this->assertEquals(42.3, $resumo['margem_percentual']);
    }

    public function test_venda_direta_nao_tem_taxa_e_a_margem_vem_do_total(): void
    {
        $id = $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(1)]))->json('id');

        $resumo = $this->getJson("/api/vendas/{$id}")->json('resumo_financeiro');

        $this->assertSame('venda_direta', $resumo['canal']);
        $this->assertEquals(0, $resumo['taxa_canal']);
        $this->assertEquals(30, $resumo['margem']);
    }

    public function test_parcelas_do_canal_sao_calculadas_sobre_o_valor_liquido(): void
    {
        $condicao = ['tipo' => 'prazo', 'parcelas' => 2, 'entrada' => 0, 'primeiro_vencimento' => '2026-11-10'];
        $id = $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(2)], $condicao, [
            'canal' => 'mercado_livre',
            'valor_liquido_recebido' => 80,
        ]))->assertCreated()->json('id');

        $valores = LancamentoFinanceiro::where('venda_id', $id)->orderBy('numero_parcela')->pluck('valor')->map(fn ($v) => (float) $v)->all();
        $this->assertSame([40.0, 40.0], $valores);

        $venda = collect($this->getJson('/api/vendas')->json())->firstWhere('id', $id);
        $this->assertEquals(80, $venda['valor_pendente']);
        $this->assertEquals(20, $venda['taxa_canal']);
    }

    public function test_editar_a_venda_para_venda_direta_descarta_o_valor_liquido(): void
    {
        $id = $this->postJson('/api/vendas', $this->vendaMercadoLivre())->json('id');

        $this->putJson("/api/vendas/{$id}", $this->payloadVenda([$this->itemProduto(2)], extra: ['canal' => 'venda_direta']))->assertNoContent();

        $venda = Venda::findOrFail($id);
        $this->assertSame('venda_direta', $venda->canal);
        $this->assertNull($venda->valor_liquido_recebido);
        $this->assertEquals([100.0], LancamentoFinanceiro::where('venda_id', $id)->pluck('valor')->map(fn ($v) => (float) $v)->all());
    }

    public function test_listagem_de_vendas_filtra_por_canal(): void
    {
        $this->postJson('/api/vendas', $this->vendaMercadoLivre())->assertCreated();
        $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(1)]))->assertCreated();

        $this->getJson('/api/vendas?page=1&per_page=10&canal=mercado_livre')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/vendas?page=1&per_page=10')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_relatorio_de_margem_soma_por_canal_e_por_produto(): void
    {
        $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(1)]))->assertCreated();   // venda direta: 50, custo 20, margem 30
        $this->postJson('/api/vendas', $this->vendaMercadoLivre())->assertCreated();                      // ML: margem 33

        $relatorio = $this->getJson('/api/relatorios/margem?inicio=2026-10-01&fim=2026-10-31')->assertOk()->json();

        $this->assertSame(2, $relatorio['resumo']['vendas']);
        $this->assertEquals(150, $relatorio['resumo']['receita_bruta']);
        $this->assertEquals(22, $relatorio['resumo']['taxas_canal']);
        $this->assertEquals(128, $relatorio['resumo']['receita_liquida']);
        $this->assertEquals(60, $relatorio['resumo']['custo']);
        $this->assertEquals(5, $relatorio['resumo']['custos_adicionais']);
        $this->assertEquals(63, $relatorio['resumo']['margem']);

        $porCanal = collect($relatorio['por_canal'])->keyBy('canal');
        $this->assertEquals(30, $porCanal['venda_direta']['margem']);
        $this->assertEquals(33, $porCanal['mercado_livre']['margem']);

        $this->assertCount(1, $relatorio['por_produto']);
        $this->assertSame('Produto Teste', $relatorio['por_produto'][0]['produto']);
        $this->assertSame(3, $relatorio['por_produto'][0]['quantidade']);
        $this->assertEquals(63, $relatorio['por_produto'][0]['margem']);
        $this->assertCount(2, $relatorio['vendas']);
    }

    public function test_relatorio_filtra_por_canal_e_periodo(): void
    {
        $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(1)]))->assertCreated();
        $this->postJson('/api/vendas', $this->vendaMercadoLivre())->assertCreated();
        $this->postJson('/api/vendas', $this->vendaMercadoLivre(['data_venda' => '2026-08-01']))->assertCreated();

        $relatorio = $this->getJson('/api/relatorios/margem?inicio=2026-10-01&fim=2026-10-31&canal=mercado_livre')->assertOk()->json();

        $this->assertSame(1, $relatorio['resumo']['vendas']);
        $this->assertEquals(33, $relatorio['resumo']['margem']);
    }

    public function test_relatorio_exige_permissao(): void
    {
        $this->usuario->revokePermissionTo('relatorios.margem.view');
        $this->usuario->unsetRelation('permissions');

        $this->getJson('/api/relatorios/margem')->assertForbidden();
    }
}
