<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Os canais deixam de ter "casa": o padrão passa a ser `venda_direta` (sem desconto de canal)
 * e `outro` vira `ecommerce` (e-commerce / outro marketplace).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vendas')->where('canal', 'casa')->update(['canal' => 'venda_direta']);
        DB::table('vendas')->where('canal', 'outro')->update(['canal' => 'ecommerce']);

        DB::statement("ALTER TABLE vendas ALTER COLUMN canal SET DEFAULT 'venda_direta'");
    }

    public function down(): void
    {
        DB::table('vendas')->where('canal', 'venda_direta')->update(['canal' => 'casa']);
        DB::table('vendas')->where('canal', 'ecommerce')->update(['canal' => 'outro']);

        DB::statement("ALTER TABLE vendas ALTER COLUMN canal SET DEFAULT 'casa'");
    }
};
