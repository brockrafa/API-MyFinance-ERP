<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE produtos CHANGE COLUMN categoria categoria_id BIGINT UNSIGNED NULL');

        // Zera referências para categorias que não existem mais, evitando falha ao criar a FK.
        DB::statement('
            UPDATE produtos
            LEFT JOIN categorias ON categorias.id = produtos.categoria_id
            SET produtos.categoria_id = NULL
            WHERE categorias.id IS NULL
        ');

        Schema::table('produtos', function (Blueprint $table) {
            $table->foreign('categoria_id')->references('id')->on('categorias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropForeign(['categoria_id']);
        });

        DB::statement('ALTER TABLE produtos CHANGE COLUMN categoria_id categoria INT(11) NOT NULL DEFAULT 0');
    }
};
