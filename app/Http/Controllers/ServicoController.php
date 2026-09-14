<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Servico;

class ServicoController extends Controller
{
    private $validacaoPadrao = [
        'servico' => 'required|min:4',
        'valor' => 'required|numeric',
        'categoria' => 'required|integer',
        'duracao_minutos' => 'nullable|integer|min:1',
        'preco_base_operacional' => 'nullable|numeric|min:0',
        'valor_venda' => 'nullable|numeric|min:0',
        'gera_agendamento' => 'sometimes|boolean',
    ];

    public function index()
    {
        $servicos = Servico::all();
        return response()->json($servicos, Response::HTTP_OK);
    }

    public function store(Request $request)
    {   
        $request->validate($this->validacaoPadrao,$this->messages);
        $servico = Servico::create($request->all());
        return response()->json($servico, Response::HTTP_CREATED);
    }

    public function show(string $id)
    {
        $servico = Servico::find($id);

        if (!$servico) {
            return response()->json([
                'message' => 'Serviço não encontrado',
                'errors' => ['id' => 'Serviço com ID:'. $id. ' não existe.']
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($servico, Response::HTTP_OK);
    }

    public function update(Request $request, string $id)
    {
        $servico = Servico::find($id);

        if (!$servico) {
            return response()->json([
                'message' => 'Serviço não encontrado',
                'errors' => ['id' => 'Serviço com ID:'. $id. ' não existe.']
            ], Response::HTTP_NOT_FOUND);
        }

        $request->validate($this->validacaoPadrao,$this->messages);
        $servico->update($request->all());

        return response()->noContent();
    }

    public function destroy(string $id)
    {
        $servico = Servico::find($id);

        if (!$servico) {
            return response()->json([
                'message' => 'Serviço não encontrado',
                'errors' => ['id' => 'Serviço com ID:'. $id. ' não existe.']
            ], Response::HTTP_NOT_FOUND);
        }

        $servico->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
