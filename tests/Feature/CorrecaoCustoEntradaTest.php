<?php

namespace Tests\Feature;

use App\Models\AjusteCustoEstoque;
use App\Models\EntradaEstoque;
use App\Models\Estoque;
use App\Models\EstoqueSaldo;
use App\Models\ItemEntradaEstoque;
use App\Models\ItemTransferenciaEstoque;
use App\Models\ItemVenda;
use App\Models\MovimentoEstoque;
use App\Models\Produto;
use App\Services\EstoqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CriaCenarioDeVenda;
use Tests\TestCase;

class CorrecaoCustoEntradaTest extends TestCase
{
    use RefreshDatabase, CriaCenarioDeVenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenario(['estoque.edit']);
    }

    private function novoProduto(string $nome = 'SSD'): Produto
    {
        return Produto::factory()->create(['empresa_id' => $this->empresa->id, 'estoque' => 0, 'produto' => $nome, 'valor_venda' => 200]);
    }

    /** @return int id da venda */
    private function vender(Produto $produto, int $quantidade, float $valor, array $extra = []): int
    {
        $item = ['id' => $produto->id, 'tipo' => 'produto', 'quantidade' => $quantidade, 'valor' => $valor];

        return $this->postJson('/api/vendas', $this->payloadVenda([$item], extra: $extra))->assertCreated()->json('id');
    }

    private function entrada(Produto $produto, int $posicao = 0): MovimentoEstoque
    {
        return MovimentoEstoque::where('produto_id', $produto->id)->where('tipo', 'entrada')->orderBy('id')->get()->get($posicao);
    }

    private function previa(MovimentoEstoque $movimento, float $custo, ?float $frete = null)
    {
        return $this->postJson("/api/estoques/movimentos/{$movimento->id}/corrigir-custo/previa", array_filter(
            ['custo_unitario' => $custo, 'frete_item' => $frete],
            fn ($v) => $v !== null
        ));
    }

    private function corrigir(MovimentoEstoque $movimento, float $custo, array $vendas, ?float $frete = null, string $motivo = 'Paguei mais caro na retirada')
    {
        return $this->postJson("/api/estoques/movimentos/{$movimento->id}/corrigir-custo", array_filter(
            ['custo_unitario' => $custo, 'frete_item' => $frete, 'motivo' => $motivo, 'vendas' => $vendas],
            fn ($v) => $v !== null
        ));
    }

    private function custoMedio(Produto $produto, ?Estoque $estoque = null): float
    {
        return (float) EstoqueSaldo::where('produto_id', $produto->id)->where('estoque_id', ($estoque ?? $this->estoque)->id)->value('custo_medio');
    }

    public function test_cenario_da_venda_no_ml_com_custo_corrigido_de_100_para_110(): void
    {
        $ssd = $this->novoProduto();
        $this->darEntrada($ssd, 1, 100);
        $vendaId = $this->vender($ssd, 1, 200, [
            'canal' => 'mercado_livre',
            'valor_liquido_recebido' => 160,
            'data_venda' => now()->toDateString(),
        ]);
        $entrada = $this->entrada($ssd);

        $previa = $this->previa($entrada, 110)->assertOk()->json();

        $this->assertEquals(100, $previa['custo_anterior']);
        $this->assertEquals(110, $previa['custo_novo']);
        $this->assertCount(1, $previa['vendas']);
        $this->assertSame($vendaId, $previa['vendas'][0]['venda_id']);
        $this->assertEquals(60, $previa['vendas'][0]['margem_atual']);
        $this->assertEquals(50, $previa['vendas'][0]['margem_nova']);
        $this->assertTrue($previa['vendas'][0]['selecionada_padrao']);
        $this->assertEquals(100, ItemVenda::where('venda_id', $vendaId)->value('custo_unitario'), 'a prévia não grava nada');

        $this->corrigir($entrada, 110, [$vendaId])->assertOk()->assertJsonPath('vendas_reprecificadas', 1);

        $item = ItemVenda::where('venda_id', $vendaId)->firstOrFail();
        $this->assertEquals(110, $item->custo_unitario);
        $this->assertEquals(110, $item->custo_total);
        $this->assertEquals(110, $this->custoMedio($ssd));
        $this->assertEquals(110, ItemEntradaEstoque::where('produto_id', $ssd->id)->value('custo_unitario'));
        $this->assertEquals(110, $entrada->fresh()->custo_unitario);
        $this->assertEquals(110, MovimentoEstoque::where('tipo', 'venda')->where('origem_id', $vendaId)->value('custo_unitario'));
        $this->assertEquals(50, $this->getJson("/api/vendas/{$vendaId}")->json('resumo_financeiro.margem'));

        $ajuste = AjusteCustoEstoque::firstOrFail();
        $this->assertEquals(100, $ajuste->custo_anterior);
        $this->assertEquals(110, $ajuste->custo_novo);
        $this->assertSame('Paguei mais caro na retirada', $ajuste->motivo);
        $this->assertSame(1, $ajuste->vendas_reprecificadas);
        $this->assertSame($this->usuario->id, $ajuste->usuario_id);
    }

    public function test_vendas_antes_da_entrada_ou_depois_de_o_estoque_zerar_nao_sao_afetadas(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 1, 50);          // lote X
        $v0 = $this->vender($p, 1, 120);       // zera o estoque
        $this->darEntrada($p, 1, 100);         // lote Y
        $v1 = $this->vender($p, 1, 150);

        $afetadasPorY = collect($this->previa($this->entrada($p, 1), 120)->assertOk()->json('vendas'))->pluck('venda_id')->all();
        $this->assertSame([$v1], $afetadasPorY, 'corrigir o lote Y não mexe na venda que usou o lote X');

        $afetadasPorX = collect($this->previa($this->entrada($p, 0), 60)->assertOk()->json('vendas'))->pluck('venda_id')->all();
        $this->assertSame([$v0], $afetadasPorX, 'depois de o estoque zerar, o custo do lote X deixa de influenciar');
    }

    public function test_custo_medio_misturado_reprecifica_as_vendas_seguintes_proporcionalmente(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 2, 100);         // lote A
        $s1 = $this->vender($p, 1, 300);       // custo 100
        $this->darEntrada($p, 2, 200);         // lote B: custo médio (100 + 400) / 3 = 166,6667
        $s2 = $this->vender($p, 1, 300);       // custo 166,6667

        $previa = $this->previa($this->entrada($p, 0), 110)->assertOk()->json();
        $vendas = collect($previa['vendas'])->keyBy('venda_id');

        $this->assertEquals(100, $vendas[$s1]['custo_atual']);
        $this->assertEquals(110, $vendas[$s1]['custo_novo']);
        $this->assertEquals(166.67, $vendas[$s2]['custo_atual']);
        $this->assertEquals(170, $vendas[$s2]['custo_novo']);
        $this->assertEquals(170, $previa['estoques'][0]['custo_medio_novo']);

        $this->corrigir($this->entrada($p, 0), 110, [$s1, $s2])->assertOk();

        $this->assertEquals(110, ItemVenda::where('venda_id', $s1)->value('custo_unitario'));
        $this->assertEquals(170, ItemVenda::where('venda_id', $s2)->value('custo_unitario'));
        $this->assertEquals(170, $this->custoMedio($p));
        $this->assertSame(2, (int) EstoqueSaldo::where('produto_id', $p->id)->value('quantidade'), 'a quantidade nunca muda');
    }

    public function test_so_as_vendas_escolhidas_sao_reprecificadas_mas_o_custo_medio_e_sempre_corrigido(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 2, 100);
        $s1 = $this->vender($p, 1, 300);
        $this->darEntrada($p, 2, 200);
        $s2 = $this->vender($p, 1, 300);

        $this->corrigir($this->entrada($p, 0), 110, [$s1])->assertOk()
            ->assertJsonPath('vendas_reprecificadas', 1)
            ->assertJsonPath('vendas_mantidas', 1);

        $this->assertEquals(110, ItemVenda::where('venda_id', $s1)->value('custo_unitario'));
        $this->assertEquals(166.6667, (float) ItemVenda::where('venda_id', $s2)->value('custo_unitario'), 'a venda não escolhida mantém o custo antigo');
        $this->assertEquals(170, $this->custoMedio($p));
        $this->assertSame(1, AjusteCustoEstoque::value('vendas_mantidas'));
    }

    public function test_venda_com_data_anterior_a_entrada_vem_desmarcada_por_padrao(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 1, 100);
        $this->vender($p, 1, 200, ['data_venda' => '2025-01-10']);

        $venda = $this->previa($this->entrada($p), 110)->assertOk()->json('vendas.0');

        $this->assertTrue($venda['antes_da_entrada']);
        $this->assertFalse($venda['selecionada_padrao']);
    }

    public function test_frete_da_entrada_tambem_pode_ser_corrigido(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 2, 100, 20);     // 2 un. a R$ 100 + R$ 20 de frete = R$ 110 por unidade
        $entrada = $this->entrada($p);

        $detalhes = $this->getJson("/api/estoques/movimentos/{$entrada->id}/custo")->assertOk()->json();
        $this->assertEquals(100, $detalhes['custo_unitario']);
        $this->assertEquals(20, $detalhes['frete_item']);
        $this->assertEquals(110, $detalhes['custo_unitario_com_frete']);
        $this->assertTrue($detalhes['tem_item']);

        $this->corrigir($entrada, 100, [], 40)->assertOk();   // frete passou de R$ 20 para R$ 40

        $this->assertEquals(120, $this->custoMedio($p));
        $this->assertEquals(40, ItemEntradaEstoque::where('produto_id', $p->id)->value('frete_rateado'));
        $this->assertEquals(120, $entrada->fresh()->custo_unitario);
        $this->assertEquals(40, EntradaEstoque::whereHas('itens', fn ($q) => $q->where('produto_id', $p->id))->value('frete_total'));
    }

    public function test_transferencia_acompanha_o_custo_corrigido_no_destino(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 2, 100);
        $loja = Estoque::create(['empresa_id' => $this->empresa->id, 'nome' => 'Loja', 'codigo' => 'LOJA']);
        app(EstoqueService::class)->transferir($this->estoque, $loja, ['itens' => [['produto_id' => $p->id, 'quantidade' => 1]]]);

        $previa = $this->previa($this->entrada($p), 110)->assertOk()->json();
        $this->assertSame(2, $previa['outros_movimentos_afetados']);
        $this->assertCount(2, $previa['estoques']);

        $this->corrigir($this->entrada($p), 110, [])->assertOk();

        $this->assertEquals(110, $this->custoMedio($p));
        $this->assertEquals(110, $this->custoMedio($p, $loja));
        $this->assertEquals(110, MovimentoEstoque::where('tipo', 'transferencia_entrada')->value('custo_unitario'));
        $this->assertEquals(110, ItemTransferenciaEstoque::where('produto_id', $p->id)->value('custo_unitario'));
    }

    public function test_venda_editada_nao_conta_duas_vezes_e_o_estorno_acompanha(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 3, 100);
        $vendaId = $this->vender($p, 1, 200);

        // Editar a venda estorna a baixa antiga e lança uma nova
        $item = ['id' => $p->id, 'tipo' => 'produto', 'quantidade' => 2, 'valor' => 200];
        $this->putJson("/api/vendas/{$vendaId}", $this->payloadVenda([$item]))->assertNoContent();

        $previa = $this->previa($this->entrada($p), 110)->assertOk()->json();
        $this->assertCount(1, $previa['vendas'], 'só a baixa viva da venda é listada');

        $this->corrigir($this->entrada($p), 110, [$vendaId])->assertOk();

        $this->assertEquals(110, ItemVenda::where('venda_id', $vendaId)->value('custo_unitario'));
        $this->assertEquals(110, MovimentoEstoque::where('tipo', 'estorno')->value('custo_unitario'));
        $this->assertSame(1, (int) EstoqueSaldo::where('produto_id', $p->id)->value('quantidade'));
    }

    public function test_correcao_para_o_mesmo_custo_nao_altera_nada(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 1, 100);
        $this->vender($p, 1, 200);

        $previa = $this->previa($this->entrada($p), 100)->assertOk()->json();

        $this->assertTrue($previa['sem_alteracao']);
        $this->assertSame([], $previa['vendas']);
    }

    public function test_venda_que_nao_e_afetada_e_recusada(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 1, 100);
        $outra = $this->vender($this->produto, 1, 50);

        $this->corrigir($this->entrada($p), 110, [$outra])->assertStatus(422)->assertJsonValidationErrors('vendas');

        $this->assertSame(0, AjusteCustoEstoque::count());
        $this->assertEquals(100, $this->custoMedio($p));
    }

    public function test_exige_motivo_e_custo_maior_que_zero(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 1, 100);
        $entrada = $this->entrada($p);

        $this->postJson("/api/estoques/movimentos/{$entrada->id}/corrigir-custo", ['custo_unitario' => 110])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->previa($entrada, 0)->assertStatus(422)->assertJsonValidationErrors('custo_unitario');
    }

    public function test_entrada_estornada_ou_movimento_que_nao_e_entrada_nao_podem_ser_corrigidos(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 1, 100);
        $entrada = $this->entrada($p);
        $this->vender($p, 1, 200);
        $venda = MovimentoEstoque::where('tipo', 'venda')->firstOrFail();

        $this->previa($venda, 50)->assertStatus(422)->assertJsonValidationErrors('movimento');

        $outro = $this->novoProduto('Cabo');
        $this->darEntrada($outro, 1, 10);
        $estornada = $this->entrada($outro);
        app(EstoqueService::class)->estornarMovimento($estornada);

        $this->previa($estornada->fresh(), 20)->assertStatus(422)->assertJsonValidationErrors('movimento');
        $this->assertSame('entrada', $entrada->tipo);
    }

    public function test_historico_que_nao_bate_com_o_saldo_bloqueia_a_correcao(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 2, 100);
        EstoqueSaldo::where('produto_id', $p->id)->update(['quantidade' => 7]); // saldo mexido por fora, sem movimento

        $this->previa($this->entrada($p), 110)->assertStatus(422)->assertJsonValidationErrors('movimento');
    }

    public function test_exige_permissao_de_editar_estoque(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 1, 100);
        $entrada = $this->entrada($p);

        $this->usuario->revokePermissionTo('estoque.edit');
        $this->usuario->unsetRelation('permissions');

        $this->previa($entrada, 110)->assertForbidden();
        $this->corrigir($entrada, 110, [])->assertForbidden();
        $this->getJson("/api/estoques/movimentos/{$entrada->id}/custo")->assertForbidden();
    }

    public function test_historico_marca_entradas_corrigidas_e_mostra_o_custo_original(): void
    {
        $p = $this->novoProduto();
        $this->darEntrada($p, 1, 100);
        $entrada = $this->entrada($p);

        $antes = collect($this->getJson("/api/estoques/{$this->estoque->id}/movimentos?produto_id={$p->id}")->assertOk()->json('data'))
            ->firstWhere('id', $entrada->id);
        $this->assertTrue($antes['pode_corrigir_custo']);
        $this->assertFalse($antes['custo_corrigido']);

        $this->corrigir($entrada, 110, [])->assertOk();

        $depois = collect($this->getJson("/api/estoques/{$this->estoque->id}/movimentos?produto_id={$p->id}")->json('data'))
            ->firstWhere('id', $entrada->id);
        $this->assertTrue($depois['custo_corrigido']);
        $this->assertEquals(100, $depois['custo_original']);
        $this->assertEquals(110, $depois['custo_unitario']);
    }
}
