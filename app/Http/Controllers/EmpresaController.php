<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\Request;

class EmpresaController extends Controller
{
    public function show(Request $request)
    {
        $empresa = Empresa::findOrFail($request->user()->empresa_id);

        return response()->json($empresa);
    }

    public function update(Request $request)
    {
        $empresa = Empresa::findOrFail($request->user()->empresa_id);

        $validado = $request->validate([
            'nome' => 'required|string|max:255',
            'cnpj' => 'required|string|unique:empresas,cnpj,' . $empresa->id,
            'contrato_ref' => 'nullable|string|max:255',
            'segmento' => 'sometimes|string|in:salao,barbearia,transporte,generico',
        ]);

        $empresa->update($validado);

        return response()->json($empresa);
    }
}
