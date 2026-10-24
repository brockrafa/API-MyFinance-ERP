<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O custo do produto passa a existir somente nas entradas de estoque
 * (itens_entrada_estoque.custo_unitario / estoque_saldos.custo_medio).
 *
 * Antes de remover produtos.valor, o custo legado é guardado como custo médio de um
 * saldo zerado para os produtos que ainda não têm nenhum saldo, para não perder a referência.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('produtos', 'valor')) {
            return;
        }

        DB::table('empresas')->orderBy('id')->each(function (object $empresa): void {
            $produtos = DB::table('produtos')
                ->where('empresa_id', $empresa->id)
                ->where('valor', '>', 0)
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('estoque_saldos')
                        ->whereColumn('estoque_saldos.produto_id', 'produtos.id');
                })
                ->get(['id', 'valor']);

            if ($produtos->isEmpty()) {
                return;
            }

            $estoqueId = DB::table('estoques')
                ->where('empresa_id', $empresa->id)
                ->orderBy('id')
                ->value('id');

            if (!$estoqueId) {
                $estoqueId = DB::table('estoques')->insertGetId([
                    'empresa_id' => $empresa->id,
                    'nome' => 'Estoque principal',
                    'codigo' => 'PRINCIPAL',
                    'cidade' => null,
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($produtos as $produto) {
                DB::table('estoque_saldos')->insert([
                    'empresa_id' => $empresa->id,
                    'estoque_id' => $estoqueId,
                    'produto_id' => $produto->id,
                    'quantidade' => 0,
                    'custo_medio' => $produto->valor,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('produtos', function (Blueprint $table): void {
            $table->dropColumn('valor');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('produtos', 'valor')) {
            return;
        }

        Schema::table('produtos', function (Blueprint $table): void {
            $table->decimal('valor', 8, 2)->default(0)->after('estoque');
        });

        // Restaura o custo a partir do custo médio atual (maior entre os estoques).
        DB::statement(
            'UPDATE produtos SET valor = COALESCE((SELECT MAX(custo_medio) FROM estoque_saldos WHERE estoque_saldos.produto_id = produtos.id), 0)'
        );
    }
};
