<?php

namespace App\Console\Commands;

use App\Services\DespesaRecorrenteService;
use Illuminate\Console\Command;

class LancarDespesasRecorrentesCommand extends Command
{
    protected $signature = 'despesas:lancar-recorrentes {--mes= : Mês no formato Y-m (padrão: mês atual)}';

    protected $description = 'Lança as despesas recorrentes ativas do mês em contas a pagar (todas as empresas, sem duplicar)';

    public function handle(DespesaRecorrenteService $service): int
    {
        $mes = $this->option('mes') ?: now()->format('Y-m');

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $this->error('Informe o mês no formato Y-m, por exemplo 2026-10.');

            return self::FAILURE;
        }

        $lancadas = $service->lancarMes($mes);

        $this->info("{$lancadas} despesa(s) recorrente(s) lançada(s) em {$mes}.");

        return self::SUCCESS;
    }
}
