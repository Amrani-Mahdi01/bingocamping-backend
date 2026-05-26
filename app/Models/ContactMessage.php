<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One submission from the storefront /contact form. Public POST
 * route is reCAPTCHA-gated; admin reads/clears these via
 * /admin/customers/messages.
 */
#[Fillable([
    'name',
    'email',
    'subject',
    'message',
    'ip',
    'is_read',
    'read_at',
])]
class ContactMessage extends Model
{
    protected $table = 'contact_messages';

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }
}
