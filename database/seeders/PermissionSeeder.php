<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Models\Empresa;
use App\Models\User;
use App\Models\Cliente;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Cadastros - Clientes
            'cadastros.clientes.view',
            'cadastros.clientes.create',
            'cadastros.clientes.edit',
            'cadastros.clientes.delete',

            // Cadastros - Produtos
            'cadastros.produtos.view',
            'cadastros.produtos.create',
            'cadastros.produtos.edit',
            'cadastros.produtos.delete',

            // Cadastros - Categorias
            'cadastros.categorias.view',
            'cadastros.categorias.create',
            'cadastros.categorias.edit',
            'cadastros.categorias.delete',

            // Estoque
            'estoque.view',
            'estoque.create',
            'estoque.edit',
            'estoque.delete',

            // Cadastros - Serviços
            'cadastros.servicos.view',
            'cadastros.servicos.create',
            'cadastros.servicos.edit',
            'cadastros.servicos.delete',

            // Cadastros - Forma de Pagamento
            'cadastros.formas-pagamento.view',
            'cadastros.formas-pagamento.create',
            'cadastros.formas-pagamento.edit',
            'cadastros.formas-pagamento.delete',

            // Cadastros - Usuários
            'cadastros.usuarios.view',
            'cadastros.usuarios.create',
            'cadastros.usuarios.edit',
            'cadastros.usuarios.delete',

            // Cadastros - Empresas
            'cadastros.empresas.create',

            // Agenda
            'agenda.view',
            'agenda.create',
            'agenda.edit',
            'agenda.delete',

            // Vendas
            'vendas.view',
            'vendas.create',
            'vendas.edit',
            'vendas.delete',

            // Financeiro - Lançamentos (Contas a Receber)
            'financeiro.lancamentos.view',
            'financeiro.lancamentos.create',
            'financeiro.lancamentos.edit',
            'financeiro.lancamentos.delete',
            'financeiro.lancamentos.baixar',

            // Financeiro - Contas a Pagar
            'financeiro.contas-pagar.view',
            'financeiro.contas-pagar.create',
            'financeiro.contas-pagar.edit',
            'financeiro.contas-pagar.delete',

            // Financeiro - Despesas Recorrentes
            'financeiro.despesas-recorrentes.view',
            'financeiro.despesas-recorrentes.create',
            'financeiro.despesas-recorrentes.edit',
            'financeiro.despesas-recorrentes.delete',
        ];

        try{
            DB::beginTransaction();
            foreach ($permissions as $permissao) {
                Permission::firstOrCreate(['name' => $permissao, 'guard_name' => 'sanctum']);
            }

            $empresa = Empresa::create([
                'nome' => 'Brock Solution',
                'cnpj' => '00000000000',
            ]);

            $user = User::create([
                'empresa_id' => $empresa->id,
                'name' => 'admin',
                'email' => 'admin@brocksolution.com',
                'password' => Hash::make('12345678'),
            ]);

            $cliente = Cliente::create([
                'nome' => 'Cliente Demo',
                'email' => 'cliente@brocksolution.com',
                'empresa_id' => $empresa->id,
            ]);

            $categoria = \App\Models\Categoria::create([
                'nome' => 'Categoria Demo',
                'empresa_id' => $empresa->id,
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);

            $role = Role::where('name', 'admin')->where('guard_name', 'sanctum')->where('empresa_id', $empresa->id)->first();
            $user->assignRole($role);
            DB::commit();
        }
        catch (\Exception $e) {
            DB::rollBack();
            echo "Erro ao criar permissões ou usuarios: " . $e->getMessage();
        }
        
    }
}