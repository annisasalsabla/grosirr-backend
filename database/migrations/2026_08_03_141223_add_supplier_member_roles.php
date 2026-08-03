<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Modifikasi users table (role, username, email)
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'admin', 'cashier', 'supplier', 'member') NOT NULL DEFAULT 'cashier'");
        
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('email');
            $table->string('email')->nullable()->change();
        });

        // 2. Modifikasi suppliers table
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->unique()->after('id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });

        // 3. Modifikasi customers table
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->unique()->after('id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
        
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'admin', 'cashier') NOT NULL DEFAULT 'cashier'");
    }
};
