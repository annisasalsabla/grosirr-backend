<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Cek duplikasi nomor telepon di awal
        $duplicates = DB::table('customers')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone');

        if ($duplicates->isNotEmpty()) {
            throw new \Exception('Migration dibatalkan: nomor HP duplikat terdeteksi pada pelanggan: ' 
                . $duplicates->implode(', '));
        }

        // 2. Modifikasi tabel
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'is_setia')) {
                $table->dropIndex(['is_setia']);
            }
            if (Schema::hasColumn('customers', 'phone')) {
                $table->dropIndex(['phone']);
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->enum('member_status', ['umum', 'calon_member', 'member', 'ditolak'])
                  ->default('umum')
                  ->after('address');
            $table->timestamp('calon_member_since')->nullable()->after('member_status');
            $table->timestamp('member_since')->nullable()->after('calon_member_since');
            $table->text('rejection_note')->nullable()->after('member_since');
        });

        // Migrasi data lama is_setia = true ke member
        if (Schema::hasColumn('customers', 'is_setia')) {
            DB::table('customers')
                ->where('is_setia', true)
                ->update([
                    'member_status' => 'member',
                    'member_since' => now(),
                ]);

            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('is_setia');
            });
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->unique('phone');
            $table->index('member_status');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropIndex(['member_status']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_setia')->default(false)->after('address');
        });

        DB::table('customers')
            ->where('member_status', 'member')
            ->update(['is_setia' => true]);

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['member_status', 'calon_member_since', 'member_since', 'rejection_note']);
            $table->index('is_setia');
            $table->index('phone');
        });
    }
};
