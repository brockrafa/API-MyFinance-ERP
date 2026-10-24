<?php

namespace App\Console\Commands;

use App\Models\LancamentoFinanceiro;
use Illuminate\Console\Command;

class AtualizarAtrasosCommand extends Command
{
    protected $signature = 'lancamentos:atualizar-atrasos';

    protected $description = 'Marca como atrasadas as parcelas a receber pendentes com vencimento anterior a hoje (todas as empresas)';

    public function handle(): int
    {
        $total = LancamentoFinanceiro::marcarAtrasados(todasEmpresas: true);

        $this->info("{$total} parcela(s) marcada(s) como atrasada(s).");

        return self::SUCCESS;
    }
}
