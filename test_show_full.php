<?php
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Receivable;
use App\Http\Controllers\Admin\CustomerController;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    echo "[INFO] Menyiapkan Customer Dummy, 2 Transaksi, 1 Piutang...\n";
    $admin = \App\Models\User::where('role', 'admin')->first();
    
    // 1. Buat Customer
    $c = Customer::create(['name' => 'Charlie', 'phone' => '089999999', 'member_status' => 'umum', 'is_ambiguous' => false]);
    
    // 2. Buat Transaksi 1 (Lunas Cash)
    $trx1 = Transaction::create([
        'invoice_number' => 'TRX-FULL-01',
        'cashier_id' => $admin->id,
        'customer_id' => $c->id,
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'total_amount' => 50000,
        'paid_amount' => 50000,
        'change_due' => 0,
    ]);
    
    // 3. Buat Transaksi 2 (Hutang/Receivable)
    $trx2 = Transaction::create([
        'invoice_number' => 'TRX-FULL-02',
        'cashier_id' => $admin->id,
        'customer_id' => $c->id,
        'payment_method' => 'receivable',
        'payment_status' => 'unpaid',
        'total_amount' => 150000,
        'paid_amount' => 0,
        'change_due' => 0,
    ]);
    
    // 4. Injeksi Piutang untuk Transaksi 2
    Receivable::insert([
        'transaction_id' => $trx2->id,
        'customer_id' => $c->id,
        'customer_name' => $c->name,
        'customer_phone' => $c->phone,
        'customer_address' => '-',
        'total_debt' => 150000,
        'remaining_debt' => 150000,
        'status' => 'unpaid',
        'due_date' => now()->addDays(7),
        'created_at' => now(),
        'updated_at' => now()
    ]);
    
    $controller = new CustomerController(app(\App\Services\SerenityLoggerService::class));
    
    // 5. Panggil show()
    $res = $controller->show($c->id);
    
    echo "\n========== RAW JSON RESPONSE (FULL LENGKAP) ==========\n";
    echo json_encode($res->getData(), JSON_PRETTY_PRINT);
    echo "\n======================================================\n";

    DB::rollBack();
    echo "\n[INFO] Rollback selesai. Data produksi aman.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
