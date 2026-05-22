<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('category')?->id;
        return [
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'alpha_dash',
                'unique:categories,slug,'.($id ?? 'NULL').',id',
            ],
            'nameFr' => ['required', 'string', 'max:120'],
            'nameAr' => ['required', 'string', 'max:120'],
            'descriptionFr' => ['nullable', 'string', 'max:2000'],
            'descriptionAr' => ['nullable', 'string', 'max:2000'],
            // null = top-level
            'parentId' => ['nullable', 'integer', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:64'],
            'image' => ['nullable', 'string', 'max:1000'],
            'displayOrder' => ['nullable', 'integer', 'min:0'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nameFr.required' => 'Le nom français est requis.',
            'nameAr.required' => 'Le nom arabe est requis.',
            'slug.unique' => 'Ce slug est déjà utilisé.',
            'parentId.exists' => 'Catégorie parente introuvable.',
        ];
    }

    public function toModel(): array
    {
        $v = $this->validated();
        $nullable = fn ($x) => is_string($x) && trim($x) === '' ? null : $x;

        // Auto-slug from FR name if none provided.
        $slug = $nullable($v['slug'] ?? null);
        if (! $slug) {
            $slug = Str::slug($v['nameFr']);
        }

        return [
            'slug' => $slug,
            'name_fr' => $v['nameFr'],
            'name_ar' => $v['nameAr'],
            'description_fr' => $nullable($v['descriptionFr'] ?? null),
            'description_ar' => $nullable($v['descriptionAr'] ?? null),
            'parent_id' => $v['parentId'] ?? null,
            'icon' => $nullable($v['icon'] ?? null) ?? 'Folder',
            // Only kept when parent_id is null (sub-categories never carry images).
            'image' => $v['parentId'] ?? null
                ? null
                : $nullable($v['image'] ?? null),
            'display_order' => $v['displayOrder'] ?? 0,
            'is_active' => array_key_exists('isActive', $v) ? (bool) $v['isActive'] : true,
        ];
    }
}
