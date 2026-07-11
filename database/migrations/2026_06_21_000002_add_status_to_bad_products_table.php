<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bad_products', function (Blueprint $table) {
            $table->enum('status', ['pending', 'reported'])->default('pending')->after('loss_amount');
            $table->timestamp('reported_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bad_products', function (Blueprint $table) {
            $table->dropColumn(['status', 'reported_at']);
        });
    }
};