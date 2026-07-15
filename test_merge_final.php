<?php
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Http\Controllers\Admin\CustomerController;
use Illuminate\Support\Facades\DB;

echo "Memulai eksekusi Test MERGE FINAL (Skenario 1-4)...\n";

DB::beginTransaction();
try {
    $controller = new CustomerController(app(\App\Services\SerenityLoggerService::class));
    $admin = \App\Models\User::where('role', 'admin')->first();
    
    // ==========================================
    // SKENARIO 1: Source = Member -> DITOLAK
    // ==========================================
    echo "\n--- SKENARIO 1: Source = Member ---\n";
    $c1_target = Customer::create(['name' => 'Budi', 'phone' => null, 'member_status' => 'umum']);
    $c1_source = Customer::create(['name' => 'Budi', 'phone' => '08999', 'member_status' => 'member']);
    
    $req1 = Request::create('/test', 'POST', ['merge_from_id' => $c1_source->id]);
    if ($admin) $req1->setUserResolver(fn() => $admin);
    
    $res1 = $controller->merge($req1, $c1_target->id);
    echo "Status: " . $res1->getStatusCode() . "\n";
    echo "Body: " . json_encode($res1->getData()) . "\n";

    // ==========================================
    // SKENARIO 2: Target = Member -> DITOLAK
    // ==========================================
    echo "\n--- SKENARIO 2: Target = Member ---\n";
    $c2_target = Customer::create(['name' => 'Andi', 'phone' => '08123', 'member_status' => 'member']);
    $c2_source = Customer::create(['name' => 'Andi', 'phone' => null, 'member_status' => 'umum']);
    
    $req2 = Request::create('/test', 'POST', ['merge_from_id' => $c2_source->id]);
    if ($admin) $req2->setUserResolver(fn() => $admin);
    
    $res2 = $controller->merge($req2, $c2_target->id);
    echo "Status: " . $res2->getStatusCode() . "\n";
    echo "Body: " . json_encode($res2->getData()) . "\n";

    // ==========================================
    // SKENARIO 3: Nama Beda -> DITOLAK
    // ==========================================
    echo "\n--- SKENARIO 3: Nama Berbeda ---\n";
    $c3_target = Customer::create(['name' => 'Citra', 'phone' => null, 'member_status' => 'umum']);
    $c3_source = Customer::create(['name' => 'Dewi', 'phone' => null, 'member_status' => 'umum']);
    
    $req3 = Request::create('/test', 'POST', ['merge_from_id' => $c3_source->id]);
    if ($admin) $req3->setUserResolver(fn() => $admin);
    
    $res3 = $controller->merge($req3, $c3_target->id);
    echo "Status: " . $res3->getStatusCode() . "\n";
    echo "Body: " . json_encode($res3->getData()) . "\n";

    // ==========================================
    // SKENARIO 4: VALID (Naik Kelas via Gabungan Trx)
    // ==========================================
    echo "\n--- SKENARIO 4: Validasi Naik Pangkat (Calon Member) ---\n";
    $c4_target = Customer::create(['name' => 'Eko', 'phone' => null, 'member_status' => 'umum']);
    $c4_source = Customer::create(['name' => 'Eko', 'phone' => null, 'member_status' => 'umum']);
    
    // Injeksi 6 transaksi ke SOURCE (agar saat merge, target terima >5 trx)
    for($i = 0; $i < 6; $i++) {
        Transaction::insert([
            'invoice_number' => 'TEST-MERGE-' . uniqid(),
            'customer_id' => $c4_source->id,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'change_due' => 0,
            'payment_fee_percentage' => 0,
            'payment_fee_amount' => 0,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
    
    echo "Target SEBELUM merge: Status=" . $c4_target->member_status . "\n";
    
    $req4 = Request::create('/test', 'POST', ['merge_from_id' => $c4_source->id]);
    if ($admin) $req4->setUserResolver(fn() => $admin);
    
    $res4 = $controller->merge($req4, $c4_target->id);
    echo "Merge Status: " . $res4->getStatusCode() . "\n";
    
    // Query ulang dari database
    $c4_target_after = Customer::find($c4_target->id);
    echo "Target SESUDAH merge (diambil ulang dari DB): Status=" . $c4_target_after->member_status . "\n";
    echo "Tanggal Calon Member Since: " . $c4_target_after->calon_member_since . "\n";

    // ==========================================
    // CLEANUP
    // ==========================================
    echo "\n[INFO] Melakukan ROLLBACK untuk membersihkan data uji...\n";
    DB::rollBack();
    echo "[INFO] Rollback selesai.\n";

} catch (\Exception $e) {
    echo "\n[ERROR CRITICAL] " . $e->getMessage() . " pada baris " . $e->getLine() . "\n";
    DB::rollBack();
}
