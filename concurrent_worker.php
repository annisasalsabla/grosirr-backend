<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Models\Receivable;
use Illuminate\Support\Facades\DB;

$custId = $argv[1];
$txValue = $argv[2];
$workerName = $argv[3];
$dummyTxId = $argv[4] ?? 1;

echo "Worker $workerName started for Customer $custId with Tx Value $txValue\n";

DB::beginTransaction();
try {
    echo "Worker $workerName attempting lockForUpdate...\n";
    $c = Customer::where('id', $custId)->lockForUpdate()->first();
    echo "Worker $workerName got lock! Sleeping for 2 seconds...\n";
    sleep(2); // Simulate processing time to allow the other worker to attempt the lock
    
    // SUM with coalescing for safety on empty sets
    $rawSum = $c->receivables()->where(function($q) {
        $q->where('status', '!=', 'paid')->orWhere('remaining_debt', '>', 0);
    })->sum('remaining_debt');
    $totalAktif = (float)($rawSum ?? 0);
    
    if (($totalAktif + $txValue) > $c->credit_limit) {
        throw new Exception("Transaksi ditolak. Piutang aktif (Rp " . number_format($totalAktif, 0, ',', '.') . ") + Transaksi (Rp " . number_format($txValue, 0, ',', '.') . ") > Limit (Rp " . number_format($c->credit_limit, 0, ',', '.') . ")");
    }
    
    Receivable::create([
        'customer_id' => $c->id, 
        'transaction_id' => $dummyTxId, 
        'customer_name' => $c->name, 
        'customer_phone' => $c->phone,
        'customer_address' => $c->address,
        'total_debt' => $txValue, 
        'remaining_debt' => $txValue, 
        'due_date' => now()->addDays(5)
    ]);
    
    DB::commit();
    echo "Worker $workerName: SUCCESS! Transaction approved.\n";
} catch (Exception $e) {
    DB::rollBack();
    echo "Worker $workerName: REJECTED - " . $e->getMessage() . "\n";
}
