<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single phone number forbidden from placing new orders. Sibling
 * of BlockedIp — IP blocking is bypassed by VPN/proxy, phone
 * numbers tend to stick. OrderController::store() checks both
 * lists in parallel before doing any other work.
 */
#[Fillable(['phone_number', 'reason', 'block_group_id', 'blocked_by_admin_id'])]
class BlockedPhone extends Model
{
    protected $table = 'blocked_phones';

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'blocked_by_admin_id');
    }
}
