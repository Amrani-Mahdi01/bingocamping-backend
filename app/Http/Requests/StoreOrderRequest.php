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
            // Required for home delivery; for stop-desk the commune is derived
            // from the chosen desk instead (see stopDeskId below).
            'shipping.commune' => ['required_unless:shipping.deliveryType,stopdesk', 'nullable', 'string', 'max:120'],
            'shipping.address' => ['nullable', 'string', 'max:255'],
            // 'home' (à domicile) or 'stopdesk' (retrait en agence). Optional
            // for backward compatibility — absent/invalid defaults to 'home'.
            'shipping.deliveryType' => ['nullable', 'in:home,stopdesk'],
            // The exact ZR pickup point chosen — required for stop-desk orders.
            'shipping.stopDeskId' => ['nullable', 'string', 'exists:zr_hubs,id', 'required_if:shipping.deliveryType,stopdesk'],
            'shipping.notes' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1', 'max:50'],
            // Identify products by slug (what the storefront cart speaks).
            // OrderController resolves slug → product row inside the
            // transaction so a deleted/disabled product fails the order.
            'lines.*.productSlug' => ['required', 'string', 'exists:products,slug'],
            'lines.*.variant' => ['nullable', 'string', 'max:160'],
            // Exact purchasable variant (color/size) chosen, so the right
            // variant's stock is decremented on confirm. Validated against the
            // resolved product inside OrderController (a variant of another
            // product is ignored there).
            'lines.*.variantId' => ['nullable', 'integer', 'exists:product_variants,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],

            // Google reCAPTCHA v2 response token. Required so the
            // controller can pass it to Google's siteverify endpoint.
            // Only enforced when RECAPTCHA_SECRET_KEY is configured in
            // .env — see OrderController::verifyRecaptcha.
            'recaptchaToken' => ['nullable', 'string', 'max:4096'],
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
            'shipping.commune.required_unless' => "La commune est requise.",
            'shipping.stopDeskId.required_if' => "Choisissez un point de retrait (stop desk).",
            'shipping.stopDeskId.exists' => "Ce point de retrait n'est plus disponible.",
            'lines.required' => "Au moins un article requis.",
            'lines.*.productSlug.exists' => "Un des produits n'est plus disponible.",
        ];
    }
}
