<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agendas', function (Blueprint $table) {
            $table->string('hora_fim')->nullable()->after('hora_agendamento');
            $table->unsignedInteger('duracao_minutos')->nullable()->after('hora_fim');
        });
    }

    public function down(): void
    {
        Schema::table('agendas', function (Blueprint $table) {
            $table->dropColumn(['hora_fim', 'duracao_minutos']);
        });
    }
};
