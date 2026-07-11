<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'value', 'type', 'description'
    ];

    protected $casts = [
        'value' => 'string',
    ];

    /**
     * Get value as boolean
     */
    public function getBooleanValueAttribute(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get value as array (for JSON type)
     */
    public function getArrayValueAttribute(): array
    {
        return json_decode($this->value, true) ?? [];
    }

    /**
     * Static helper to get setting value
     */
    public static function getValue(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * Static helper to get boolean setting
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $setting = static::where('key', $key)->first();
        return $setting ? filter_var($setting->value, FILTER_VALIDATE_BOOLEAN) : $default;
    }

    /**
     * Static helper to set setting value
     */
    public static function setValue(string $key, $value, string $type = 'string'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            [
                'value' => is_bool($value) ? ($value ? 'true' : 'false') : $value,
                'type' => $type
            ]
        );
    }
}