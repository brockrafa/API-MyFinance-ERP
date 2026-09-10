<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMultiItemTransferenciaRequest extends FormRequest
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
            'estoque_origem_id' => ['required', 'integer', 'exists:estoques,id'],
            'estoque_destino_id' => ['required', 'integer', 'exists:estoques,id', 'different:estoque_origem_id'],
            'data_transferencia' => ['nullable', 'date'],
            'frete_total' => ['nullable', 'numeric', 'min:0'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.produto_id' => ['required', 'integer', 'exists:produtos,id', 'distinct'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'estoque_origem_id.required' => 'Estoque de origem é obrigatório',
            'estoque_origem_id.exists' => 'Estoque de origem inválido',
            'estoque_destino_id.required' => 'Estoque de destino é obrigatório',
            'estoque_destino_id.exists' => 'Estoque de destino inválido',
            'estoque_destino_id.different' => 'Estoque de destino deve ser diferente da origem',
            'itens.required' => 'Adicione pelo menos um item',
            'itens.*.produto_id.required' => 'Selecione um produto para cada item',
            'itens.*.produto_id.distinct' => 'Produtos duplicados não são permitidos',
            'itens.*.quantidade.required' => 'Quantidade é obrigatória',
            'itens.*.quantidade.min' => 'Quantidade deve ser maior que zero',
        ];
    }
}
