<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'order_number',
    'customer_id',
    'customer_first_name',
    'customer_last_name',
    'customer_phone',
    'customer_email',
    'customer_ip',
    'wilaya_id',
    'wilaya_name',
    'commune',
    'address',
    'delivery_type',
    'notes',
    'subtotal',
    'shipping_fee',
    'total',
    'status',
    'archived_at',
    'payment_method',
    'payment_status',
    'tracking_number',
    'cancellation_reason',
    // ZR Express delivery integration
    'zr_parcel_id',
    'zr_state',
    'zr_synced_at',
    'zr_last_error',
])]
class Order extends Model
{
    /** Canonical status values. Anything else is rejected at the boundary. */
    public const STATUSES = [
        'pending',
        'confirmed',
        'preparing',
        'shipped',
        'delivered',
        'cancelled',
        'returned',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'shipping_fee' => 'integer',
            'total' => 'integer',
            'zr_synced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusEntry::class)->orderBy('at');
    }

    public function callAttempts(): HasMany
    {
        return $this->hasMany(OrderCallAttempt::class)->orderBy('at');
    }

    /**
     * Archive (don't delete) orders left untouched for {$months} months —
     * abandoned/stalled ones whose status never moved. `updated_at` bumps on
     * any status change or ZR sync, so only genuinely inactive orders match.
     * Sets archived_at so they drop out of the active admin list but stay in
     * the DB (and in the statistics) and can be restored. Already-archived rows
     * are skipped. Returns the number archived.
     */
    public static function archiveStale(int $months = 3): int
    {
        return static::query()
            ->whereNull('archived_at')
            ->where('updated_at', '<', now()->subMonths($months))
            ->update(['archived_at' => now()]);
    }
}
