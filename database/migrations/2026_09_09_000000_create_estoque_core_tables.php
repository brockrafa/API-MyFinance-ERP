<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nome');
            $table->string('codigo', 50);
            $table->string('cidade')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo']);
            $table->index(['empresa_id', 'ativo']);
        });

        Schema::create('estoque_saldos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('estoque_id')->constrained('estoques')->cascadeOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->unsignedInteger('quantidade')->default(0);
            $table->decimal('custo_medio', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['estoque_id', 'produto_id']);
            $table->index(['empresa_id', 'produto_id']);
        });

        Schema::create('movimentos_estoque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('estoque_id')->constrained('estoques')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->string('tipo', 30);
            $table->integer('quantidade');
            $table->decimal('custo_unitario', 15, 4)->default(0);
            $table->decimal('custo_total', 15, 4)->default(0);
            $table->string('origem_tipo', 50)->nullable();
            $table->unsignedBigInteger('origem_id')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();
            $table->timestamp('movimentado_em');
            $table->timestamps();

            $table->index(['empresa_id', 'estoque_id', 'produto_id']);
            $table->index(['origem_tipo', 'origem_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentos_estoque');
        Schema::dropIfExists('estoque_saldos');
        Schema::dropIfExists('estoques');
    }
};