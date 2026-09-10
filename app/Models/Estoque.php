<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estoque extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $fillable = [
        'nome',
        'codigo',
        'cidade',
        'ativo',
        'empresa_id',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function saldos(): HasMany
    {
        return $this->hasMany(EstoqueSaldo::class);
    }

    public function movimentos(): HasMany
    {
        return $this->hasMany(MovimentoEstoque::class);
    }
}