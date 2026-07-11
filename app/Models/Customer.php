<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'address', 'is_setia'
    ];

    protected $casts = [
        'is_setia' => 'boolean',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function getUnpaidTransactions()
    {
        return $this->transactions()
            ->where('payment_method', 'receivable')
            ->where('payment_status', 'unpaid')
            ->get();
    }

    public function getTotalReceivable()
    {
        return $this->transactions()
            ->where('payment_method', 'receivable')
            ->where('payment_status', 'unpaid')
            ->sum('total_amount');
    }
}