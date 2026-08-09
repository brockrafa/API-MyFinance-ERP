<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agendas', function (Blueprint $table) {
            if (!Schema::hasColumn('agendas', 'status')) {
                $table->string('status')->default('aberto')->after('observacao');
            }
        });

        Schema::create('agenda_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agenda_id')->constrained('agendas')->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('produto_id')->nullable()->constrained('produtos')->nullOnDelete();
            $table->foreignId('servico_id')->nullable()->constrained('servicos')->nullOnDelete();
            $table->string('tipo');
            $table->integer('quantidade')->default(1);
            $table->decimal('valor_unitario', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_itens');

        Schema::table('agendas', function (Blueprint $table) {
            if (Schema::hasColumn('agendas', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
