<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id',
    'code',
    'name_fr',
    'name_ar',
    'region',
    'shipping_price',
    'delivery_days',
])]
class Wilaya extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'shipping_price' => 'integer',
            'delivery_days' => 'integer',
        ];
    }

    public function communes(): HasMany
    {
        return $this->hasMany(Commune::class);
    }
}
