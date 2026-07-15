<?php
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Controllers\Admin\CustomerController;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    echo "[INFO] Membuat 2 entitas ambigu ('Zainudin' dengan phone=null)...\n";
    $target = Customer::create(['name' => 'Zainudin', 'phone' => null, 'member_status' => 'umum', 'is_ambiguous' => true]);
    $candidate = Customer::create(['name' => 'Zainudin', 'phone' => null, 'member_status' => 'umum', 'is_ambiguous' => true]);
    
    $controller = new CustomerController(app(\App\Services\SerenityLoggerService::class));
    
    // Panggil endpoint GET /admin/customers/{id}/merge-candidates
    $res = $controller->getMergeCandidates($target->id);
    
    echo "\n--- RAW JSON RESPONSE (getMergeCandidates) ---\n";
    echo json_encode($res->getData(), JSON_PRETTY_PRINT);
    echo "\n----------------------------------------------\n";

    DB::rollBack();
    echo "[INFO] Rollback selesai. Data aman.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage();
}
