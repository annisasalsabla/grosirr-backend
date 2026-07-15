<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return false;
        }
        $result = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        return !empty($result);
    }

    public function up(): void
    {
        // Add date column untuk indexing - transactions
        if (!Schema::hasColumn('transactions', 'tx_date')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->date('tx_date')->nullable()->after('change_due')->index();
            });
            DB::statement('UPDATE transactions SET tx_date = DATE(created_at) WHERE tx_date IS NULL');
            DB::statement('UPDATE transactions SET tx_date = DATE(created_at)');
        }

        // Index untuk payment_method - MySQL 8.0 compatible
        if (!$this->indexExists('transactions', 'idx_transactions_payment_method')) {
            DB::statement('CREATE INDEX idx_transactions_payment_method ON transactions(payment_method)');
        }

        // Index untuk profits - profit_date (ubah ke date aja)
        if (!Schema::hasColumn('profits', 'profit_date_only')) {
            Schema::table('profits', function (Blueprint $table) {
                $table->date('profit_date_only')->nullable()->after('profit_date')->index();
            });
            DB::statement('UPDATE profits SET profit_date_only = DATE(profit_date) WHERE profit_date_only IS NULL');
            DB::statement('UPDATE profits SET profit_date_only = DATE(profit_date)');
        }

        // Index untuk stocks - created_at date
        if (!Schema::hasColumn('stocks', 'stock_date')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->date('stock_date')->nullable()->after('type')->index();
            });
            DB::statement('UPDATE stocks SET stock_date = DATE(created_at) WHERE stock_date IS NULL');
            DB::statement('UPDATE stocks SET stock_date = DATE(created_at)');
        }

        // Index untuk transaction_details - transaction_id - MySQL 8.0 compatible
        if (!$this->indexExists('transaction_details', 'idx_transaction_details_transaction_id')) {
            DB::statement('CREATE INDEX idx_transaction_details_transaction_id ON transaction_details(transaction_id)');
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['tx_date']);
        });

        if ($this->indexExists('transactions', 'idx_transactions_payment_method')) {
            DB::statement('DROP INDEX idx_transactions_payment_method ON transactions');
        }

        Schema::table('profits', function (Blueprint $table) {
            $table->dropColumn(['profit_date_only']);
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['stock_date']);
        });

        if ($this->indexExists('transaction_details', 'idx_transaction_details_transaction_id')) {
            DB::statement('DROP INDEX idx_transaction_details_transaction_id ON transaction_details');
        }
    }
};