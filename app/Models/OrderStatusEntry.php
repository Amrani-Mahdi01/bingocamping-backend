<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['order_id', 'status', 'by', 'note', 'at'])]
class OrderStatusEntry extends Model
{
    protected $table = 'order_status_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
