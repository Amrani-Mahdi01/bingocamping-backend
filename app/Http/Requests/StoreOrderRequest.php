<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public order creation. The endpoint is unauthenticated (guest checkout)
 * but rate-limited at the route level.
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer' => ['required', 'array'],
            'customer.firstName' => ['required', 'string', 'max:80'],
            'customer.lastName' => ['required', 'string', 'max:80'],
            'customer.phone' => [
                'required',
                'string',
                'regex:/^0[567]\d{8}$/',
            ],
            'customer.email' => ['nullable', 'email', 'max:120'],

            'shipping' => ['required', 'array'],
            'shipping.wilayaId' => ['required', 'string', 'size:2', 'exists:wilayas,id'],
            'shipping.commune' => ['required', 'string', 'max:120'],
            'shipping.address' => ['nullable', 'string', 'max:255'],
            'shipping.notes' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.productId' => ['required', 'integer', 'exists:products,id'],
            'lines.*.variant' => ['nullable', 'string', 'max:160'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer.firstName.required' => 'Le prénom est requis.',
            'customer.lastName.required' => 'Le nom est requis.',
            'customer.phone.required' => 'Le téléphone est requis.',
            'customer.phone.regex' =>
                "Format attendu : 10 chiffres commençant par 05, 06 ou 07.",
            'shipping.wilayaId.required' => "Choisissez votre wilaya.",
            'shipping.commune.required' => "La commune est requise.",
            'lines.required' => "Au moins un article requis.",
        ];
    }
}
