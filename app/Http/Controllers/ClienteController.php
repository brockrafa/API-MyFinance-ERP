<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListaPaginavel;
use Illuminate\Http\Request;
use App\Models\Cliente;
use Illuminate\Http\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ClienteController extends Controller
{
    use ListaPaginavel;

    private array $validacaoPadrao = [
        'nome' => 'required|string|min:4'
    ];

    public function index(Request $request)
    {
        $consulta = $this->aplicarBusca(
            Cliente::query()->orderBy('id'),
            $request,
            ['nome', 'email', 'telefone', 'cidade', 'bairro']
        );

        return $this->listar($request, $consulta);
    }

    public function store(Request $request)
    {   
        $request->validate($this->validacaoPadrao,$this->messages);
        $cliente = new Cliente();
        $cliente->fill($request->all());
        $cliente->telefone = str_replace(['(',')','-',' '],'',$request->telefone);
        $cliente->save();
        return response()->json($cliente, Response::HTTP_CREATED);
    }

    public function show(string $id)
    {
        try{
            $cliente = Cliente::findOrFail($id);
            return response()->json($cliente, Response::HTTP_OK);
        }catch(ModelNotFoundException $e){
            return response()->json([
                'errors' =>  'Cliente com id:'. $id. ' não existe.'], Response::HTTP_NOT_FOUND
            ); 
        }
    }

    public function update(Request $request, string $id)
    {
        try{
            $cliente = Cliente::findOrFail($id);
            $request->validate($this->validacaoPadrao,$this->messages);
            $cliente->fill($request->all());
            $cliente->telefone = str_replace(['(',')','-',' '],'',$request->telefone);
            $cliente->save();
            return response()->noContent();
        }catch(ModelNotFoundException $e){
            return response()->json([
                'errors' => ['id' => 'Cliente com id:'. $id. ' não encontrado.']], Response::HTTP_NOT_FOUND
            ); 
        }
    }

    public function destroy(string $id)
    {
        Cliente::findOrFail($id)->delete();
        return response()->noContent();
    }
}
