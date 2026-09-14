<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Produto extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $fillable = [
        'produto',
        'valor',
        'valor_venda',
        'categoria_id',
        'empresa_id'
    ];

    public function vendas()
    {
        return $this->belongsToMany(Venda::class, 'item_vendas');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }
}
