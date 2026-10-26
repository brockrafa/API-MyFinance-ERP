<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trilha de auditoria das correções de custo de entradas de estoque.
        Schema::create('ajustes_custo_estoque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('movimento_estoque_id')->constrained('movimentos_estoque')->restrictOnDelete();
            $table->foreignId('estoque_id')->constrained('estoques')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            // Custo unitário da entrada (com frete rateado) antes e depois da correção
            $table->decimal('custo_anterior', 15, 4);
            $table->decimal('custo_novo', 15, 4);
            $table->string('motivo');
            $table->unsignedInteger('vendas_reprecificadas')->default(0);
            $table->unsignedInteger('vendas_mantidas')->default(0);
            $table->timestamps();

            $table->index(['empresa_id', 'produto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_custo_estoque');
    }
};
