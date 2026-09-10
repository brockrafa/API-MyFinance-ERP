<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemEntradaEstoque extends Model
{
    use HasFactory;

    protected $table = 'itens_entrada_estoque';

    protected $fillable = ['entrada_estoque_id', 'produto_id', 'quantidade', 'custo_unitario', 'frete_rateado'];

    protected $casts = ['quantidade' => 'integer', 'custo_unitario' => 'decimal:4', 'frete_rateado' => 'decimal:4'];

    public function entrada(): BelongsTo
    {
        return $this->belongsTo(EntradaEstoque::class, 'entrada_estoque_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}