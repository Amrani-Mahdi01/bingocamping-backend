<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ZR Express pickup point (stop desk), mirrored from ZR's POST /hubs/search
 * during "sync territories". `id` is ZR's own hub UUID (used as the parcel's
 * hubId), so it's a non-incrementing string primary key.
 */
#[Fillable([
    'id',
    'wilaya_id',
    'name',
    'commune_name',
    'address',
    'district_territory_id',
])]
class ZrHub extends Model
{
    protected $table = 'zr_hubs';

    public $incrementing = false;

    protected $keyType = 'string';

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }
}
