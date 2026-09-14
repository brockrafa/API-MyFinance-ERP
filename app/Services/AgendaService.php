<?php

namespace App\Services;

use App\Models\Servico;
use Carbon\Carbon;

class AgendaService
{
    private const DURACAO_PADRAO_MINUTOS = 30;

    public function calcularDuracaoTotal(array $items, ?int $duracaoManual): int
    {
        $servicoIds = collect($items)
            ->where('tipo', 'servico')
            ->pluck('id')
            ->filter()
            ->unique();

        $duracoesPorServico = $servicoIds->isEmpty()
            ? collect()
            : Servico::whereIn('id', $servicoIds)->pluck('duracao_minutos', 'id');

        $duracaoServicos = collect($items)
            ->where('tipo', 'servico')
            ->sum(function (array $item) use ($duracoesPorServico) {
                $duracaoServico = $duracoesPorServico->get($item['id']);
                if (!$duracaoServico) {
                    return 0;
                }

                return $duracaoServico * ($item['quantidade'] ?? 1);
            });

        if ($duracaoServicos > 0) {
            return (int) $duracaoServicos;
        }

        return $duracaoManual ?? self::DURACAO_PADRAO_MINUTOS;
    }

    public function calcularHoraFim(string $dataAgendamento, string $horaAgendamento, int $duracaoMinutos): string
    {
        return Carbon::parse("{$dataAgendamento} {$horaAgendamento}")
            ->addMinutes($duracaoMinutos)
            ->format('H:i');
    }
}
