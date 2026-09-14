<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_vendas', function (Blueprint $table) {
            $table->string('descricao')->nullable()->after('quantidade');
        });
    }

    public function down(): void
    {
        Schema::table('item_vendas', function (Blueprint $table) {
            $table->dropColumn('descricao');
        });
    }
};
