<?php

namespace App\Http\Controllers;

use App\Models\Agenda;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AgendaController extends Controller
{
    private array $validacaoStore = [
        'cliente_id' => 'required|integer|exists:clientes,id',
        'usuario_id' => 'required|integer|exists:users,id',
        'data_agendamento' => 'required|date',
        'hora_agendamento' => 'required',
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
        'usuario_id' => 'sometimes|required|integer|exists:users,id',
        'data_agendamento' => 'sometimes|required|date',
        'hora_agendamento' => 'sometimes|required',
        'observacao' => 'nullable|string',
        'status' => 'sometimes|string|in:aberto,finalizado',
        'items' => 'sometimes|array|min:1',
        'items.*.id' => 'required_with:items|integer',
        'items.*.tipo' => 'required_with:items|string|in:produto,servico',
        'items.*.quantidade' => 'required_with:items|integer|min:1',
        'items.*.valor' => 'required_with:items|numeric|min:0',
    ];

    public function index()
    {
        $agendas = Agenda::with(['cliente', 'usuario', 'itens.servico', 'itens.produto'])
            ->orderBy('data_agendamento')
            ->orderBy('hora_agendamento')
            ->get();

        return response()->json($agendas, Response::HTTP_OK);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->validacaoStore);

        $agenda = Agenda::create([
            'cliente_id' => $data['cliente_id'],
            'usuario_id' => $data['usuario_id'],
            'data_agendamento' => $data['data_agendamento'],
            'hora_agendamento' => $data['hora_agendamento'],
            'observacao' => $data['observacao'] ?? null,
            'status' => $data['status'] ?? 'aberto',
            'empresa_id' => $request->user()->empresa_id,
        ]);

        $agenda->itens()->createMany(array_map(function ($item) use ($request) {
            return [
                'empresa_id' => $request->user()->empresa_id,
                'produto_id' => $item['tipo'] === 'produto' ? $item['id'] : null,
                'servico_id' => $item['tipo'] === 'servico' ? $item['id'] : null,
                'tipo' => $item['tipo'],
                'quantidade' => $item['quantidade'],
                'valor_unitario' => $item['valor'],
            ];
        }, $data['items']));

        return response()->json($agenda->load(['cliente', 'usuario', 'itens.servico', 'itens.produto']), Response::HTTP_CREATED);
    }

    public function show(string $id)
    {
        try {
            $agenda = Agenda::with(['cliente', 'usuario', 'itens.servico', 'itens.produto'])->findOrFail($id);
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

            $agenda->update($data);

            if (array_key_exists('items', $data)) {
                $agenda->itens()->delete();
                $agenda->itens()->createMany(array_map(function ($item) use ($request) {
                    return [
                        'empresa_id' => $request->user()->empresa_id,
                        'produto_id' => $item['tipo'] === 'produto' ? $item['id'] : null,
                        'servico_id' => $item['tipo'] === 'servico' ? $item['id'] : null,
                        'tipo' => $item['tipo'],
                        'quantidade' => $item['quantidade'],
                        'valor_unitario' => $item['valor'],
                    ];
                }, $data['items']));
            }

            return response()->json($agenda->load(['cliente', 'usuario', 'itens.servico', 'itens.produto']), Response::HTTP_OK);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => ['id' => 'Agendamento com id:' . $id . ' não encontrado.'],
            ], Response::HTTP_NOT_FOUND);
        }
    }

    public function destroy(string $id)
    {
        Agenda::findOrFail($id)->delete();
        return response()->noContent();
    }
}
