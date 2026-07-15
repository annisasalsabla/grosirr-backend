<?php
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Receivable;
use App\Http\Controllers\Admin\CustomerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    echo "[INFO] Menyiapkan Customer Dummy untuk Test Deactivate...\n";
    $admin = \App\Models\User::where('role', 'admin')->first();
    
    // 1. Customer DENGAN piutang aktif
    $c_with_debt = Customer::create(['name' => 'Debt Member', 'phone' => '0811111', 'member_status' => 'member', 'member_since' => now(), 'address' => 'Jalan A']);
    
    $trx = Transaction::create([
        'invoice_number' => 'TRX-DEACT-01',
        'cashier_id' => $admin->id,
        'customer_id' => $c_with_debt->id,
        'payment_method' => 'receivable',
        'payment_status' => 'unpaid',
        'total_amount' => 500000,
        'paid_amount' => 0,
        'change_due' => 0,
    ]);
    
    Receivable::insert([
        'transaction_id' => $trx->id,
        'customer_id' => $c_with_debt->id,
        'customer_name' => $c_with_debt->name,
        'customer_phone' => $c_with_debt->phone,
        'customer_address' => $c_with_debt->address,
        'total_debt' => 500000,
        'remaining_debt' => 500000,
        'status' => 'unpaid',
        'due_date' => now()->addDays(7),
        'created_at' => now(),
        'updated_at' => now()
    ]);

    // 2. Customer TANPA piutang aktif
    $c_no_debt = Customer::create(['name' => 'Clean Member', 'phone' => '0822222', 'member_status' => 'member', 'member_since' => now(), 'address' => 'Jalan B']);
    
    $controller = new CustomerController(app(\App\Services\SerenityLoggerService::class));
    
    // Mock request
    $request = Request::create('/api/admin/customers/deactivate-member', 'POST');
    $request->setUserResolver(function () use ($admin) {
        return $admin;
    });

    echo "\n========== SCENARIO 1: MEMBER DENGAN PIUTANG (Harus Ditolak) ==========\n";
    $res1 = $controller->deactivateMember($request, $c_with_debt->id);
    echo json_encode($res1->getData(), JSON_PRETTY_PRINT) . "\n";
    
    echo "\n========== SCENARIO 2: MEMBER TANPA PIUTANG (Harus Berhasil) ==========\n";
    $res2 = $controller->deactivateMember($request, $c_no_debt->id);
    echo json_encode($res2->getData(), JSON_PRETTY_PRINT) . "\n";
    
    // Verifikasi data
    $c_no_debt->refresh();
    echo "\n[CEK DATABASE SCENARIO 2]\n";
    echo "Member Status: " . $c_no_debt->member_status . "\n";
    echo "Member Since: " . ($c_no_debt->member_since ?: 'null') . "\n";
    echo "Phone (Masih Ada?): " . $c_no_debt->phone . "\n";
    echo "Address (Masih Ada?): " . $c_no_debt->address . "\n";

    DB::rollBack();
    echo "\n[INFO] Rollback selesai. Data produksi aman.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
