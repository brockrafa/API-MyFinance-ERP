<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Gatilho das tarefas agendadas para ambientes sem processo contínuo (Cloud Run).
 * O Cloud Scheduler chama POST /api/internal/cron/{tarefa} com o header X-Cron-Token.
 */
class CronController extends Controller
{
    private const TAREFAS = [
        'atrasos' => 'lancamentos:atualizar-atrasos',
        'recorrentes' => 'despesas:lancar-recorrentes',
    ];

    public function executar(Request $request, string $tarefa)
    {
        $esperado = (string) config('services.cron.token');
        $recebido = (string) $request->header('X-Cron-Token', '');

        if ($esperado === '' || !hash_equals($esperado, $recebido)) {
            return response()->json(['message' => 'Não autorizado.'], Response::HTTP_UNAUTHORIZED);
        }

        $comando = self::TAREFAS[$tarefa] ?? null;
        if (!$comando) {
            return response()->json(['message' => 'Tarefa desconhecida.'], Response::HTTP_NOT_FOUND);
        }

        Artisan::call($comando);

        return response()->json(['tarefa' => $tarefa, 'saida' => trim(Artisan::output())]);
    }
}
