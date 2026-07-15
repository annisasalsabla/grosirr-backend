<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        if ($transaction->customer_id) {
            DB::transaction(function () use ($transaction) {
                Customer::evaluateMemberCandidacy($transaction->customer_id);
            });
        }
    }
}
