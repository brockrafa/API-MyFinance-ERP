<?php

namespace App\Http\Controllers;

use App\Models\Agenda;
use App\Models\FormaPagamento;
use App\Models\Profissional;
use App\Services\AgendaService;
use App\Services\VendaService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AgendaController extends Controller
{
    private AgendaService $agendaService;
    private VendaService $vendaService;

    public function __construct(AgendaService $agendaService, VendaService $vendaService)
    {
        $this->agendaService = $agendaService;
        $this->vendaService = $vendaService;
    }

    private const MAPA_FORMA_PAGAMENTO = [
        'dinheiro' => 'Dinheiro',
        'pix' => 'PIX',
        'cartao_credito' => 'Cartão de Crédito',
        'cartao_debito' => 'Cartão de Débito',
    ];

    private array $validacaoStore = [
        'cliente_id' => 'required|integer|exists:clientes,id',
        'profissional_id' => 'required_without:usuario_id|integer|exists:profissionais,id',
        'usuario_id' => 'required_without:profissional_id|integer|exists:users,id',
        'data_agendamento' => 'required|date',
        'hora_agendamento' => 'required',
        'duracao_minutos' => 'nullable|integer|min:1',
        'observacao' => 'nullable|string',
        'status' => 'sometimes|string|in:aberto,finalizado',
        'items' => 'required|array|min:1',
        'items.*.id' => 'required|integer',
        'items.*.tipo' => 'required|string|in:produto,servico',
        'items.*.quantidade' => 'required|integer|min:1',
        'items.*.valor' => 'required|numeric|min:0',
    ];

    private array $validacaoUpdate = [
        'cliente_id' => 'sometimes|required|integer|exists:clientes,id',
        'profissional_id' => 'sometimes|required|integer|exists:profissionais,id',
        'usuario_id' => 'sometimes|required|integer|exists:users,id',
        'data_agendamento' => 'sometimes|required|date',
        'hora_agendamento' => 'sometimes|required',
        'duracao_minutos' => 'nullable|integer|min:1',
        'observacao' => 'nullable|string',
        'status' => 'sometimes|string|in:aberto,finalizado',
        'forma_pagamento' => 'nullable|string|in:dinheiro,pix,cartao_credito,cartao_debito',
        'observacao_final' => 'nullable|string',
        'estoque_id' => 'nullable|integer|exists:estoques,id',
        'items' => 'sometimes|array|min:1',
        'items.*.id' => 'required_with:items|integer',
        'items.*.tipo' => 'required_with:items|string|in:produto,servico',
        'items.*.quantidade' => 'required_with:items|integer|min:1',
        'items.*.valor' => 'required_with:items|numeric|min:0',
    ];

    private function montarItens(array $data, int $empresaId): array
    {
        if (empty($data['items'])) {
            return [];
        }

        return array_map(function ($item) {
            return [
                'empresa_id' => null,
                'produto_id' => $item['tipo'] === 'produto' ? $item['id'] : null,
                'servico_id' => $item['tipo'] === 'servico' ? $item['id'] : null,
                'tipo' => $item['tipo'],
                'descricao' => null,
                'quantidade' => $item['quantidade'],
                'valor_unitario' => $item['valor'],
                'valor_total' => $item['valor'] * $item['quantidade'],
            ];
        }, $data['items']);
    }

    private function gerarVendaAoFinalizar(Agenda $agenda, array $data, int $empresaId): void
    {
        $itensAgenda = $agenda->itens()->getResults();

        $temProduto = $itensAgenda->contains('tipo', 'produto');
        $estoqueId = $data['estoque_id'] ?? null;

        if ($temProduto && !$estoqueId) {
            throw ValidationException::withMessages([
                'estoque_id' => 'Este agendamento tem produtos — selecione o estoque para dar baixa antes de finalizar.',
            ]);
        }

        $formaPagamentoId = null;
        $formaPagamentoString = $data['forma_pagamento'] ?? $agenda->forma_pagamento;
        if ($formaPagamentoString) {
            $descricao = self::MAPA_FORMA_PAGAMENTO[$formaPagamentoString] ?? $formaPagamentoString;
            $formaPagamento = FormaPagamento::firstOrCreate(['descricao' => $descricao]);
            $formaPagamentoId = $formaPagamento->id;
        }

        $itensVenda = $itensAgenda->map(function ($item) {
            return [
                'id' => $item->produto_id ?? $item->servico_id,
                'tipo' => $item->tipo,
                'descricao' => $item->descricao,
                'quantidade' => $item->quantidade,
                'valor' => (float) $item->valor_unitario,
            ];
        })->toArray();

        $dadosVenda = [
            'cliente_id' => $agenda->cliente_id,
            'estoque_id' => $temProduto ? $estoqueId : null,
            'forma_pagamento_id' => $formaPagamentoId,
            'data_venda' => $agenda->data_agendamento ?? now()->toDateString(),
            'tipo_venda' => 'avista',
        ];

        $venda = $this->vendaService->criar($dadosVenda, $itensVenda, $empresaId, $agenda->id);

        $agenda->venda_id = $venda->id;
    }

    public function index()
    {
        $agendas = Agenda::with(['cliente', 'usuario', 'profissional.user', 'itens.servico', 'itens.produto'])
            ->orderBy('data_agendamento')
            ->orderBy('hora_agendamento')
            ->get();

        return response()->json($agendas, Response::HTTP_OK);
    }

    private function resolverProfissionalEUsuario(array $data, $empresaId): array
    {
        $profissionalId = $data['profissional_id'] ?? null;
        $usuarioId = $data['usuario_id'] ?? null;

        if ($profissionalId) {
            $profissional = Profissional::find($profissionalId);
            $usuarioId = $profissional?->user_id ?? $usuarioId;
        } elseif ($usuarioId) {
            $profissional = Profissional::where('user_id', $usuarioId)->first();
            $profissionalId = $profissional?->id;
        }

        return [$profissionalId, $usuarioId];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->validacaoStore);
        $empresaId = $request->user()->empresa_id;

        [$profissionalId, $usuarioId] = $this->resolverProfissionalEUsuario($data, $empresaId);

        $itens = $this->montarItens($data, $empresaId);
        $duracaoTotal = $this->agendaService->calcularDuracaoTotal($data['items'] ?? [], $data['duracao_minutos'] ?? null);
        $horaFim = $this->agendaService->calcularHoraFim($data['data_agendamento'], $data['hora_agendamento'], $duracaoTotal);

        $agenda = Agenda::create([
            'cliente_id' => $data['cliente_id'],
            'profissional_id' => $profissionalId,
            'usuario_id' => $usuarioId,
            'data_agendamento' => $data['data_agendamento'],
            'hora_agendamento' => $data['hora_agendamento'],
            'hora_fim' => $horaFim,
            'duracao_minutos' => $duracaoTotal,
            'observacao' => $data['observacao'] ?? null,
            'status' => $data['status'] ?? 'aberto',
            'empresa_id' => $empresaId,
        ]);

        $agenda->itens()->createMany(array_map(function ($item) use ($empresaId) {
            $item['empresa_id'] = $empresaId;
            return $item;
        }, $itens));

        return response()->json($agenda->load(['cliente', 'usuario', 'profissional.user', 'itens.servico', 'itens.produto']), Response::HTTP_CREATED);
    }

    public function show(string $id)
    {
        try {
            $agenda = Agenda::with(['cliente', 'usuario', 'profissional.user', 'itens.servico', 'itens.produto'])->findOrFail($id);
            return response()->json($agenda, Response::HTTP_OK);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => ['id' => 'Agendamento com id:' . $id . ' não existe.'],
            ], Response::HTTP_NOT_FOUND);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $agenda = Agenda::findOrFail($id);
            $data = $request->validate($this->validacaoUpdate);
            $empresaId = $request->user()->empresa_id;

            if (isset($data['profissional_id']) || isset($data['usuario_id'])) {
                [$data['profissional_id'], $data['usuario_id']] = $this->resolverProfissionalEUsuario($data, $empresaId);
            }

            $mudouItens = array_key_exists('items', $data);
            $mudouHorario = isset($data['data_agendamento']) || isset($data['hora_agendamento']) || isset($data['duracao_minutos']) || $mudouItens;
            $vaiFinalizar = ($data['status'] ?? null) === 'finalizado' && $agenda->status !== 'finalizado' && !$agenda->venda_id;

            DB::transaction(function () use ($agenda, $data, $empresaId, $mudouItens, $mudouHorario, $vaiFinalizar) {
                if ($mudouItens) {
                    $itens = $this->montarItens($data, $empresaId);
                    $agenda->itens()->delete();
                    $agenda->itens()->createMany(array_map(function ($item) use ($empresaId) {
                        $item['empresa_id'] = $empresaId;
                        return $item;
                    }, $itens));
                }

                $dataAgendamento = $data['data_agendamento'] ?? $agenda->data_agendamento;
                $horaAgendamento = $data['hora_agendamento'] ?? $agenda->hora_agendamento;

                if ($mudouHorario && $dataAgendamento && $horaAgendamento) {
                    $itemsParaDuracao = $data['items'] ?? $agenda->itens()->getResults()->map(fn ($i) => ['tipo' => $i->tipo, 'id' => $i->servico_id, 'quantidade' => $i->quantidade])->toArray();
                    $duracaoManual = $data['duracao_minutos'] ?? $agenda->duracao_minutos;

                    $duracaoTotal = $this->agendaService->calcularDuracaoTotal($itemsParaDuracao, $duracaoManual);
                    $data['duracao_minutos'] = $duracaoTotal;
                    $data['hora_fim'] = $this->agendaService->calcularHoraFim((string) $dataAgendamento, (string) $horaAgendamento, $duracaoTotal);
                }

                if ($vaiFinalizar) {
                    $this->gerarVendaAoFinalizar($agenda, $data, $empresaId);
                }

                $agenda->update($data);
            });

            return response()->json($agenda->load(['cliente', 'usuario', 'profissional.user', 'itens.servico', 'itens.produto', 'venda']), Response::HTTP_OK);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => ['id' => 'Agendamento com id:' . $id . ' não encontrado.'],
            ], Response::HTTP_NOT_FOUND);
        } catch (ValidationException $e) {
            return response()->json([
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function destroy(string $id)
    {
        Agenda::findOrFail($id)->delete();
        return response()->noContent();
    }
}
