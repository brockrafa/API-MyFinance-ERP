<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Agenda extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $fillable = [
        'cliente_id',
        'usuario_id',
        'data_agendamento',
        'hora_agendamento',
        'observacao',
        'status',
        'empresa_id',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function servico()
    {
        return $this->belongsTo(Servico::class);
    }

    public function produto()
    {
        return $this->belongsTo(Produto::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class);
    }

    public function itens()
    {
        return $this->hasMany(AgendaItem::class);
    }
}
