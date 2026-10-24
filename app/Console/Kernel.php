<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Em Cloud Run estas tarefas são disparadas pelo Cloud Scheduler (ver CronController);
        // o agendamento abaixo vale para execução local com `php artisan schedule:work`.
        $schedule->command('lancamentos:atualizar-atrasos')->dailyAt('00:10');
        $schedule->command('despesas:lancar-recorrentes')->monthlyOn(1, '00:20');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
