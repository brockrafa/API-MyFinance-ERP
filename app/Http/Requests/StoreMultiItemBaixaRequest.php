<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMultiItemBaixaRequest extends FormRequest
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
            'observacao' => ['nullable', 'string', 'max:1000'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.produto_id' => ['required', 'integer', 'exists:produtos,id', 'distinct'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
            'itens.*.motivo' => ['required', 'string', 'max:500'],
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
            'itens.required' => 'Adicione pelo menos um item',
            'itens.*.produto_id.required' => 'Selecione um produto para cada item',
            'itens.*.produto_id.distinct' => 'Produtos duplicados não são permitidos',
            'itens.*.quantidade.required' => 'Quantidade é obrigatória',
            'itens.*.quantidade.min' => 'Quantidade deve ser maior que zero',
            'itens.*.motivo.required' => 'Motivo é obrigatório',
        ];
    }
}
