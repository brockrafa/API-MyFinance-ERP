<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntradaEstoque extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $table = 'entradas_estoque';

    protected $fillable = ['empresa_id', 'estoque_id', 'usuario_id', 'tipo', 'frete_total', 'data_entrada', 'observacao'];

    protected $casts = ['frete_total' => 'decimal:4', 'data_entrada' => 'date'];

    public function estoque(): BelongsTo
    {
        return $this->belongsTo(Estoque::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemEntradaEstoque::class);
    }
}