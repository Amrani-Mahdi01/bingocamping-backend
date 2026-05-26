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
    'notes',
    'subtotal',
    'shipping_fee',
    'total',
    'status',
    'payment_method',
    'payment_status',
    'tracking_number',
    'cancellation_reason',
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
}
