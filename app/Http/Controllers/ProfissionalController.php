<?php

namespace App\Http\Controllers;

use App\Models\Profissional;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProfissionalController extends Controller
{
    private array $validacaoPadrao = [
        'nome' => 'required|string|min:2',
        'cargo' => 'nullable|string',
        'user_id' => 'nullable|integer|exists:users,id',
        'ativo' => 'sometimes|boolean',
    ];

    public function index()
    {
        return response()->json(Profissional::with('user')->orderBy('nome')->get(), Response::HTTP_OK);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->validacaoPadrao);
        $profissional = Profissional::create($data);
        return response()->json($profissional->load('user'), Response::HTTP_CREATED);
    }

    public function show(string $id)
    {
        try {
            $profissional = Profissional::with('user')->findOrFail($id);
            return response()->json($profissional, Response::HTTP_OK);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => ['id' => 'Profissional com id:' . $id . ' não existe.'],
            ], Response::HTTP_NOT_FOUND);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $profissional = Profissional::findOrFail($id);
            $data = $request->validate($this->validacaoPadrao);
            $profissional->update($data);
            return response()->json($profissional->load('user'), Response::HTTP_OK);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => ['id' => 'Profissional com id:' . $id . ' não encontrado.'],
            ], Response::HTTP_NOT_FOUND);
        }
    }

    public function destroy(string $id)
    {
        Profissional::findOrFail($id)->delete();
        return response()->noContent();
    }
}
