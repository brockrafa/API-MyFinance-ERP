<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentos_estoque', function (Blueprint $table) {
            $table->timestamp('estornado_em')->nullable()->after('observacao');
            $table->foreignId('estornado_por_id')->nullable()->after('estornado_em')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentos_estoque', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estornado_por_id');
            $table->dropColumn('estornado_em');
        });
    }
};
