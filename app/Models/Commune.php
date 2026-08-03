<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'wilaya_id',
    'code',
    'name_fr',
    'name_ar',
    'zr_district_id',
    'has_stop_desk',
])]
class Commune extends Model
{
    protected $casts = [
        'has_stop_desk' => 'boolean',
    ];

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }
}
