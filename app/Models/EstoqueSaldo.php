<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstoqueSaldo extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $table = 'estoque_saldos';

    protected $fillable = [
        'empresa_id',
        'estoque_id',
        'produto_id',
        'quantidade',
        'custo_medio',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'custo_medio' => 'decimal:4',
    ];

    public function estoque(): BelongsTo
    {
        return $this->belongsTo(Estoque::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}