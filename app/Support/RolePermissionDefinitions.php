<?php

namespace App\Support;

use Spatie\Permission\Models\Permission;

class RolePermissionDefinitions
{
    /**
     * Define todas as roles padrão e suas permissões.
     * Usado tanto pelo EmpresaObserver (empresa nova) quanto pelo
     * Command roles:update (empresa existente).
     */

    public static function all(): array
    {
        return [
            'admin' => [
                '*',
            ],
            'gestor' => [
                'cadastros.clientes.view',
                'cadastros.clientes.create',
                'cadastros.clientes.edit',
                'cadastros.clientes.delete',

                'cadastros.produtos.view',
                'cadastros.produtos.create',
                'cadastros.produtos.edit',
                'cadastros.produtos.delete',

                'cadastros.servicos.view',
                'cadastros.servicos.create',
                'cadastros.servicos.edit',
                'cadastros.servicos.delete',

                'cadastros.categorias.view',
                'cadastros.categorias.create',
                'cadastros.categorias.edit',
                'cadastros.categorias.delete',

                'estoque.view',
                'estoque.create',
                'estoque.edit',
                'estoque.delete',

                'agenda.view',
                'agenda.create',
                'agenda.edit',
                'agenda.delete',

                'cadastros.profissionais.view',
                'cadastros.profissionais.create',
                'cadastros.profissionais.edit',
                'cadastros.profissionais.delete',

                'vendas.view',
                'vendas.create',
                'vendas.edit',
                'vendas.delete',

                'financeiro.lancamentos.view',
                'financeiro.lancamentos.create',
                'financeiro.lancamentos.edit',
                'financeiro.lancamentos.delete',
                'financeiro.lancamentos.baixar',

                'financeiro.contas-pagar.view',
                'financeiro.contas-pagar.create',
                'financeiro.contas-pagar.edit',
                'financeiro.contas-pagar.delete',

                'financeiro.despesas-recorrentes.view',
                'financeiro.despesas-recorrentes.create',
                'financeiro.despesas-recorrentes.edit',
                'financeiro.despesas-recorrentes.delete',

                'cadastros.usuarios.view',
                'cadastros.usuarios.create',
                'cadastros.usuarios.edit',
                'cadastros.usuarios.delete',

                'configuracoes.empresa.edit',
            ],
            'usuario' => [
                'cadastros.clientes.view',
                'cadastros.produtos.view',
                'cadastros.categorias.view',
                'cadastros.formas-pagamento.view',
                'estoque.view',
                'agenda.view',
                'agenda.create',
                'agenda.edit',
                'agenda.delete',
                'cadastros.profissionais.view',
            ],
            'vendedor' => [
                'cadastros.clientes.view',
                'cadastros.clientes.create',
                'cadastros.clientes.edit',
                'cadastros.clientes.delete',

                'cadastros.produtos.view',
                'cadastros.produtos.create',

                'cadastros.servicos.view',
                'cadastros.servicos.create',

                'cadastros.categorias.view',
                'cadastros.categorias.create',
                'cadastros.categorias.edit',

                'cadastros.formas-pagamento.view',

                'estoque.view',

                'agenda.view',
                'agenda.create',
                'cadastros.profissionais.view',

                'vendas.view',
                'vendas.create',
                'vendas.edit',
            ],
            'financeiro' => [
                'financeiro.lancamentos.view',
                'financeiro.lancamentos.baixar',
            ],
        ];
    }

    public static function allPermissionNames(): array
    {
        $permissoes = [];

        foreach (self::all() as $roleName => $permissoesDaRole) {
            foreach ($permissoesDaRole as $permissao) {
                if ($permissao === '*') {
                    continue;
                }

                $permissoes[$permissao] = $permissao;
            }
        }

        return array_values($permissoes);
    }

    public static function resolvePermissions(array $permissoes): array
    {
        if (in_array('*', $permissoes, true)) {
            return Permission::where('guard_name', 'sanctum')->pluck('name')->toArray();
        }

        return $permissoes;
    }
}