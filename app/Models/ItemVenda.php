<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemVenda extends Model
{
    use HasFactory, HasEmpresaScope;
    public $timestamps = false;
    protected $fillable = ['venda_id', 'produto_id','servico_id','quantidade', 'valor_unitario', 'custo_unitario', 'custo_total', 'empresa_id'];

    protected $casts = [
        'quantidade' => 'integer',
        'valor_unitario' => 'decimal:2',
        'custo_unitario' => 'decimal:4',
        'custo_total' => 'decimal:4',
    ];

    public function produto()
    {
        return $this->belongsTo(Produto::class);
    }

    public function servico()
    {
        return $this->belongsTo(Servico::class);
    }
    
}
