<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferenciaEstoque extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $table = 'transferencias_estoque';

    protected $fillable = ['empresa_id', 'estoque_origem_id', 'estoque_destino_id', 'usuario_id', 'frete_total', 'data_transferencia', 'observacao'];

    protected $casts = ['frete_total' => 'decimal:4', 'data_transferencia' => 'date'];

    public function origem(): BelongsTo
    {
        return $this->belongsTo(Estoque::class, 'estoque_origem_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Estoque::class, 'estoque_destino_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemTransferenciaEstoque::class);
    }
}