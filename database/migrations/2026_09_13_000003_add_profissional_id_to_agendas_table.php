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
        if ($this->foreignKeyExists('agendas', 'agendas_usuario_id_foreign')) {
            Schema::table('agendas', function (Blueprint $table) {
                $table->dropForeign(['usuario_id']);
            });
        }

        DB::table('agendas')->whereNull('usuario_id')->delete();

        DB::statement('ALTER TABLE agendas MODIFY usuario_id BIGINT UNSIGNED NOT NULL');

        if (!$this->foreignKeyExists('agendas', 'agendas_usuario_id_foreign')) {
            Schema::table('agendas', function (Blueprint $table) {
                $table->foreign('usuario_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('agendas', 'profissional_id')) {
            if ($this->foreignKeyExists('agendas', 'agendas_profissional_id_foreign')) {
                Schema::table('agendas', function (Blueprint $table) {
                    $table->dropForeign(['profissional_id']);
                });
            }

            Schema::table('agendas', function (Blueprint $table) {
                $table->dropColumn('profissional_id');
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $result = DB::selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraintName]
        );

        return $result->total > 0;
    }
};
