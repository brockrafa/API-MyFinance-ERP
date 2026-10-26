<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AjusteCustoEstoque extends Model
{
    use HasEmpresaScope;

    protected $table = 'ajustes_custo_estoque';

    protected $fillable = [
        'empresa_id',
        'movimento_estoque_id',
        'estoque_id',
        'produto_id',
        'usuario_id',
        'custo_anterior',
        'custo_novo',
        'motivo',
        'vendas_reprecificadas',
        'vendas_mantidas',
    ];

    protected $casts = [
        'custo_anterior' => 'decimal:4',
        'custo_novo' => 'decimal:4',
        'vendas_reprecificadas' => 'integer',
        'vendas_mantidas' => 'integer',
    ];

    public function movimento(): BelongsTo
    {
        return $this->belongsTo(MovimentoEstoque::class, 'movimento_estoque_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
