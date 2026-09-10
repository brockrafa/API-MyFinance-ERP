<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entradas_estoque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('estoque_id')->constrained('estoques')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 30);
            $table->decimal('frete_total', 15, 4)->default(0);
            $table->date('data_entrada');
            $table->text('observacao')->nullable();
            $table->timestamps();
        });

        Schema::create('itens_entrada_estoque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entrada_estoque_id')->constrained('entradas_estoque')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->unsignedInteger('quantidade');
            $table->decimal('custo_unitario', 15, 4);
            $table->decimal('frete_rateado', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('transferencias_estoque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('estoque_origem_id')->constrained('estoques')->restrictOnDelete();
            $table->foreignId('estoque_destino_id')->constrained('estoques')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('data_transferencia');
            $table->text('observacao')->nullable();
            $table->timestamps();
        });

        Schema::create('itens_transferencia_estoque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transferencia_estoque_id')->constrained('transferencias_estoque')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->unsignedInteger('quantidade');
            $table->decimal('custo_unitario', 15, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_transferencia_estoque');
        Schema::dropIfExists('transferencias_estoque');
        Schema::dropIfExists('itens_entrada_estoque');
        Schema::dropIfExists('entradas_estoque');
    }
};