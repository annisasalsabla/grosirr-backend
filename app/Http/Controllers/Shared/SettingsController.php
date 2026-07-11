<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    /**
     * Get active payment methods
     * GET /api/settings/payment-methods/active
     */
    public function getActivePaymentMethods(): JsonResponse
    {
        $activeMethods = [];
        
        if (Setting::getBool('payment_method_cash', true)) $activeMethods[] = 'cash';
        if (Setting::getBool('payment_method_transfer', true)) $activeMethods[] = 'transfer';
        if (Setting::getBool('payment_method_qris', true)) $activeMethods[] = 'qris';
        if (Setting::getBool('payment_method_midtrans_qris', true)) $activeMethods[] = 'midtrans_qris';
        if (Setting::getBool('payment_method_receivable', true)) $activeMethods[] = 'receivable';

        return response()->json([
            'success' => true,
            'message' => 'Active payment methods retrieved successfully',
            'data' => [
                'active_methods' => $activeMethods
            ]
        ]);
    }
}
