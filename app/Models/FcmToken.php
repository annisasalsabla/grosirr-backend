<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcmToken extends Model
{
    protected $fillable = [
        'user_id',
        'fcm_token',
        'device_type',
        'is_active',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function updateTokenForUser(int $userId, string $fcmToken, string $deviceType): self
    {
        // Remove token from other users (if reassigned to new device/account)
        self::where('fcm_token', $fcmToken)
            ->where('user_id', '!=', $userId)
            ->delete();

        // Update or create token for current user based on device_type
        return self::updateOrCreate(
            [
                'user_id' => $userId,
                'device_type' => $deviceType,
            ],
            [
                'fcm_token' => $fcmToken,
            ]
        );
    }
}