<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['key', 'value', 'is_public'])]
class Setting extends Model
{
    protected $table = 'site_settings';

    /** Dotted-string keys, not auto-incrementing. */
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /** Fetch a single setting value with an optional default. */
    public static function get(string $key, ?string $default = null): ?string
    {
        return self::query()->where('key', $key)->value('value') ?? $default;
    }

    /** Set (or create) a setting. */
    public static function set(string $key, ?string $value, bool $isPublic = true): self
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'is_public' => $isPublic],
        );
    }
}
