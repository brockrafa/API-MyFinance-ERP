<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Servico extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $fillable = [
        'servico',
        'valor',
        'categoria',
        'duracao_minutos',
        'preco_base_operacional',
        'valor_venda',
        'gera_agendamento',
        'empresa_id'
    ];

    protected $casts = [
        'gera_agendamento' => 'boolean',
    ];
}
