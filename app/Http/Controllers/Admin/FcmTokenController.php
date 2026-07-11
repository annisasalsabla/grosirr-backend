<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FcmTokenController extends Controller
{
    /**
     * POST /api/admin/fcm-token
     * Register/update FCM token for logged in admin
     */
    public function store(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
            'device_type' => 'required|in:android,ios,web',
        ]);

        $user = Auth::user();

        $fcmToken = FcmToken::updateTokenForUser(
            $user->id,
            $request->fcm_token,
            $request->device_type
        );

        return response()->json([
            'message' => 'FCM token registered successfully',
            'data' => $fcmToken,
        ]);
    }

    /**
     * DELETE /api/admin/fcm-token
     * Remove FCM token (logout)
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        FcmToken::where('fcm_token', $request->fcm_token)
            ->where('user_id', Auth::id())
            ->delete();

        return response()->json([
            'message' => 'FCM token removed successfully',
        ]);
    }
}