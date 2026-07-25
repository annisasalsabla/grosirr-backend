<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simplifikasi tabel customers: hapus semua kolom terkait
     * sistem auto-detection member lama (calon_member, merge, ambiguous).
     * Setelah migration ini, semua baris di tabel customers = Member sah.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Drop index eksplisit sebelum drop kolom (MySQL requirement)
            $table->dropIndex('customers_member_status_index');

            $table->dropColumn([
                'member_status',
                'is_ambiguous',
                'calon_member_since',
                'member_since',
                'rejection_note',
            ]);
        });
    }

    /**
     * Reverse: kembalikan kolom-kolom lama.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('member_status', ['umum', 'calon_member', 'member'])
                ->default('umum')
                ->after('address')
                ->index();
            $table->boolean('is_ambiguous')->default(false)->after('member_status');
            $table->timestamp('calon_member_since')->nullable()->after('is_ambiguous');
            $table->timestamp('member_since')->nullable()->after('calon_member_since');
            $table->text('rejection_note')->nullable()->after('member_since');
        });
    }
};
