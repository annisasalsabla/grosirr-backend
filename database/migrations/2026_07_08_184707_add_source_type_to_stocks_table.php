<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->enum('source_type', ['pembelian', 'kompensasi_supplier'])->default('pembelian')->after('type');
            $table->foreignId('related_bad_product_id')->nullable()->constrained('bad_products')->nullOnDelete()->after('source_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropForeign(['related_bad_product_id']);
            $table->dropColumn(['source_type', 'related_bad_product_id']);
        });
    }
};
