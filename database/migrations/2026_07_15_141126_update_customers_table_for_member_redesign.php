<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill data 'ditolak' menjadi 'umum'
        DB::table('customers')
            ->where('member_status', 'ditolak')
            ->update(['member_status' => 'umum']);
            
        // 2. Ubah tipe ENUM untuk member_status (hapus 'ditolak')
        // SQLite tidak mendukung ALTER COLUMN ENUM langsung, kita harus menggunakan workaround jika di SQLite.
        // Tapi kita asumsikan untuk MySQL di server asli.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE customers MODIFY member_status ENUM('umum', 'calon_member', 'member') DEFAULT 'umum'");
        }
        
        // 3. Tambahkan kolom is_ambiguous
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'is_ambiguous')) {
                $table->boolean('is_ambiguous')->default(false)->after('member_status');
            }
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE customers MODIFY member_status ENUM('umum', 'calon_member', 'member', 'ditolak') DEFAULT 'umum'");
        }
        
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_ambiguous');
        });
    }
};
