<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use App\Services\FirebasePushService;
use Illuminate\Http\Request;

class TestPushController extends Controller
{
    protected FirebasePushService $firebasePush;

    public function __construct(FirebasePushService $firebasePush)
    {
        $this->firebasePush = $firebasePush;
    }

    /**
     * Test push notification - bypass anti-duplicate
     */
    public function send(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'message' => 'required|string',
        ]);

        // Get all active admin FCM tokens
        $tokens = FcmToken::where('is_active', true)
            ->whereHas('user', fn($q) => $q->where('role', 'admin'))
            ->pluck('fcm_token')
            ->toArray();

        if (empty($tokens)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada token FCM admin yang aktif',
                'tokens_count' => 0,
            ], 404);
        }

        // Send push via Firebase - return full results for debugging
        $result = $this->firebasePush->sendToMultipleTokens(
            $tokens,
            $request->title,
            $request->message
        );

        return response()->json([
            'all_success' => $result['all_success'],
            'tokens_count' => count($tokens),
            'tokens' => $tokens,
            'results' => $result['results']
        ]);
    }
}