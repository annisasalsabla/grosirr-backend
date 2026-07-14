<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->foreignId('customer_id')
                  ->nullable()
                  ->after('transaction_id')
                  ->constrained('customers')
                  ->nullOnDelete();
        });

        // Backfill data customer_id dari tabel transactions yang sesuai
        $transactionsWithCustomer = DB::table('transactions')
            ->whereNotNull('customer_id')
            ->pluck('customer_id', 'id');

        foreach ($transactionsWithCustomer as $transactionId => $customerId) {
            DB::table('receivables')
                ->where('transaction_id', $transactionId)
                ->update(['customer_id' => $customerId]);
        }
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
