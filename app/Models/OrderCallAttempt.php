<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['order_id', 'outcome', 'by', 'note', 'at'])]
class OrderCallAttempt extends Model
{
    protected $table = 'order_call_attempts';

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
