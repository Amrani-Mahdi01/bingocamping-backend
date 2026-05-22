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
])]
class Commune extends Model
{
    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }
}
