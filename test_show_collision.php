<?php
use App\Models\Customer;
use App\Models\Receivable;
use App\Http\Controllers\Admin\CustomerController;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    echo "[INFO] Membuat 2 entitas ('Budi' A dan 'Budi' B) untuk menguji anti-collision...\n";
    $budi_a = Customer::create(['name' => 'Budi', 'phone' => null, 'member_status' => 'umum', 'is_ambiguous' => true]);
    $budi_b = Customer::create(['name' => 'Budi', 'phone' => '0812345', 'member_status' => 'member', 'is_ambiguous' => false]);
    
    // Injeksi piutang (Receivable) untuk Budi A saja
    Receivable::insert([
        'customer_id' => $budi_a->id,
        'customer_name' => $budi_a->name, // Mensimulasikan data lama yang masih menyimpan nama
        'customer_phone' => null,
        'amount' => 100000,
        'remaining_amount' => 100000,
        'status' => 'unpaid',
        'due_date' => now()->addDays(7),
        'created_at' => now(),
        'updated_at' => now()
    ]);
    
    $controller = new CustomerController(app(\App\Services\SerenityLoggerService::class));
    
    echo "\n--- RAW JSON: BUDI A (Harus punya piutang) ---\n";
    $res_a = $controller->show($budi_a->id);
    $data_a = $res_a->getData(true);
    echo "Jumlah Piutang: " . count($data_a['data']['receivables_history']) . "\n";
    echo "Total Receivable: " . $data_a['data']['total_receivable'] . "\n";
    
    echo "\n--- RAW JSON: BUDI B (Harus KOSONG) ---\n";
    $res_b = $controller->show($budi_b->id);
    $data_b = $res_b->getData(true);
    echo "Jumlah Piutang: " . count($data_b['data']['receivables_history']) . "\n";
    echo "Total Receivable: " . $data_b['data']['total_receivable'] . "\n";

    DB::rollBack();
    echo "\n[INFO] Rollback selesai. Data aman.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage();
}
