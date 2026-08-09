<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Produto;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pode_criar_uma_agenda_para_um_cliente(): void
    {
        $empresa = Empresa::create([
            'nome' => 'Empresa Teste',
            'cnpj' => '00000000000',
        ]);

        $usuario = User::factory()->create([
            'empresa_id' => $empresa->id,
            'email' => 'agenda@teste.com',
            'password' => bcrypt('12345678'),
        ]);

        foreach (['agenda.view', 'agenda.create', 'agenda.edit', 'agenda.delete'] as $permissao) {
            Permission::firstOrCreate(['name' => $permissao, 'guard_name' => 'sanctum']);
        }

        $usuario->givePermissionTo(['agenda.view', 'agenda.create', 'agenda.edit', 'agenda.delete']);

        $cliente = Cliente::create([
            'nome' => 'Cliente Agenda',
            'email' => 'cliente@agenda.com',
            'empresa_id' => $empresa->id,
        ]);

        $servico = Servico::create([
            'servico' => 'Corte',
            'valor' => 80,
            'categoria' => 1,
            'empresa_id' => $empresa->id,
        ]);

        $produto = Produto::create([
            'produto' => 'Shampoo',
            'estoque' => 10,
            'valor' => 20,
            'valor_venda' => 20,
            'categoria' => 1,
            'empresa_id' => $empresa->id,
        ]);

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/agendas', [
            'cliente_id' => $cliente->id,
            'servico_id' => $servico->id,
            'produto_id' => $produto->id,
            'usuario_id' => $usuario->id,
            'data_agendamento' => '2026-08-10',
            'hora_agendamento' => '14:30',
            'observacao' => 'Cliente preferencial',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('cliente_id', $cliente->id)
            ->assertJsonPath('servico_id', $servico->id)
            ->assertJsonPath('produto_id', $produto->id)
            ->assertJsonPath('usuario_id', $usuario->id);
    }
}
