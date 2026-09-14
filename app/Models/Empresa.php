<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    protected $fillable = ['nome', 'cnpj', 'contrato_ref', 'ativo', 'segmento'];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}