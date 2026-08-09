<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgendaItem extends Model
{
    use HasFactory, HasEmpresaScope;
    protected $table = 'agenda_itens';

    protected $fillable = [
        'agenda_id',
        'empresa_id',
        'produto_id',
        'servico_id',
        'tipo',
        'quantidade',
        'valor_unitario',
    ];

    public function agenda()
    {
        return $this->belongsTo(Agenda::class);
    }

    public function produto()
    {
        return $this->belongsTo(Produto::class);
    }

    public function servico()
    {
        return $this->belongsTo(Servico::class);
    }
}
