<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMultiItemEntradaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'estoque_id' => ['required', 'integer', 'exists:estoques,id'],
            'tipo' => ['required', 'string', 'in:compra,ajuste'],
            'data_entrada' => ['nullable', 'date'],
            'frete_total' => ['nullable', 'numeric', 'min:0'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.produto_id' => ['required', 'integer', 'exists:produtos,id', 'distinct'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
            'itens.*.custo_unitario' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'estoque_id.required' => 'Estoque é obrigatório',
            'estoque_id.exists' => 'Estoque inválido',
            'tipo.required' => 'Tipo de entrada é obrigatório',
            'itens.required' => 'Adicione pelo menos um item',
            'itens.*.produto_id.required' => 'Selecione um produto para cada item',
            'itens.*.produto_id.distinct' => 'Produtos duplicados não são permitidos',
            'itens.*.quantidade.required' => 'Quantidade é obrigatória',
            'itens.*.quantidade.min' => 'Quantidade deve ser maior que zero',
            'itens.*.custo_unitario.required' => 'Custo unitário é obrigatório',
            'itens.*.custo_unitario.min' => 'Custo unitário deve ser maior que zero',
        ];
    }
}
