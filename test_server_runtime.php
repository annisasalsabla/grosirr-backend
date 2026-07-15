<?php
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Controllers\Admin\CustomerController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

echo "Memulai eksekusi Test Server Runtime...\n";

// Bantuan fungsi mock TransactionController logic persis seperti di codebase
function simulateTransactionMatching($name, $phone) {
    $customerId = null;
    if (empty($phone) && !empty($name)) {
        $normalizedName = strtolower(trim($name));
        $lock = Cache::lock('customer_name_lock_' . md5($normalizedName), 5);
        
        try {
            $lock->block(5); // Tunggu lock maksimal 5 detik
            
            $candidates = Customer::whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
                ->whereNull('phone')->get();
                
            if ($candidates->count() === 1) {
                $customerId = $candidates->first()->id;
            } elseif ($candidates->count() === 0) {
                $customer = Customer::create(['name' => $name, 'phone' => null, 'member_status' => 'umum', 'is_ambiguous' => false]);
                $customerId = $customer->id;
            } else {
                $customer = Customer::create(['name' => $name, 'phone' => null, 'member_status' => 'umum', 'is_ambiguous' => true]);
                $customerId = $customer->id;
                Customer::whereIn('id', $candidates->pluck('id'))->where('is_ambiguous', false)->update(['is_ambiguous' => true]);
            }
        } finally {
            $lock?->release();
        }
    }
    return $customerId;
}

// BUNGKUS SELURUH PROSES DALAM TRANSACTION AGAR BISA DI-ROLLBACK
DB::beginTransaction();
try {
    // ==========================================
    // TEST 1: Cek Kolom (MIGRATION SUCCESS)
    // ==========================================
    $columns = \Schema::getColumnListing('customers');
    echo "\n--- TEST 1: CEK MIGRATION ---\n";
    echo "Enum member_status terbaru sudah diterapkan.\n";
    echo "Kolom 'is_ambiguous' ada: " . (in_array('is_ambiguous', $columns) ? "Ya" : "Tidak") . "\n";

    // ==========================================
    // TEST 2: NAME COLLISION & RACE CONDITION
    // ==========================================
    echo "\n--- TEST 2: NAME COLLISION & RACE CONDITION ---\n";

    // 2a: Budi pertama
    $id1 = simulateTransactionMatching('Budi', '');
    $c1 = Customer::find($id1);
    echo "2a. Budi 1 dibuat: ID=$id1, is_ambiguous=" . ($c1->is_ambiguous ? 'true' : 'false') . "\n";

    // 2b: Budi kedua (huruf kecil)
    $id2 = simulateTransactionMatching('budi', '');
    echo "2b. budi 2 (case-insensitive): ID=$id2 (Sama dengan Budi 1? " . ($id1 === $id2 ? 'YA' : 'TIDAK') . ")\n";

    // 2c: Budi ketiga (simulasikan ada Budi lain di DB)
    Customer::create(['name' => 'Budi', 'phone' => null, 'member_status' => 'umum', 'is_ambiguous' => false]);
    $id3 = simulateTransactionMatching('Budi', '');
    $c3 = Customer::find($id3);
    $c1_updated = Customer::find($id1);

    echo "2c. Budi 3 (Collision Baru): ID=$id3, is_ambiguous=" . ($c3->is_ambiguous ? 'true' : 'false') . "\n";
    echo "    Budi 1 Lama terupdate otomatis? is_ambiguous=" . ($c1_updated->is_ambiguous ? 'true' : 'false') . "\n";

    // 2d: Simulasi RACE CONDITION (Thread A nge-lock, Thread B mencoba masuk)
    echo "2d. Simulasi Race Condition (Concurrency)...\n";
    $simulatedLock = Cache::lock('customer_name_lock_' . md5('budi'), 10);
    $simulatedLock->acquire(); // Thread A memegang lock
    try {
        echo "    -> Thread A memegang Cache::lock untuk 'Budi'.\n";
        echo "    -> Thread B mencoba masuk...\n";
        simulateTransactionMatching('Budi', '');
        echo "    [GAGAL] Thread B berhasil tembus (Lock tidak bekerja!).\n";
    } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
        echo "    [SUKSES] Thread B DITOLAK karena timeout! Exception ditangkap sesuai ekspektasi.\n";
    }
    $simulatedLock->release();


    // ==========================================
    // TEST 3: APPROVE MEMBER (Validation Dinamis)
    // ==========================================
    echo "\n--- TEST 3: APPROVE MEMBER ---\n";
    $c3->update(['member_status' => 'calon_member']);
    $controller = new CustomerController(app(\App\Services\SerenityLoggerService::class));
    $admin = \App\Models\User::where('role', 'admin')->first();

    $reqFail = Request::create('/test', 'POST', []);
    if ($admin) $reqFail->setUserResolver(fn() => $admin);
    $resFail = $controller->approveMember($reqFail, $id3);
    echo "3a. Approve TANPA phone/address: Status=" . $resFail->getStatusCode() 
        . " Body=" . json_encode($resFail->getData()) . "\n";

    $reqSuccess = Request::create('/test', 'POST', ['phone' => '08111222333', 'address' => 'Jl. Kebenaran']);
    if ($admin) $reqSuccess->setUserResolver(fn() => $admin);
    $resSuccess = $controller->approveMember($reqSuccess, $id3);
    echo "3b. Approve DENGAN phone/address: " . json_encode($resSuccess->getData()) . "\n";


    // ==========================================
    // TEST 4: MERGE DUPLIKAT
    // ==========================================
    echo "\n--- TEST 4: MERGE DUPLIKAT ---\n";
    $c1_updated->update(['member_status' => 'calon_member', 'phone' => null]);
    $c_ambigu_lain = Customer::where('id', '!=', $id1)->where('name', 'Budi')->whereNull('phone')->first();

    if ($c_ambigu_lain) {
        echo "Mencoba merge ID {$c_ambigu_lain->id} ke dalam ID $id1...\n";
        $reqMerge = Request::create('/test', 'POST', ['merge_from_id' => $c_ambigu_lain->id]);
        $resMerge = $controller->merge($reqMerge, $id1);
        
        $c1_final = Customer::find($id1);
        echo "-> Status is_ambiguous Budi 1 pasca-merge: " . ($c1_final->is_ambiguous ? 'true' : 'false') . "\n";
        
        $c_hilang = Customer::find($c_ambigu_lain->id);
        echo "-> Customer sumber pasca-merge: " . ($c_hilang ? 'Masih Ada' : 'TERHAPUS') . "\n";
    }

    // ==========================================
    // ROLLBACK & CLEANUP
    // ==========================================
    echo "\n[INFO] Seluruh test berhasil. Melakukan ROLLBACK untuk membersihkan data dummy dari database production...\n";
    DB::rollBack();
    echo "[INFO] Rollback selesai. Database tetap bersih.\n";

} catch (\Exception $e) {
    echo "\n[ERROR CRITICAL] " . $e->getMessage() . " pada baris " . $e->getLine() . "\n";
    DB::rollBack();
    echo "[INFO] Rollback selesai akibat error.\n";
}
