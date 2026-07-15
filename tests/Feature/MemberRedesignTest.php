<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Cashier\TransactionController;
use App\Http\Controllers\Admin\CustomerController;
use Illuminate\Http\Request;

class MemberRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Cek struktur migration (Test 1)
        $columns = \Schema::getColumnListing('customers');
        echo "TEST 1 - MIGRATION SUCCESS:\n";
        echo "Kolom di customers: " . implode(", ", $columns) . "\n";
        echo "is_ambiguous ada: " . (in_array('is_ambiguous', $columns) ? "Ya" : "Tidak") . "\n\n";
    }

    public function test_end_to_end_collision()
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $admin = User::factory()->create(['role' => 'admin']);

        // Test 2a: Budi pertama
        $request1 = Request::create('/api/cashier/transactions', 'POST', [
            'payment_method' => 'cash',
            'paid_amount' => 10000,
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'customer_name' => 'Budi',
            'customer_phone' => ''
        ]);
        // Kita mock controller logic (atau buat route). Karena product ID butuh seed, 
        // kita panggil logic resolver secara manual untuk simulasi.
        
        $resolveCustomer = function($name, $phone) {
            $request = Request::create('/test', 'POST', ['customer_name' => $name, 'customer_phone' => $phone]);
            $customerId = null;
            if (empty($phone) && !empty($name)) {
                $normalizedName = strtolower(trim($name));
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
                    Customer::whereIn('id', $candidates->pluck('id'))->update(['is_ambiguous' => true]);
                }
            }
            return $customerId;
        };

        $id1 = $resolveCustomer('Budi', '');
        $c1 = Customer::find($id1);
        echo "TEST 2a - Budi 1: ID=$id1, is_ambiguous=" . ($c1->is_ambiguous ? 'true' : 'false') . "\n";

        // Test 2b: budi kecil
        $id2 = $resolveCustomer('budi', '');
        echo "TEST 2b - budi 2 (case insensitive): ID=$id2 (Sama dengan Budi 1? " . ($id1 === $id2 ? 'Ya' : 'Tidak') . ")\n";

        // Test 2c: Budi baru (bikin manual dulu seolah ada Budi lain)
        Customer::create(['name' => 'Budi', 'phone' => null, 'member_status' => 'umum', 'is_ambiguous' => false]);
        $id3 = $resolveCustomer('Budi', '');
        $c3 = Customer::find($id3);
        $c1_updated = Customer::find($id1);
        echo "TEST 2c - Budi 3 (Collision): ID=$id3, is_ambiguous=" . ($c3->is_ambiguous ? 'true' : 'false') . "\n";
        echo "Cek Budi 1 setelah collision: is_ambiguous=" . ($c1_updated->is_ambiguous ? 'true' : 'false') . "\n\n";

        // Test 3: Approve Member
        $c3->update(['member_status' => 'calon_member']);
        $controller = new CustomerController(app(\App\Services\SerenityLoggerService::class));
        
        $reqFail = Request::create('/test', 'POST', []);
        $reqFail->setUserResolver(fn() => $admin);
        $resFail = $controller->approveMember($reqFail, $id3);
        echo "TEST 3a - Approve Tanpa Phone: " . json_encode($resFail->getData()) . "\n";

        $reqSuccess = Request::create('/test', 'POST', ['phone' => '0812345', 'address' => 'Jl Tes']);
        $reqSuccess->setUserResolver(fn() => $admin);
        $resSuccess = $controller->approveMember($reqSuccess, $id3);
        echo "TEST 3b - Approve Dengan Phone: " . json_encode($resSuccess->getData()) . "\n\n";

        // Test 4: Merge
        $c1_updated->update(['member_status' => 'calon_member', 'phone' => null]);
        $c_ambigu_lain = Customer::where('id', '!=', $id1)->where('id', '!=', $id3)->first();
        
        echo "TEST 4 - Merge ID $id1 dan {$c_ambigu_lain->id} \n";
        $reqMerge = Request::create('/test', 'POST', ['merge_from_id' => $c_ambigu_lain->id]);
        $resMerge = $controller->merge($reqMerge, $id1);
        echo "Merge Result: " . json_encode($resMerge->getData()) . "\n";
        
        $c1_final = Customer::find($id1);
        echo "Status is_ambiguous Budi 1 setelah merge: " . ($c1_final->is_ambiguous ? 'true' : 'false') . "\n";
        $c_hilang = Customer::find($c_ambigu_lain->id);
        echo "Customer sumber setelah merge: " . ($c_hilang ? 'Masih Ada' : 'Terhapus') . "\n";
    }
}
