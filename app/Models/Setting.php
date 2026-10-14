<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One business setting, saved as JSON under a key. Read it with Setting::read('tax'); it is null until someone saves it. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @return array<string, mixed>|null */
    public static function read(string $key): ?array
    {
        return static::query()->whereKey($key)->first()?->value;
    }

    /** @param  array<string, mixed>  $value */
    public static function write(string $key, array $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
