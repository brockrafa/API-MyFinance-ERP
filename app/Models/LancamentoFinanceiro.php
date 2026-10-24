<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LancamentoFinanceiro extends Model
{
    use HasFactory, HasEmpresaScope;

    protected $table = 'lancamentos_financeiros';

    protected $fillable = [
        'venda_id',
        'cliente_id',
        'numero_parcela',
        'total_parcelas',
        'valor',
        'valor_pago',
        'data_vencimento',
        'data_pagamento',
        'status',
        'tipo',
        'descricao',
        'fornecedor',
        'categoria_id',
        'forma_pagamento',
        'conta_pagar_id',
        'observacao',
        'empresa_id'
    ];

    protected $casts = [
        'data_vencimento' => 'date',
        'data_pagamento'  => 'date',
        'valor'           => 'decimal:2',
    ];

    /**
     * Marca como atrasadas as parcelas a receber pendentes já vencidas.
     * Sem usuário logado (agendador), use $todasEmpresas = true.
     */
    public static function marcarAtrasados(bool $todasEmpresas = false): int
    {
        $consulta = $todasEmpresas ? static::withoutGlobalScopes() : static::query();

        return $consulta
            ->where('tipo', 'entrada')
            ->where('status', 'pendente')
            ->whereDate('data_vencimento', '<', today())
            ->update(['status' => 'atrasado']);
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
