<?php

namespace Tests\Concerns;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Estoque;
use App\Models\EstoqueSaldo;
use App\Models\FormaPagamento;
use App\Models\Produto;
use App\Models\User;
use App\Services\EstoqueService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait CriaCenarioDeVenda
{
    protected Empresa $empresa;
    protected User $usuario;
    protected Cliente $cliente;
    protected Estoque $estoque;
    protected Produto $produto;
    protected FormaPagamento $forma;

    /** Uma empresa, um usuário com permissões, um cliente, um estoque e um produto com 10 unidades a R$ 20,00. */
    protected function prepararCenario(array $permissoes = []): void
    {
        $this->empresa = Empresa::create(['nome' => 'Empresa Teste', 'cnpj' => '12345678000199']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->empresa->id);

        $this->usuario = User::factory()->create([
            'empresa_id' => $this->empresa->id,
            'email' => 'teste@erp.local',
            'password' => bcrypt('12345678'),
        ]);

        $padrao = [
            'vendas.view', 'vendas.create', 'vendas.edit', 'vendas.delete',
            'cadastros.produtos.view', 'cadastros.produtos.create', 'cadastros.produtos.edit',
            'cadastros.clientes.view', 'financeiro.lancamentos.view', 'estoque.view', 'estoque.create',
        ];

        foreach (array_unique(array_merge($padrao, $permissoes)) as $permissao) {
            Permission::firstOrCreate(['name' => $permissao, 'guard_name' => 'sanctum']);
            $this->usuario->givePermissionTo($permissao);
        }

        $this->cliente = Cliente::factory()->create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste']);
        $this->forma = FormaPagamento::create(['descricao' => 'Pix']);
        $this->estoque = Estoque::create(['empresa_id' => $this->empresa->id, 'nome' => 'Casa', 'codigo' => 'CASA']);
        $this->produto = Produto::factory()->create([
            'empresa_id' => $this->empresa->id,
            'estoque' => 0,
            'produto' => 'Produto Teste',
            'valor_venda' => 50,
        ]);

        $this->actingAs($this->usuario, 'sanctum');
        $this->darEntrada($this->produto, 10, 20);
    }

    protected function darEntrada(Produto $produto, int $quantidade, float $custo, float $frete = 0): void
    {
        app(EstoqueService::class)->registrarEntrada($this->estoque, [
            'tipo' => 'compra',
            'frete_total' => $frete,
            'itens' => [['produto_id' => $produto->id, 'quantidade' => $quantidade, 'custo_unitario' => $custo]],
        ]);
    }

    protected function saldo(?Produto $produto = null): int
    {
        return (int) EstoqueSaldo::where('estoque_id', $this->estoque->id)
            ->where('produto_id', ($produto ?? $this->produto)->id)
            ->value('quantidade');
    }

    protected function payloadVenda(array $produtos, array $condicao = ['tipo' => 'avista'], array $extra = []): array
    {
        return array_merge([
            'cliente_id' => $this->cliente->id,
            'estoque_id' => $this->estoque->id,
            'produtos' => $produtos,
            'forma_pagamento' => ['id' => $this->forma->id],
            'condicao_pagamento' => $condicao,
            'data_venda' => '2026-10-20',
        ], $extra);
    }

    protected function itemProduto(int $quantidade, float $valor = 50): array
    {
        return ['id' => $this->produto->id, 'tipo' => 'produto', 'quantidade' => $quantidade, 'valor' => $valor];
    }
}
