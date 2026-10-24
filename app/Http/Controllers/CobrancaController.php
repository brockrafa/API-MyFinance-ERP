<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\LancamentoFinanceiro;
use App\Models\Venda;
use App\Services\CobrancaService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CobrancaController extends Controller
{
    public function __construct(private CobrancaService $cobranca)
    {
    }

    /** Ranking de quem deve: um registro por cliente, do maior saldo em aberto para o menor. */
    public function devedores()
    {
        $devedores = $this->cobranca->devedores();

        return response()->json([
            'resumo' => [
                'devedores' => count($devedores),
                'total_em_aberto' => round(array_sum(array_column($devedores, 'total_em_aberto')), 2),
                'total_vencido' => round(array_sum(array_column($devedores, 'total_vencido')), 2),
            ],
            'devedores' => $devedores,
        ], Response::HTTP_OK);
    }

    public function extrato(string $id)
    {
        $cliente = Cliente::find($id);

        if (!$cliente) {
            return response()->json(['errors' => "Cliente com id {$id} não existe."], Response::HTTP_NOT_FOUND);
        }

        return response()->json($this->cobranca->extrato($cliente), Response::HTTP_OK);
    }

    public function reciboVenda(Request $request, string $id)
    {
        $venda = Venda::find($id);

        if (!$venda) {
            return response()->json(['errors' => "Venda com id {$id} não existe."], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'empresa' => $this->dadosEmpresa($request),
            'recibo' => $this->cobranca->reciboVenda($venda),
        ], Response::HTTP_OK);
    }

    public function reciboPagamento(Request $request, string $id)
    {
        $lancamento = LancamentoFinanceiro::where('tipo', 'entrada')->find($id);

        if (!$lancamento) {
            return response()->json(['errors' => "Lançamento com id {$id} não existe."], Response::HTTP_NOT_FOUND);
        }

        if ($lancamento->status !== 'pago') {
            return response()->json(['errors' => 'Esta parcela ainda não foi paga, então não há recibo.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'empresa' => $this->dadosEmpresa($request),
            'recibo' => $this->cobranca->reciboPagamento($lancamento),
        ], Response::HTTP_OK);
    }

    private function dadosEmpresa(Request $request): array
    {
        return Empresa::findOrFail($request->user()->empresa_id)->only(['nome', 'cnpj']);
    }
}
