<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'description',
    ];

    /**
     * Get a setting value by key, optionally decrypting it.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        if (!$setting || $setting->value === null) {
            return $default;
        }

        // Try to decrypt, fall back to raw value
        try {
            return Crypt::decryptString($setting->value);
        } catch (\Exception $e) {
            return $setting->value;
        }
    }

    /**
     * Set a setting value by key, encrypting sensitive values.
     */
    public static function setValue(string $key, mixed $value, string $group = 'general', ?string $description = null): void
    {
        $encryptedGroups = ['jules', 'github', 'gemini'];
        $data = [
            'value' => in_array($group, $encryptedGroups) ? Crypt::encryptString((string) $value) : $value,
            'group' => $group,
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }

        static::updateOrCreate(
            ['key' => $key],
            $data,
        );
    }

    /**
     * Get all settings for a group.
     */
    public static function getGroup(string $group): array
    {
        return static::where('group', $group)
            ->pluck('value', 'key')
            ->map(function ($value) {
                try {
                    return Crypt::decryptString($value);
                } catch (\Exception $e) {
                    return $value;
                }
            })
            ->toArray();
    }
}
