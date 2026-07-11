<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('receivable_payments')) {
            Schema::create('receivable_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
                $table->decimal('amount_paid', 15, 2);
                $table->enum('payment_channel', ['CASH', 'MIDTRANS_QRIS']);
                $table->timestamp('paid_at')->useCurrent();
                $table->string('midtrans_transaction_id')->nullable();
                $table->timestamps();
                
                $table->index('transaction_id');
                $table->index('payment_channel');
                $table->index('paid_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
    }
};