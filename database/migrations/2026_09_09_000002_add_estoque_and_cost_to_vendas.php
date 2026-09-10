<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->foreignId('estoque_id')->nullable()->after('cliente_id')->constrained('estoques')->nullOnDelete();
        });

        Schema::table('item_vendas', function (Blueprint $table) {
            $table->decimal('custo_unitario', 15, 4)->nullable()->after('valor_unitario');
            $table->decimal('custo_total', 15, 4)->nullable()->after('custo_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('item_vendas', function (Blueprint $table) {
            $table->dropColumn(['custo_unitario', 'custo_total']);
        });

        Schema::table('vendas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estoque_id');
        });
    }
};