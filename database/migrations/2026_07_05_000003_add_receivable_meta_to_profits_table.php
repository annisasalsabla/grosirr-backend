<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('profits', function (Blueprint $table) {
            // Must be nullable to avoid breaking existing Profit rows
            $table->boolean('is_from_receivable')->default(false)->after('transaction_id');
            $table->string('receivable_status')->nullable()->after('is_from_receivable');
        });
    }

    public function down(): void
    {
        Schema::table('profits', function (Blueprint $table) {
            $table->dropColumn('receivable_status');
            $table->dropColumn('is_from_receivable');
        });
    }
};

