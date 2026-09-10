<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transferencias_estoque', function (Blueprint $table): void {
            $table->decimal('frete_total', 15, 4)->default(0)->after('usuario_id');
        });

        Schema::table('itens_transferencia_estoque', function (Blueprint $table): void {
            $table->decimal('frete_rateado', 15, 4)->default(0)->after('custo_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('itens_transferencia_estoque', function (Blueprint $table): void {
            $table->dropColumn('frete_rateado');
        });

        Schema::table('transferencias_estoque', function (Blueprint $table): void {
            $table->dropColumn('frete_total');
        });
    }
};