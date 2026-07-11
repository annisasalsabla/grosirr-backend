<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity_sold');
            $table->decimal('profit_amount', 15, 2);
            $table->date('profit_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profits');
    }
};