<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('empresas')->orderBy('id')->each(function (object $empresa): void {
            $estoque = DB::table('estoques')
                ->where('empresa_id', $empresa->id)
                ->where('codigo', 'PRINCIPAL')
                ->first();

            if (!$estoque) {
                $estoqueId = DB::table('estoques')->insertGetId([
                    'empresa_id' => $empresa->id,
                    'nome' => 'Estoque principal',
                    'codigo' => 'PRINCIPAL',
                    'cidade' => null,
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $estoqueId = $estoque->id;
            }

            DB::table('produtos')
                ->where('empresa_id', $empresa->id)
                ->where('estoque', '>', 0)
                ->orderBy('id')
                ->each(function (object $produto) use ($empresa, $estoqueId): void {
                    $quantidade = (int) $produto->estoque;
                    $custo = (float) $produto->valor;

                    DB::table('estoque_saldos')->updateOrInsert(
                        ['estoque_id' => $estoqueId, 'produto_id' => $produto->id],
                        [
                            'empresa_id' => $empresa->id,
                            'quantidade' => $quantidade,
                            'custo_medio' => $custo,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    $movimentoExiste = DB::table('movimentos_estoque')
                        ->where('origem_tipo', 'migracao_estoque_legado')
                        ->where('estoque_id', $estoqueId)
                        ->where('produto_id', $produto->id)
                        ->exists();

                    if (!$movimentoExiste) {
                        DB::table('movimentos_estoque')->insert([
                            'empresa_id' => $empresa->id,
                            'estoque_id' => $estoqueId,
                            'produto_id' => $produto->id,
                            'tipo' => 'saldo_inicial',
                            'quantidade' => $quantidade,
                            'custo_unitario' => $custo,
                            'custo_total' => $quantidade * $custo,
                            'origem_tipo' => 'migracao_estoque_legado',
                            'origem_id' => null,
                            'usuario_id' => null,
                            'observacao' => 'Saldo migrado de produtos.estoque',
                            'movimentado_em' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        });
    }

    public function down(): void
    {
        $estoques = DB::table('estoques')->where('codigo', 'PRINCIPAL')->get();

        foreach ($estoques as $estoque) {
            $possuiEntradas = DB::table('entradas_estoque')
                ->where('estoque_id', $estoque->id)
                ->exists();
            $possuiTransferencias = DB::table('transferencias_estoque')
                ->where(function ($query) use ($estoque): void {
                    $query->where('estoque_origem_id', $estoque->id)
                        ->orWhere('estoque_destino_id', $estoque->id);
                })
                ->exists();

            // Never remove an operational stock during rollback.
            if ($possuiEntradas || $possuiTransferencias) {
                continue;
            }

            $movimentos = DB::table('movimentos_estoque')
                ->where('estoque_id', $estoque->id)
                ->where('origem_tipo', 'migracao_estoque_legado')
                ->get(['produto_id']);

            DB::table('movimentos_estoque')
                ->where('estoque_id', $estoque->id)
                ->where('origem_tipo', 'migracao_estoque_legado')
                ->delete();

            foreach ($movimentos as $movimento) {
                DB::table('estoque_saldos')
                    ->where('estoque_id', $estoque->id)
                    ->where('produto_id', $movimento->produto_id)
                    ->delete();
            }

            DB::table('estoques')->where('id', $estoque->id)->delete();
        }
    }
};