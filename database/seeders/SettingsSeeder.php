<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Payment Methods - untuk toggle enable/disable
            [
                'key' => 'payment_method_cash',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Aktifkan metode pembayaran Tunai'
            ],
            [
                'key' => 'payment_method_transfer',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Aktifkan metode pembayaran Transfer Bank'
            ],
            [
                'key' => 'payment_method_qris',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Aktifkan metode pembayaran QRIS statis'
            ],
            [
                'key' => 'payment_method_midtrans_qris',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Aktifkan metode pembayaran QRIS Midtrans'
            ],
            [
                'key' => 'payment_method_receivable',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Aktifkan metode pembayaran Piutang'
            ],

            // Store Info
            [
                'key' => 'store_name',
                'value' => 'Grosir Tiga Bersaudara',
                'type' => 'string',
                'description' => 'Nama toko'
            ],
            [
                'key' => 'store_address',
                'value' => 'Jl. Rimbo Data, Bandar Buat, Padang',
                'type' => 'string',
                'description' => 'Alamat toko'
            ],
            [
                'key' => 'store_phone',
                'value' => '082181769006',
                'type' => 'string',
                'description' => 'Nomor telepon toko'
            ],
        ];

        foreach ($settings as $setting) {
            Setting::create($setting);
        }
    }
}