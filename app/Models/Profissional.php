<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profissional extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $table = 'profissionais';

    protected $fillable = [
        'empresa_id',
        'user_id',
        'nome',
        'cargo',
        'ativo',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function agendas()
    {
        return $this->hasMany(Agenda::class);
    }
}
