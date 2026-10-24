<?php

namespace Tests\Feature;

use App\Models\DespesaRecorrente;
use App\Models\LancamentoFinanceiro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CriaCenarioDeVenda;
use Tests\TestCase;

class RotinasAgendadasTest extends TestCase
{
    use RefreshDatabase, CriaCenarioDeVenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenario();
    }

    private function parcela(string $vencimento, string $status = 'pendente', string $tipo = 'entrada'): LancamentoFinanceiro
    {
        return LancamentoFinanceiro::create([
            'empresa_id' => $this->empresa->id,
            'tipo' => $tipo,
            'cliente_id' => $this->cliente->id,
            'numero_parcela' => 1,
            'total_parcelas' => 1,
            'valor' => 100,
            'data_vencimento' => $vencimento,
            'status' => $status,
        ]);
    }

    public function test_marcar_atrasados_so_atinge_entradas_pendentes_vencidas(): void
    {
        $vencida = $this->parcela(now()->subDay()->toDateString());
        $hoje = $this->parcela(now()->toDateString());
        $futura = $this->parcela(now()->addDays(3)->toDateString());
        $paga = $this->parcela(now()->subDays(10)->toDateString(), 'pago');
        $saida = $this->parcela(now()->subDays(10)->toDateString(), 'pendente', 'saida');

        $this->assertSame(1, LancamentoFinanceiro::marcarAtrasados());

        $this->assertSame('atrasado', $vencida->fresh()->status);
        $this->assertSame('pendente', $hoje->fresh()->status);
        $this->assertSame('pendente', $futura->fresh()->status);
        $this->assertSame('pago', $paga->fresh()->status);
        $this->assertSame('pendente', $saida->fresh()->status);
    }

    public function test_comando_de_atrasos_atualiza_todas_as_empresas(): void
    {
        $vencida = $this->parcela(now()->subDay()->toDateString());

        $this->artisan('lancamentos:atualizar-atrasos')->assertSuccessful();

        $this->assertSame('atrasado', LancamentoFinanceiro::withoutGlobalScopes()->find($vencida->id)->status);
    }

    public function test_lancar_recorrentes_nao_duplica_e_respeita_o_ultimo_dia_do_mes(): void
    {
        DespesaRecorrente::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Internet', 'valor' => 99.9, 'dia_vencimento' => 31, 'ativa' => true]);
        DespesaRecorrente::create(['empresa_id' => $this->empresa->id, 'descricao' => 'Inativa', 'valor' => 10, 'dia_vencimento' => 5, 'ativa' => false]);

        $this->artisan('despesas:lancar-recorrentes', ['--mes' => '2026-11'])->assertSuccessful();
        $this->artisan('despesas:lancar-recorrentes', ['--mes' => '2026-11'])->assertSuccessful();

        $lancados = LancamentoFinanceiro::withoutGlobalScopes()->where('tipo', 'saida')->get();
        $this->assertCount(1, $lancados);
        $this->assertSame('2026-11-30', $lancados->first()->data_vencimento->toDateString());
    }

    public function test_lancar_recorrentes_recusa_mes_invalido(): void
    {
        $this->artisan('despesas:lancar-recorrentes', ['--mes' => '2026-13'])->assertFailed();
    }

    public function test_endpoint_de_cron_exige_o_token(): void
    {
        config(['services.cron.token' => 'segredo-de-teste']);

        $this->postJson('/api/internal/cron/atrasos')->assertUnauthorized();
        $this->postJson('/api/internal/cron/atrasos', [], ['X-Cron-Token' => 'errado'])->assertUnauthorized();
        $this->postJson('/api/internal/cron/atrasos', [], ['X-Cron-Token' => 'segredo-de-teste'])->assertOk()->assertJsonPath('tarefa', 'atrasos');
        $this->postJson('/api/internal/cron/inexistente', [], ['X-Cron-Token' => 'segredo-de-teste'])->assertNotFound();
    }

    public function test_endpoint_de_cron_fica_bloqueado_sem_token_configurado(): void
    {
        config(['services.cron.token' => null]);

        $this->postJson('/api/internal/cron/atrasos', [], ['X-Cron-Token' => ''])->assertUnauthorized();
    }
}
