<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('brand')?->id;
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'alpha_dash',
                'max:120',
                'unique:brands,slug,'.($id ?? 'NULL').',id',
            ],
            'country' => ['nullable', 'string', 'size:2'],
            'logo' => ['nullable', 'string', 'max:1000'],
            'descriptionFr' => ['nullable', 'string', 'max:2000'],
            'descriptionAr' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la marque est requis.',
            'slug.unique' => 'Ce slug est déjà utilisé.',
            'country.size' => 'Le code pays doit faire 2 caractères (ex : FR, DZ, US).',
        ];
    }

    public function toModel(): array
    {
        $v = $this->validated();
        $nullable = fn ($x) => is_string($x) && trim($x) === '' ? null : $x;

        $slug = $nullable($v['slug'] ?? null) ?? Str::slug($v['name']);

        return [
            'name' => $v['name'],
            'slug' => $slug,
            'country' => $nullable($v['country'] ?? null),
            'logo' => $nullable($v['logo'] ?? null),
            'description_fr' => $nullable($v['descriptionFr'] ?? null),
            'description_ar' => $nullable($v['descriptionAr'] ?? null),
            'is_active' => array_key_exists('isActive', $v) ? (bool) $v['isActive'] : true,
        ];
    }
}
