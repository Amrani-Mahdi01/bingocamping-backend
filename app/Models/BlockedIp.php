<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single IP address that is forbidden from placing new orders.
 * Checked at the top of OrderController::store() before any other
 * validation so we never even hit Google reCAPTCHA for a blocked IP.
 */
#[Fillable(['ip_address', 'reason', 'block_group_id', 'blocked_by_admin_id'])]
class BlockedIp extends Model
{
    protected $table = 'blocked_ips';

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'blocked_by_admin_id');
    }
}
