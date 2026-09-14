<?php

namespace App\Console\Commands;

use App\Models\Agenda;
use App\Models\Profissional;
use App\Models\User;
use Illuminate\Console\Command;

class BackfillProfissionaisCommand extends Command
{
    /**
     * php artisan agenda:backfill-profissionais
     *   → Cria um Profissional espelho para cada User existente (idempotente)
     *     e preenche agendas.profissional_id a partir de agendas.usuario_id.
     */
    protected $signature = 'agenda:backfill-profissionais';

    protected $description = 'Cria profissionais espelho para usuários existentes e preenche profissional_id nas agendas antigas';

    public function handle(): int
    {
        $this->info('Criando profissionais espelho para usuários existentes...');

        $criados = 0;

        User::orderBy('id')->chunk(200, function ($users) use (&$criados) {
            foreach ($users as $user) {
                $existe = Profissional::withoutGlobalScopes()
                    ->where('user_id', $user->id)
                    ->exists();

                if (!$existe) {
                    Profissional::withoutGlobalScopes()->create([
                        'empresa_id' => $user->empresa_id,
                        'user_id' => $user->id,
                        'nome' => $user->name,
                        'cargo' => null,
                        'ativo' => $user->ativo ?? true,
                    ]);
                    $criados++;
                }
            }
        });

        $this->info("Profissionais criados: {$criados}");

        $this->info('Preenchendo profissional_id nas agendas existentes...');

        $atualizadas = 0;

        Agenda::withoutGlobalScopes()
            ->whereNull('profissional_id')
            ->whereNotNull('usuario_id')
            ->chunkById(200, function ($agendas) use (&$atualizadas) {
                foreach ($agendas as $agenda) {
                    $profissional = Profissional::withoutGlobalScopes()
                        ->where('user_id', $agenda->usuario_id)
                        ->where('empresa_id', $agenda->empresa_id)
                        ->first();

                    if ($profissional) {
                        $agenda->profissional_id = $profissional->id;
                        $agenda->saveQuietly();
                        $atualizadas++;
                    }
                }
            });

        $this->info("Agendas atualizadas: {$atualizadas}");

        $pendentes = Agenda::withoutGlobalScopes()
            ->whereNull('profissional_id')
            ->whereNotNull('usuario_id')
            ->count();

        if ($pendentes > 0) {
            $this->warn("Atenção: {$pendentes} agendas ainda sem profissional_id (usuario_id sem profissional correspondente na mesma empresa).");
        }

        $this->info('Backfill concluído.');

        return self::SUCCESS;
    }
}
