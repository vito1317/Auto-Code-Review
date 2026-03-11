<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'value',
        'group',
        'description',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get a setting value by key.
     * Fallback chain: user setting → global setting → default.
     */
    public static function getValue(string $key, mixed $default = null, ?int $userId = null): mixed
    {
        // Try user-specific setting first
        if ($userId !== null) {
            $setting = static::where('key', $key)->where('user_id', $userId)->first();

            if ($setting && $setting->value !== null) {
                return static::decryptValue($setting->value);
            }
        }

        // Fallback to global setting (user_id IS NULL)
        $setting = static::where('key', $key)->whereNull('user_id')->first();

        if (! $setting || $setting->value === null) {
            return $default;
        }

        return static::decryptValue($setting->value);
    }

    /**
     * Set a setting value by key, optionally scoped to a user.
     */
    public static function setValue(string $key, mixed $value, string $group = 'general', ?string $description = null, ?int $userId = null): void
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
            ['key' => $key, 'user_id' => $userId],
            $data,
        );
    }

    /**
     * Get all settings for a group, optionally scoped to a user.
     */
    public static function getGroup(string $group, ?int $userId = null): array
    {
        $query = static::where('group', $group);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            $query->whereNull('user_id');
        }

        return $query
            ->pluck('value', 'key')
            ->map(fn ($value) => static::decryptValue($value))
            ->toArray();
    }

    /**
     * Try to decrypt a value, fall back to raw value.
     */
    private static function decryptValue(mixed $value): mixed
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return $value;
        }
    }
}
