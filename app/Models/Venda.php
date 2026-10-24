<?php

namespace App\Models;

use App\Models\Concerns\HasEmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\LancamentoFinanceiro;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venda extends Model
{
    use HasFactory, HasEmpresaScope;
    protected $fillable = ['cliente_id', 'estoque_id', 'agenda_id', 'total', 'forma_pagamento', 'data_venda', 'tipo_venda','parcelas','entrada','valor_parcela','primeiro_vencimento','canal','valor_liquido_recebido','custos_adicionais','empresa_id'];

    public const CANAIS = ['venda_direta', 'mercado_livre', 'ecommerce'];

    protected $casts = [
        'total' => 'decimal:2',
        'valor_liquido_recebido' => 'decimal:2',
        'custos_adicionais' => 'decimal:2',
    ];

    /** Quanto a venda realmente rende: o valor líquido do canal quando informado, senão o total. */
    public function receitaLiquida(): float
    {
        if (($this->canal ?? 'venda_direta') !== 'venda_direta' && $this->valor_liquido_recebido !== null) {
            return (float) $this->valor_liquido_recebido;
        }

        return (float) $this->total;
    }

    /** Desconto do canal (comissão, tarifas, frete do vendedor): total menos o que foi recebido. */
    public function taxaCanal(): float
    {
        return round((float) $this->total - $this->receitaLiquida(), 2);
    }

    /**
     * Resultado da venda: receita líquida menos o custo dos itens (custo médio no momento da venda)
     * e os custos adicionais digitados na venda. Margem percentual é sobre a receita líquida.
     */
    public function resumoFinanceiro(): array
    {
        $itens = $this->relationLoaded('itens') ? $this->itens : $this->itens()->get();

        $custo = round($itens->sum(function ($item) {
            $custoTotal = (float) $item->custo_total;

            return $custoTotal > 0 ? $custoTotal : (float) $item->custo_unitario * (int) $item->quantidade;
        }), 2);

        $receita = $this->receitaLiquida();
        $adicionais = (float) $this->custos_adicionais;
        $margem = round($receita - $custo - $adicionais, 2);

        return [
            'canal' => $this->canal ?? 'venda_direta',
            'total' => round((float) $this->total, 2),
            'taxa_canal' => $this->taxaCanal(),
            'receita_liquida' => round($receita, 2),
            'custo' => $custo,
            'custos_adicionais' => round($adicionais, 2),
            'margem' => $margem,
            'margem_percentual' => $receita > 0 ? round($margem / $receita * 100, 1) : null,
        ];
    }

    public function estoque()
    {
        return $this->belongsTo(Estoque::class);
    }

    public function agenda()
    {
        return $this->belongsTo(Agenda::class);
    }

    public function produtos()
    {
        return $this->belongsToMany(Produto::class, 'item_vendas')
            ->select('produtos.id', 'produtos.produto', 'produtos.valor_venda')
            ->withPivot('quantidade', 'valor_unitario', 'custo_unitario', 'tipo');
    }

    public function itens()
    {
        return $this->hasMany(ItemVenda::class, 'venda_id');
    }
    

    public function lancamentos(): HasMany
    {
        return $this->hasMany(LancamentoFinanceiro::class);
    }

    public function cliente(){
        return $this->belongsTo(Cliente::class);
    }

    public function formaPagamento(){
        return $this->belongsTo(FormaPagamento::class);
    }
}
