<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimentoEstoque extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $table = 'movimentos_estoque';

    protected $fillable = [
        'empresa_id',
        'estoque_id',
        'produto_id',
        'tipo',
        'quantidade',
        'custo_unitario',
        'custo_total',
        'origem_tipo',
        'origem_id',
        'usuario_id',
        'observacao',
        'movimentado_em',
        'estornado_em',
        'estornado_por_id',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'custo_unitario' => 'decimal:4',
        'custo_total' => 'decimal:4',
        'movimentado_em' => 'datetime',
        'estornado_em' => 'datetime',
    ];

    public function estoque(): BelongsTo
    {
        return $this->belongsTo(Estoque::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function estornadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'estornado_por_id');
    }

    public function estaEstornado(): bool
    {
        return $this->estornado_em !== null;
    }
}