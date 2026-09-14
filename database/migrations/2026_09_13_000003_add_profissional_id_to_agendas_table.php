<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agendas', function (Blueprint $table) {
            $table->foreignId('profissional_id')->nullable()->after('usuario_id')
                ->constrained('profissionais')->nullOnDelete();
        });

        Schema::table('agendas', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
        });

        DB::statement('ALTER TABLE agendas MODIFY usuario_id BIGINT UNSIGNED NULL');

        Schema::table('agendas', function (Blueprint $table) {
            $table->foreign('usuario_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agendas', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
        });

        DB::statement('ALTER TABLE agendas MODIFY usuario_id BIGINT UNSIGNED NOT NULL');

        Schema::table('agendas', function (Blueprint $table) {
            $table->foreign('usuario_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('agendas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profissional_id');
        });
    }
};
