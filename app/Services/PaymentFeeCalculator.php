<?php

namespace App\Services;

use App\Models\Setting;

class PaymentFeeCalculator
{
    /**
     * Hitung fee berdasarkan payment channel dan nominal pembayaran
     * 
     * @param string $paymentChannel (cash, transfer, qris_statis, qris_biasa, midtrans_qris)
     * @param float $amount Nominal yang dibayarkan
     * @return array ['percentage' => float, 'amount' => float]
     */
    public static function calculate(string $paymentChannel, float $amount): array
    {
        $channel = strtolower($paymentChannel);
        $percentage = 0.0;

        if (in_array($channel, ['qris_statis', 'qris_biasa', 'qris'])) {
            $percentage = (float) Setting::getValue('qris_fee_percentage', '0.7');
        } elseif ($channel === 'midtrans_qris') {
            $percentage = (float) Setting::getValue('midtrans_fee_percentage', '1.5');
        }

        // Konsistensi dengan TransactionController: TANPA pembulatan round() di level PHP, 
        // kita biarkan kolom MySQL DECIMAL(12,2) yang menanganinya otomatis.
        $feeAmount = $amount * ($percentage / 100);

        return [
            'percentage' => $percentage,
            'amount' => $feeAmount,
        ];
    }
}
