<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\LancamentoFinanceiro;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Gera ~12 meses de recebimentos e despesas COERENTES (status compatível com as datas)
 * para demonstrar o dashboard gerencial.
 *
 * Todos os registros recebem observacao = 'demo-dashboard', então podem ser removidos com:
 *   php artisan db:seed --class=DashboardDemoSeeder   (recria do zero a cada execução)
 * ou apagados com:  LancamentoFinanceiro::where('observacao', 'demo-dashboard')->delete()
 *
 * Usa mt_srand fixo: o resultado é reproduzível.
 */
class DashboardDemoSeeder extends Seeder
{
    private const TAG = 'demo-dashboard';

    public function run(): void
    {
        mt_srand(20261012);

        LancamentoFinanceiro::where('observacao', self::TAG)->delete();

        $hoje = Carbon::today();
        $clientes = Cliente::pluck('id')->all();
        $categorias = Categoria::pluck('id', 'nome');

        if (empty($clientes)) {
            $this->command?->warn('Sem clientes cadastrados: rode o DemoDataSeeder antes.');
            return;
        }

        $catId = fn (string $nome) => $categorias[$nome] ?? $categorias->first();
        $formas = ['pix', 'dinheiro', 'transferencia', 'boleto', 'cartao_debito', 'cartao_credito'];

        // ── Recebimentos: 12 meses para trás + 3 para frente ──
        for ($i = -11; $i <= 3; $i++) {
            $mes = $hoje->copy()->startOfMonth()->addMonths($i);
            $crescimento = 1 + (($i + 11) * 0.025); // negócio crescendo ~2,5% a.m.
            $qtd = mt_rand(9, 14);

            for ($n = 0; $n < $qtd; $n++) {
                $venc = $mes->copy()->day(mt_rand(1, min(28, $mes->daysInMonth)));
                $valor = round(mt_rand(180, 1800) * $crescimento, 2);

                if ($venc->lt($hoje)) {
                    $diasAtras = $venc->diffInDays($hoje);
                    // Recentes têm mais chance de estar em aberto; antigos quase sempre pagos
                    $chanceAberto = $diasAtras <= 20 ? 0.30 : ($diasAtras <= 60 ? 0.14 : 0.06);
                    $aberto = (mt_rand(0, 1000) / 1000) < $chanceAberto;
                } else {
                    $aberto = true;
                }

                $this->criar([
                    'tipo' => 'entrada',
                    'cliente_id' => $clientes[array_rand($clientes)],
                    'descricao' => 'Venda ' . $mes->format('m/Y'),
                    'valor' => $valor,
                    'data_vencimento' => $venc->toDateString(),
                    'status' => $aberto ? ($venc->lt($hoje) ? 'atrasado' : 'pendente') : 'pago',
                    'data_pagamento' => $aberto ? null : $venc->copy()->addDays(mt_rand(-2, 6))->min($hoje)->toDateString(),
                    'valor_pago' => $aberto ? null : $valor,
                    'forma_pagamento' => $aberto ? null : $formas[array_rand($formas)],
                ]);
            }
        }

        // ── Despesas fixas (recorrentes) ──
        $fixas = [
            ['Aluguel do escritório', 'Imobiliária Central', 'Casa', 3200, 5],
            ['Energia elétrica', 'Companhia de Energia', 'Casa', 780, 12],
            ['Internet e telefonia', 'TelNet', 'Tecnologia', 320, 10],
            ['Contabilidade', 'Contabilize', 'Serviços', 650, 15],
            ['Software de gestão', 'CloudSoft', 'Tecnologia', 290, 8],
        ];

        // ── Despesas variáveis ──
        $variaveis = [
            ['Compra de material', 'Distribuidora Alfa', 'Alimentos', 900, 3200],
            ['Marketing e anúncios', 'Ads Digital', 'Moda', 600, 2400],
            ['Manutenção', 'Oficina do Zé', 'Automotivo', 200, 1500],
            ['Fornecedor de mercadorias', 'Atacado Prime', 'Alimentos', 1500, 5200],
            ['Treinamento de equipe', 'Instituto Mais', 'Educação', 300, 1200],
        ];

        for ($i = -11; $i <= 3; $i++) {
            $mes = $hoje->copy()->startOfMonth()->addMonths($i);

            foreach ($fixas as [$desc, $forn, $cat, $base, $dia]) {
                // pequenas variações mês a mês (reajuste/consumo); energia oscila mais
                $oscila = $desc === 'Energia elétrica' ? mt_rand(-20, 35) : mt_rand(-2, 4);
                $valor = round($base * (1 + $oscila / 100) * (1 + max(0, $i + 11) * 0.004), 2);
                $this->saida($desc, $forn, $catId($cat), $valor, $mes->copy()->day(min($dia, $mes->daysInMonth)), $hoje, $formas, true);
            }

            // Variáveis: de 2 a 4 por mês; os últimos 2 meses com um pico para o dashboard ter o que destacar
            $qtd = mt_rand(2, 4);
            for ($n = 0; $n < $qtd; $n++) {
                [$desc, $forn, $cat, $min, $max] = $variaveis[array_rand($variaveis)];
                $valor = round(mt_rand($min, $max) * (($i >= -1 && $i <= 0) ? 1.35 : 1), 2);
                $venc = $mes->copy()->day(mt_rand(1, min(28, $mes->daysInMonth)));
                $this->saida($desc, $forn, $catId($cat), $valor, $venc, $hoje, $formas, false);
            }
        }

        $this->command?->info('Dashboard demo: ' . LancamentoFinanceiro::where('observacao', self::TAG)->count() . ' lançamentos criados.');
    }

    private function saida(string $desc, string $forn, int $categoriaId, float $valor, Carbon $venc, Carbon $hoje, array $formas, bool $fixa): void
    {
        if ($venc->lt($hoje)) {
            $diasAtras = $venc->diffInDays($hoje);
            $aberto = (mt_rand(0, 1000) / 1000) < ($diasAtras <= 15 ? 0.18 : 0.04);
        } else {
            $aberto = true;
        }

        $this->criar([
            'tipo' => 'saida',
            'descricao' => $desc,
            'fornecedor' => $forn,
            'categoria_id' => $categoriaId,
            'valor' => $valor,
            'data_vencimento' => $venc->toDateString(),
            'status' => $aberto ? ($venc->lt($hoje) ? 'atrasado' : 'pendente') : 'pago',
            'data_pagamento' => $aberto ? null : $venc->copy()->subDays(mt_rand(0, 3))->toDateString(),
            'valor_pago' => $aberto ? null : $valor,
            'forma_pagamento' => $aberto ? null : $formas[array_rand($formas)],
            // conta_pagar_id preenchido = veio de despesa recorrente (usado como "despesa fixa" no dashboard)
            'conta_pagar_id' => $fixa ? 1 : null,
        ]);
    }

    private function criar(array $dados): void
    {
        LancamentoFinanceiro::create($dados + [
            'numero_parcela' => 1,
            'total_parcelas' => 1,
            'observacao' => self::TAG,
        ]);
    }
}
