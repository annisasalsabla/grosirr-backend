<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class OwnerAccountSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Pemilik Grosir Tiga Bersaudara',
            'email' => 'nisasalsabila@gmail.com',
            'phone' => '081992027569',
            'password' => Hash::make('owner123'),
            'role' => 'owner',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}