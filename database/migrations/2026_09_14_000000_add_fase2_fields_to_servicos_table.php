<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servicos', function (Blueprint $table) {
            $table->unsignedInteger('duracao_minutos')->nullable()->after('valor');
            $table->decimal('preco_base_operacional', 10, 2)->nullable()->after('duracao_minutos');
            $table->decimal('valor_venda', 10, 2)->nullable()->after('preco_base_operacional');
            $table->boolean('gera_agendamento')->default(false)->after('valor_venda');
        });

        DB::statement('UPDATE servicos SET valor_venda = valor WHERE valor_venda IS NULL');
    }

    public function down(): void
    {
        Schema::table('servicos', function (Blueprint $table) {
            $table->dropColumn(['duracao_minutos', 'preco_base_operacional', 'valor_venda', 'gera_agendamento']);
        });
    }
};
