<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            // Modelo da mensagem de cobrança enviada por WhatsApp. Vazio = usa o texto padrão do app.
            // Variáveis: {nome} {empresa} {valor} {parcelas} {vencimento}
            $table->text('mensagem_cobranca')->nullable()->after('segmento');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('mensagem_cobranca');
        });
    }
};
