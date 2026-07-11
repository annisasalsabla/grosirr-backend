<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use ApiResponseTrait;

    /**
     * Get all settings
     * GET /api/admin/settings
     */
    public function index()
    {
        $settings = Setting::all()->groupBy(function ($setting) {
            if (str_starts_with($setting->key, 'payment_method_')) {
                return 'payment_methods';
            }
            if (str_starts_with($setting->key, 'store_')) {
                return 'store_info';
            }
            return 'other';
        });

        return $this->success($settings, 'Pengaturan berhasil dimuat', 200);
    }

    /**
     * Get payment methods settings only
     * GET /api/admin/settings/payment-methods
     */
    public function paymentMethods()
    {
        $paymentMethods = [
            'cash' => Setting::getBool('payment_method_cash', true),
            'transfer' => Setting::getBool('payment_method_transfer', true),
            'qris' => Setting::getBool('payment_method_qris', true),
            'midtrans_qris' => Setting::getBool('payment_method_midtrans_qris', true),
            'receivable' => Setting::getBool('payment_method_receivable', true),
        ];

        $activeMethods = [];
        $labels = [
            'cash' => 'Tunai',
            'transfer' => 'Transfer Bank',
            'qris' => 'QRIS',
            'midtrans_qris' => 'QRIS Midtrans',
            'receivable' => 'Piutang',
        ];

        foreach ($paymentMethods as $method => $enabled) {
            if ($enabled) {
                $activeMethods[] = [
                    'value' => $method,
                    'label' => $labels[$method] ?? $method,
                ];
            }
        }

        return $this->success([
            'all_methods' => $paymentMethods,
            'active_methods' => $activeMethods,
            'labels' => $labels,
        ], 'Metode pembayaran berhasil dimuat', 200);
    }

    /**
     * Update a setting
     * PUT /api/admin/settings/{key}
     */
    public function update(Request $request, $key)
    {
        $request->validate([
            'value' => 'required',
        ]);

        $setting = Setting::where('key', $key)->first();

        if (!$setting) {
            return $this->error('Pengaturan tidak ditemukan', null, 404);
        }

        $setting->update([
            'value' => $request->value,
        ]);

        return $this->success($setting, 'Pengaturan berhasil diperbarui', 200);
    }

    /**
     * Toggle payment method on/off
     * PUT /api/admin/settings/payment-methods/{method}/toggle
     *
     * Auto-creates the record with default true if it doesn't exist yet,
     * then toggles it — no more 404 on first toggle.
     */
    public function togglePaymentMethod(Request $request, $method)
    {
        $allowedMethods = ['cash', 'transfer', 'qris', 'midtrans_qris', 'receivable'];

        if (!in_array($method, $allowedMethods)) {
            return $this->error('Metode pembayaran tidak dikenali: ' . $method, null, 422);
        }

        $key = 'payment_method_' . $method;

        // Auto-create with default true if record doesn't exist yet
        $currentValue = Setting::getBool($key, true);
        $newValue = !$currentValue;

        Setting::setValue($key, $newValue, 'boolean');

        return $this->success([
            'method' => $method,
            'enabled' => $newValue,
            'message' => $newValue ? 'Metode pembayaran diaktifkan' : 'Metode pembayaran dinonaktifkan',
        ], 'Metode pembayaran berhasil diubah', 200);
    }
}