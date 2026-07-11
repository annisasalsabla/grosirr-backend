<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure 'unpaid' exists in the ENUM used by the app.
        // Current migrations already add 'paid','unpaid','partial' in some order,
        // but if a later migration removed 'unpaid', this restores it.
        DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_status ENUM('pending', 'paid', 'unpaid', 'partial', 'failed') NOT NULL DEFAULT 'unpaid'");
    }

    public function down(): void
    {
        // Rollback to a version without 'unpaid' (keeps failed for safety)
        DB::statement("ALTER TABLE transactions MODIFY COLUMN payment_status ENUM('pending', 'paid', 'partial', 'failed') NOT NULL DEFAULT 'pending'");
    }
};

