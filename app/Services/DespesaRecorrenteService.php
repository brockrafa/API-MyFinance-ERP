<?php

namespace App\Services;

use App\Models\DespesaRecorrente;
use App\Models\LancamentoFinanceiro;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DespesaRecorrenteService
{
    /**
     * Lança as despesas recorrentes ativas do mês (formato Y-m) que ainda não foram lançadas.
     * Idempotente: pode rodar várias vezes no mesmo mês sem duplicar.
     * Com $empresaId nulo, processa todas as empresas (uso do agendador, sem usuário logado).
     */
    public function lancarMes(string $mes, ?int $empresaId = null): int
    {
        [$ano, $numMes] = array_map('intval', explode('-', $mes));
        $diasNoMes = Carbon::create($ano, $numMes, 1)->daysInMonth;
        $lancadas = 0;

        DB::transaction(function () use ($mes, $ano, $numMes, $diasNoMes, $empresaId, &$lancadas) {
            $recorrentes = DespesaRecorrente::withoutGlobalScopes()
                ->where('ativa', true)
                ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
                ->get();

            $jaLancadas = LancamentoFinanceiro::withoutGlobalScopes()
                ->where('tipo', 'saida')
                ->whereNotNull('conta_pagar_id')
                ->whereRaw("DATE_FORMAT(data_vencimento, '%Y-%m') = ?", [$mes])
                ->pluck('conta_pagar_id')
                ->all();

            foreach ($recorrentes as $rec) {
                if (in_array($rec->id, $jaLancadas, true)) {
                    continue;
                }

                $dia = min((int) $rec->dia_vencimento, $diasNoMes);

                LancamentoFinanceiro::withoutGlobalScopes()->create([
                    'empresa_id' => $rec->empresa_id,
                    'tipo' => 'saida',
                    'descricao' => $rec->descricao,
                    'fornecedor' => $rec->fornecedor,
                    'numero_parcela' => 1,
                    'total_parcelas' => 1,
                    'valor' => $rec->valor,
                    'data_vencimento' => Carbon::create($ano, $numMes, $dia)->format('Y-m-d'),
                    'status' => 'pendente',
                    'conta_pagar_id' => $rec->id,
                    'categoria_id' => $rec->categoria_id,
                ]);
                $lancadas++;
            }
        });

        return $lancadas;
    }
}
