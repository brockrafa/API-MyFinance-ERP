<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            // casa | mercado_livre | outro
            $table->string('canal', 30)->default('casa')->after('tipo_venda');
            // Valor que realmente entrou depois dos descontos do canal (comissão, tarifa, frete...).
            // A taxa do canal é derivada: total - valor_liquido_recebido.
            $table->decimal('valor_liquido_recebido', 10, 2)->nullable()->after('canal');
            // Custos digitados na própria venda (embalagem etc.), além do custo dos itens.
            $table->decimal('custos_adicionais', 10, 2)->default(0)->after('valor_liquido_recebido');

            $table->index(['empresa_id', 'canal']);
        });
    }

    public function down(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'canal']);
            $table->dropColumn(['canal', 'valor_liquido_recebido', 'custos_adicionais']);
        });
    }
};
