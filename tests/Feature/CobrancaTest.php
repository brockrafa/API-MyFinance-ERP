<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\LancamentoFinanceiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CriaCenarioDeVenda;
use Tests\TestCase;

class CobrancaTest extends TestCase
{
    use RefreshDatabase, CriaCenarioDeVenda;

    private Cliente $clienteB;
    private Cliente $clienteC;
    private int $vendaA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenario(['configuracoes.empresa.edit']);

        $this->clienteB = Cliente::factory()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Quitado']);
        $this->clienteC = Cliente::factory()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Em Dia', 'telefone' => '45988887777']);

        // A deve 3 x R$ 50: duas parcelas vencidas (há 2 e 1 mês) e uma que vence hoje
        $this->vendaA = $this->venderAPrazo($this->cliente->id, 3, 3, now()->subMonths(2)->toDateString());
        // B comprou à vista (nada em aberto)
        $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(1)], extra: ['cliente_id' => $this->clienteB->id]))->assertCreated();
        // C deve 1 x R$ 50 que vence daqui a 10 dias
        $this->venderAPrazo($this->clienteC->id, 1, 1, now()->addDays(10)->toDateString());
    }

    private function venderAPrazo(int $clienteId, int $quantidade, int $parcelas, string $primeiroVencimento): int
    {
        $condicao = ['tipo' => 'prazo', 'parcelas' => $parcelas, 'entrada' => 0, 'primeiro_vencimento' => $primeiroVencimento];

        return $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto($quantidade)], $condicao, ['cliente_id' => $clienteId]))
            ->assertCreated()
            ->json('id');
    }

    private function pagarParcela(int $vendaId, int $numero, float $valor = 50): LancamentoFinanceiro
    {
        $parcela = LancamentoFinanceiro::where('venda_id', $vendaId)->where('numero_parcela', $numero)->firstOrFail();
        $parcela->update(['status' => 'pago', 'valor_pago' => $valor, 'data_pagamento' => now()->toDateString()]);

        return $parcela;
    }

    public function test_devedores_agrupa_por_cliente_e_ordena_pelo_maior_saldo(): void
    {
        $resposta = $this->getJson('/api/clientes/devedores')->assertOk();
        $devedores = collect($resposta->json('devedores'));

        $this->assertSame(['Cliente Teste', 'Cliente Em Dia'], $devedores->pluck('nome')->all());

        $a = $devedores->first();
        $this->assertEquals(150, $a['total_em_aberto']);
        $this->assertEquals(100, $a['total_vencido']);
        $this->assertSame(3, $a['qtd_parcelas']);
        $this->assertSame(2, $a['qtd_vencidas']);
        $this->assertGreaterThanOrEqual(28, $a['dias_atraso']);
        $this->assertSame(now()->subMonths(2)->toDateString(), $a['vencimento_mais_antigo']);

        $c = $devedores->last();
        $this->assertEquals(50, $c['total_em_aberto']);
        $this->assertEquals(0, $c['total_vencido']);
        $this->assertSame(0, $c['dias_atraso']);
        $this->assertSame('45988887777', $c['telefone']);

        $resposta->assertJsonPath('resumo.devedores', 2);
        $this->assertEquals(200, $resposta->json('resumo.total_em_aberto'));
        $this->assertEquals(100, $resposta->json('resumo.total_vencido'));
    }

    public function test_quem_quitou_sai_da_lista_de_devedores(): void
    {
        $this->pagarParcela($this->vendaA, 1);
        $this->pagarParcela($this->vendaA, 2);
        $this->pagarParcela($this->vendaA, 3);

        $nomes = collect($this->getJson('/api/clientes/devedores')->json('devedores'))->pluck('nome')->all();

        $this->assertSame(['Cliente Em Dia'], $nomes);
    }

    public function test_pagamento_parcial_reduz_o_saldo_em_aberto(): void
    {
        $this->pagarParcela($this->vendaA, 1);

        $a = collect($this->getJson('/api/clientes/devedores')->json('devedores'))->firstWhere('cliente_id', $this->cliente->id);

        $this->assertEquals(100, $a['total_em_aberto']);
        $this->assertEquals(50, $a['total_vencido']);
        $this->assertSame(2, $a['qtd_parcelas']);
    }

    public function test_extrato_mostra_compras_pagamentos_e_saldo_corrente(): void
    {
        $this->pagarParcela($this->vendaA, 1);

        $extrato = $this->getJson("/api/clientes/{$this->cliente->id}/extrato")->assertOk()->json();

        $this->assertSame('Cliente Teste', $extrato['cliente']['nome']);
        $this->assertEquals(150, $extrato['resumo']['total_comprado']);
        $this->assertEquals(50, $extrato['resumo']['total_pago']);
        $this->assertEquals(100, $extrato['resumo']['total_em_aberto']);
        $this->assertEquals(50, $extrato['resumo']['total_vencido']);
        $this->assertSame(2, $extrato['resumo']['qtd_parcelas_abertas']);

        $this->assertSame(['compra', 'pagamento'], array_column($extrato['movimentos'], 'tipo'));
        $this->assertEquals([150, 100], array_column($extrato['movimentos'], 'saldo'));
        $this->assertStringContainsString('Produto Teste', $extrato['movimentos'][0]['descricao']);

        $this->assertCount(2, $extrato['parcelas_abertas']);
        $this->assertTrue($extrato['parcelas_abertas'][0]['vencida']);
        $this->assertFalse($extrato['parcelas_abertas'][1]['vencida']);
    }

    public function test_extrato_de_cliente_inexistente_retorna_404(): void
    {
        $this->getJson('/api/clientes/9999/extrato')->assertNotFound();
    }

    public function test_recibo_da_venda_informa_total_pago_e_o_que_falta(): void
    {
        $this->pagarParcela($this->vendaA, 1);

        $resposta = $this->getJson("/api/vendas/{$this->vendaA}/recibo")->assertOk();

        $resposta->assertJsonPath('empresa.nome', 'Empresa Teste')
            ->assertJsonPath('recibo.numero', $this->vendaA)
            ->assertJsonPath('recibo.cliente.nome', 'Cliente Teste')
            ->assertJsonPath('recibo.itens.0.descricao', 'Produto Teste');
        $this->assertEquals(150, $resposta->json('recibo.total'));
        $this->assertEquals(50, $resposta->json('recibo.pago'));
        $this->assertEquals(100, $resposta->json('recibo.em_aberto'));
        $this->assertCount(2, $resposta->json('recibo.parcelas_abertas'));
    }

    public function test_recibo_de_venda_a_vista_aparece_quitado(): void
    {
        $id = $this->postJson('/api/vendas', $this->payloadVenda([$this->itemProduto(2)]))->json('id');

        $recibo = $this->getJson("/api/vendas/{$id}/recibo")->assertOk()->json('recibo');

        $this->assertEquals(100, $recibo['total']);
        $this->assertEquals(100, $recibo['pago']);
        $this->assertEquals(0, $recibo['em_aberto']);
    }

    public function test_recibo_do_pagamento_so_existe_para_parcela_paga(): void
    {
        $paga = $this->pagarParcela($this->vendaA, 1);
        $aberta = LancamentoFinanceiro::where('venda_id', $this->vendaA)->where('numero_parcela', 2)->firstOrFail();

        $resposta = $this->getJson("/api/lancamentos-financeiros/{$paga->id}/recibo")->assertOk();
        $this->assertEquals(50, $resposta->json('recibo.valor_pago'));
        $this->assertSame('1/3', $resposta->json('recibo.parcela'));
        $this->assertEquals(100, $resposta->json('recibo.restante_da_venda'));
        $this->assertSame(2, $resposta->json('recibo.parcelas_restantes'));

        $this->getJson("/api/lancamentos-financeiros/{$aberta->id}/recibo")->assertStatus(422);
    }

    public function test_cobranca_exige_permissao_de_contas_a_receber(): void
    {
        $this->usuario->revokePermissionTo('financeiro.lancamentos.view');
        $this->usuario->unsetRelation('permissions');

        $this->getJson('/api/clientes/devedores')->assertForbidden();
        $this->getJson("/api/clientes/{$this->cliente->id}/extrato")->assertForbidden();
    }

    public function test_devedores_de_outra_empresa_nao_aparecem(): void
    {
        $outra = Empresa::create(['nome' => 'Outra Empresa', 'cnpj' => '99999999000199']);
        $clienteOutra = Cliente::factory()->create(['empresa_id' => $outra->id, 'nome' => 'Devedor da Outra']);
        LancamentoFinanceiro::withoutGlobalScopes()->create([
            'empresa_id' => $outra->id, 'tipo' => 'entrada', 'cliente_id' => $clienteOutra->id,
            'numero_parcela' => 1, 'total_parcelas' => 1, 'valor' => 999,
            'data_vencimento' => now()->subDays(5)->toDateString(), 'status' => 'pendente',
        ]);

        $nomes = collect($this->getJson('/api/clientes/devedores')->json('devedores'))->pluck('nome');

        $this->assertFalse($nomes->contains('Devedor da Outra'));
        $this->getJson("/api/clientes/{$clienteOutra->id}/extrato")->assertNotFound();
    }

    public function test_modelo_da_mensagem_de_cobranca_e_salvo_na_empresa(): void
    {
        $modelo = 'Oi {nome}, você deve {valor}.';

        $this->putJson('/api/empresa', ['nome' => 'Empresa Teste', 'cnpj' => '12345678000199', 'mensagem_cobranca' => $modelo])
            ->assertOk()
            ->assertJsonPath('mensagem_cobranca', $modelo);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('empresa.mensagem_cobranca', $modelo);
    }
}
